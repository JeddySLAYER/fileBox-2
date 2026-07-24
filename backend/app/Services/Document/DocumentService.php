<?php

namespace App\Services\Document;

use App\Enums\ConfidentialityLevel;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\Version;
use App\Services\Access\AccessService;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Storage\FileStorageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function __construct(
        private readonly FileStorageService $files,
        private readonly AccessService $accessService,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @param  array{search?: string, folder_id?: int, project_id?: int, status?: string, trashed?: bool}  $filters
     */
    public function list(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Document::query()
            ->with(['folder', 'author', 'owner', 'currentVersion', 'documentType'])
            ->latest();

        if (! empty($filters['trashed'])) {
            $query->onlyTrashed();
        }

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(reference) LIKE ?', ["%{$search}%"]);
            });
        }

        if (! empty($filters['folder_id'])) {
            $query->where('folder_id', $filters['folder_id']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! $actor->hasPermission('documents.view')) {
            $ids = $this->accessService->accessibleDocumentIds($actor);
            $query->whereIn('id', $ids ?: [0]);
        }

        return $query->paginate($perPage);
    }

    /**
     * RG-DOC-001..004 : création avec version initiale obligatoire.
     *
     * @param  array{title: string, folder_id: int, description?: string|null, project_id?: int|null, department_id?: int|null, document_type_id?: int|null, workflow_id?: int|null, confidentiality?: string, is_editable?: bool, language?: string|null, tag_ids?: array<int>}  $data
     */
    public function create(User $actor, array $data, UploadedFile $file): Document
    {
        return DB::transaction(function () use ($actor, $data, $file) {
            $document = Document::query()->create([
                'reference' => $this->nextReference(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'folder_id' => $data['folder_id'],
                'project_id' => $data['project_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'document_type_id' => $data['document_type_id'] ?? null,
                'workflow_id' => $data['workflow_id'] ?? null,
                'author_id' => $actor->id,
                'owner_id' => $data['owner_id'] ?? $actor->id,
                'status' => DocumentStatus::Draft,
                'confidentiality' => $data['confidentiality'] ?? ConfidentialityLevel::PublicInternal->value,
                'is_editable' => $data['is_editable'] ?? false,
                'language' => $data['language'] ?? null,
            ]);

            $version = $this->storeUploadedVersion($document, $actor, $file, 1, 'Version initiale');

            $document->current_version_id = $version->id;
            $document->save();

            if (! empty($data['tag_ids'])) {
                $document->tags()->sync($data['tag_ids']);
            }

            $loaded = $this->loadDocument($document);

            $this->activityLog->log(
                action: 'document.created',
                user: $actor,
                subject: $loaded,
                description: "Document créé : {$loaded->reference}",
            );

            return $loaded;
        });
    }

    /**
     * Mise à jour des métadonnées uniquement (pas le contenu).
     *
     * @param  array{title?: string, description?: string|null, folder_id?: int, project_id?: int|null, department_id?: int|null, document_type_id?: int|null, workflow_id?: int|null, owner_id?: int, confidentiality?: string, is_editable?: bool, language?: string|null, tag_ids?: array<int>}  $data
     */
    public function update(Document $document, array $data): Document
    {
        $this->assertModifiable($document);

        return DB::transaction(function () use ($document, $data) {
            $document->fill(collect($data)->only([
                'title',
                'description',
                'folder_id',
                'project_id',
                'department_id',
                'document_type_id',
                'workflow_id',
                'owner_id',
                'confidentiality',
                'is_editable',
                'language',
            ])->all());
            $document->save();

            if (array_key_exists('tag_ids', $data)) {
                $document->tags()->sync($data['tag_ids'] ?? []);
            }

            return $this->loadDocument($document);
        });
    }

    /**
     * Réupload : obligatoire si is_editable=false, aussi autorisé si is_editable=true.
     */
    public function createVersion(
        Document $document,
        User $actor,
        UploadedFile $file,
        ?string $changeSummary = null,
    ): Document {
        $this->assertModifiable($document);

        return DB::transaction(function () use ($document, $actor, $file, $changeSummary) {
            $nextNumber = (int) $document->versions()->max('version_number') + 1;
            $version = $this->storeUploadedVersion(
                $document,
                $actor,
                $file,
                $nextNumber,
                $changeSummary ?? "Version {$nextNumber}",
            );

            $document->current_version_id = $version->id;
            $document->save();

            $loaded = $this->loadDocument($document, withVersions: true);

            $this->activityLog->log(
                action: 'document.version_created',
                user: $actor,
                subject: $loaded,
                description: "Nouvelle version #{$nextNumber} : {$loaded->reference}",
            );

            return $loaded;
        });
    }

    /**
     * Édition en ligne : uniquement si is_editable=true.
     * Si is_editable=false → réupload obligatoire via createVersion.
     */
    public function saveContent(
        Document $document,
        User $actor,
        string $content,
        ?string $fileName = null,
        ?string $changeSummary = null,
    ): Document {
        $this->assertModifiable($document);

        if (! $document->is_editable) {
            throw ValidationException::withMessages([
                'is_editable' => [
                    'Ce document n\'est pas éditable en ligne. Téléchargez-le, modifiez-le localement, puis réuploadez une nouvelle version.',
                ],
            ]);
        }

        return DB::transaction(function () use ($document, $actor, $content, $fileName, $changeSummary) {
            $nextNumber = (int) $document->versions()->max('version_number') + 1;
            $current = $document->currentVersion;
            $name = $fileName
                ?? $current?->file_name
                ?? 'content.txt';
            $mime = $current?->mime_type ?? 'text/plain';

            $stored = $this->files->storeDocumentContent(
                content: $content,
                documentId: $document->id,
                versionNumber: $nextNumber,
                fileName: $name,
                mimeType: $mime,
            );

            $version = Version::query()->create([
                'document_id' => $document->id,
                'version_number' => $nextNumber,
                'file_path' => $stored['file_path'],
                'file_name' => $stored['file_name'],
                'mime_type' => $stored['mime_type'],
                'extension' => $stored['extension'],
                'size' => $stored['size'],
                'checksum' => $stored['checksum'],
                'created_by' => $actor->id,
                'change_summary' => $changeSummary ?? "Édition en ligne — version {$nextNumber}",
            ]);

            $document->current_version_id = $version->id;
            $document->save();

            $loaded = $this->loadDocument($document, withVersions: true);

            $this->activityLog->log(
                action: 'document.content_saved',
                user: $actor,
                subject: $loaded,
                description: "Contenu édité en ligne : {$loaded->reference} (v{$nextNumber})",
            );

            return $loaded;
        });
    }

    public function readContent(Document $document): string
    {
        $version = $document->currentVersion;

        if (! $version || ! $this->files->exists($version->file_path)) {
            throw ValidationException::withMessages([
                'document' => ['Aucun contenu disponible pour ce document.'],
            ]);
        }

        if (! $document->is_editable) {
            throw ValidationException::withMessages([
                'is_editable' => [
                    'Ce document n\'est pas éditable en ligne. Utilisez le téléchargement.',
                ],
            ]);
        }

        return $this->files->get($version->file_path) ?? '';
    }

    public function move(Document $document, int $folderId): Document
    {
        $this->assertModifiable($document);

        $document->folder_id = $folderId;
        $document->save();

        return $document->load(['folder', 'author', 'owner', 'currentVersion']);
    }

    public function archive(Document $document): Document
    {
        if ($document->status === DocumentStatus::Archived) {
            throw ValidationException::withMessages([
                'document' => ['Ce document est déjà archivé.'],
            ]);
        }

        $document->status = DocumentStatus::Archived;
        $document->archived_at = now();
        $document->save();

        $this->activityLog->log(
            action: 'document.archived',
            subject: $document,
            description: "Document archivé : {$document->reference}",
        );

        return $document->load(['folder', 'author', 'owner', 'currentVersion']);
    }

    public function unarchive(Document $document): Document
    {
        if ($document->status !== DocumentStatus::Archived) {
            throw ValidationException::withMessages([
                'document' => ['Ce document n\'est pas archivé.'],
            ]);
        }

        $document->status = DocumentStatus::Draft;
        $document->archived_at = null;
        $document->save();

        $this->activityLog->log(
            action: 'document.unarchived',
            subject: $document,
            description: "Document désarchivé : {$document->reference}",
        );

        return $document->load(['folder', 'author', 'owner', 'currentVersion']);
    }

    public function delete(Document $document): void
    {
        $reference = $document->reference;
        $document->status = DocumentStatus::Deleted;
        $document->save();
        $document->delete();

        $this->activityLog->log(
            action: 'document.deleted',
            subject: $document,
            description: "Document mis en corbeille : {$reference}",
        );
    }

    public function restore(Document $document): Document
    {
        $document->restore();
        $document->status = DocumentStatus::Draft;
        $document->save();

        $this->activityLog->log(
            action: 'document.restored',
            subject: $document,
            description: "Document restauré : {$document->reference}",
        );

        return $document->load(['folder', 'author', 'owner', 'currentVersion']);
    }

    private function storeUploadedVersion(
        Document $document,
        User $actor,
        UploadedFile $file,
        int $versionNumber,
        string $changeSummary,
    ): Version {
        $stored = $this->files->storeDocumentFile($file, $document->id, $versionNumber);

        return Version::query()->create([
            'document_id' => $document->id,
            'version_number' => $versionNumber,
            'file_path' => $stored['file_path'],
            'file_name' => $stored['file_name'],
            'mime_type' => $stored['mime_type'],
            'extension' => $stored['extension'],
            'size' => $stored['size'],
            'checksum' => $stored['checksum'],
            'created_by' => $actor->id,
            'change_summary' => $changeSummary,
        ]);
    }

    private function loadDocument(Document $document, bool $withVersions = false): Document
    {
        $relations = [
            'folder',
            'author',
            'owner',
            'currentVersion',
            'documentType',
            'tags',
        ];

        if ($withVersions) {
            $relations[] = 'versions';
        }

        return $document->load($relations);
    }

    private function assertModifiable(Document $document): void
    {
        if ($document->status === DocumentStatus::Archived) {
            throw ValidationException::withMessages([
                'document' => ['Un document archivé ne peut plus être modifié.'],
            ]);
        }
    }

    private function nextReference(): string
    {
        $year = now()->year;
        $prefix = "DOC-{$year}-";

        $last = Document::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, -6)) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
