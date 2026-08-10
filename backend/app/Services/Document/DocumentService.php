<?php

namespace App\Services\Document;

use App\Enums\ConfidentialityLevel;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Folder;
use App\Models\User;
use App\Models\Version;
use App\Events\Document\DocumentArchived;
use App\Events\Document\DocumentContentSaved;
use App\Events\Document\DocumentCreated;
use App\Events\Document\DocumentDeleted;
use App\Events\Document\DocumentProposed;
use App\Events\Document\DocumentPublished;
use App\Events\Document\DocumentRestored;
use App\Events\Document\DocumentUnarchived;
use App\Events\Document\DocumentVersionCreated;
use App\Services\Access\AccessService;
use App\Services\Storage\FileStorageService;
use App\Support\DocumentEditability;
use App\Support\DocumentWorkflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public function __construct(
        private readonly FileStorageService $files,
        private readonly AccessService $accessService,
    ) {}

    /**
     * @param  array{search?: string, folder_id?: int, project_id?: int, status?: string, trashed?: bool}  $filters
     */
    public function list(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Document::query()
            ->with(['folder', 'author', 'owner', 'currentVersion', 'documentType', 'project', 'workflow'])
            ->withExists([
                'favorites as is_favorited' => fn ($q) => $q->where('user_id', $actor->id),
            ])
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
     * @param  array{title: string, folder_id: int, description?: string|null, project_id?: int|null, department_id?: int|null, document_type_id?: int|null, workflow_id?: int|null, confidentiality?: string, language?: string|null, tag_ids?: array<int>}  $data
     */
    public function create(User $actor, array $data, UploadedFile $file): Document
    {
        return DB::transaction(function () use ($actor, $data, $file) {
            $data = $this->enrichFromFolder($data);
            $documentType = ! empty($data['document_type_id'])
                ? DocumentType::query()->find($data['document_type_id'])
                : null;

            $workflowId = DocumentWorkflow::resolveWorkflowId(
                $data['workflow_id'] ?? null,
                $documentType,
            );

            // Workflow optionnel : perso = jamais ; projet public = suggéré via type, jamais forcé
            if (! DocumentWorkflow::wouldBeProjectPublic($data)) {
                $workflowId = null;
            }

            $document = Document::query()->create([
                'reference' => $this->nextReference(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'folder_id' => $data['folder_id'],
                'project_id' => $data['project_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'document_type_id' => $data['document_type_id'] ?? null,
                'workflow_id' => $workflowId,
                'author_id' => $actor->id,
                'owner_id' => $data['owner_id'] ?? $actor->id,
                'status' => DocumentStatus::Draft,
                'confidentiality' => $data['confidentiality'] ?? ConfidentialityLevel::PublicInternal->value,
                'is_editable' => DocumentEditability::fromUploadedFile($file),
                'language' => $data['language'] ?? null,
            ]);

            $version = $this->storeUploadedVersion($document, $actor, $file, 1, 'Version initiale');

            $document->current_version_id = $version->id;
            $document->save();

            if (! empty($data['tag_ids'])) {
                $document->tags()->sync($data['tag_ids']);
            }

            $loaded = $this->loadDocument($document);

            event(new DocumentCreated($loaded, $actor));

            return $loaded;
        });
    }

    public function propose(Document $document, User $actor): Document
    {
        if (! DocumentWorkflow::subjectToWorkflow($document)) {
            throw ValidationException::withMessages([
                'document' => ['Seuls les documents publics de projet peuvent être proposés à validation.'],
            ]);
        }

        if (! DocumentWorkflow::canPropose($document)) {
            throw ValidationException::withMessages([
                'document' => ['Ce document ne peut pas être proposé à validation dans son état actuel.'],
            ]);
        }

        if (! in_array($actor->id, [$document->author_id, $document->owner_id], true)
            && ! $actor->hasPermission('documents.update')) {
            throw ValidationException::withMessages([
                'document' => ['Vous ne pouvez proposer que vos propres documents.'],
            ]);
        }

        $document->status = DocumentStatus::Proposed;
        $document->save();

        $loaded = $this->loadDocument($document);
        event(new DocumentProposed($loaded, $actor));

        return $loaded;
    }

    /**
     * Mise à jour des métadonnées uniquement (pas le contenu).
     *
     * @param  array{title?: string, description?: string|null, folder_id?: int, project_id?: int|null, department_id?: int|null, document_type_id?: int|null, workflow_id?: int|null, owner_id?: int, confidentiality?: string, language?: string|null, tag_ids?: array<int>}  $data
     */
    public function update(Document $document, array $data): Document
    {
        $this->assertModifiable($document);

        return DB::transaction(function () use ($document, $data) {
            // is_editable dérivé de l'extension du fichier courant — pas modifiable à la main
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
            $this->lockCurrentVersion($document);

            $nextNumber = (int) $document->versions()->max('version_number') + 1;
            $version = $this->storeUploadedVersion(
                $document,
                $actor,
                $file,
                $nextNumber,
                $changeSummary ?? "Version {$nextNumber}",
            );

            $document->current_version_id = $version->id;
            $document->is_editable = DocumentEditability::fromExtension($version->extension);
            $document->save();

            $loaded = $this->loadDocument($document, withVersions: true);

            event(new DocumentVersionCreated($loaded, $actor, $nextNumber));

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
            $this->lockCurrentVersion($document);

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
                'is_locked' => false,
            ]);

            $document->current_version_id = $version->id;
            $document->save();

            $loaded = $this->loadDocument($document, withVersions: true);

            event(new DocumentContentSaved($loaded, $actor, $nextNumber));

            return $loaded;
        });
    }

    /**
     * SCN-VER-003 : comparaison de deux versions (métadonnées + diff contenu texte si applicable).
     *
     * @return array{
     *   left: Version,
     *   right: Version,
     *   metadata_diff: array<string, array{left: mixed, right: mixed}>,
     *   content_comparable: bool,
     *   content_identical: bool|null,
     *   content_diff: list<array{type: string, text: string}>|null
     * }
     */
    public function compareVersions(Document $document, Version $left, Version $right): array
    {
        if ($left->document_id !== $document->id || $right->document_id !== $document->id) {
            throw ValidationException::withMessages([
                'versions' => ['Les deux versions doivent appartenir à ce document.'],
            ]);
        }

        if ($left->id === $right->id) {
            throw ValidationException::withMessages([
                'versions' => ['Sélectionnez deux versions distinctes.'],
            ]);
        }

        $left->loadMissing('creator');
        $right->loadMissing('creator');

        $fields = ['file_name', 'mime_type', 'extension', 'size', 'checksum', 'change_summary'];
        $metadataDiff = [];

        foreach ($fields as $field) {
            $a = $left->{$field};
            $b = $right->{$field};
            if ($a != $b) {
                $metadataDiff[$field] = ['left' => $a, 'right' => $b];
            }
        }

        if ($left->created_by !== $right->created_by) {
            $metadataDiff['creator'] = [
                'left' => $left->creator?->name,
                'right' => $right->creator?->name,
            ];
        }

        if ($left->created_at?->toIso8601String() !== $right->created_at?->toIso8601String()) {
            $metadataDiff['created_at'] = [
                'left' => $left->created_at?->toIso8601String(),
                'right' => $right->created_at?->toIso8601String(),
            ];
        }

        $comparable = $this->isTextComparable($left->mime_type) && $this->isTextComparable($right->mime_type);
        $contentIdentical = null;
        $contentDiff = null;

        if ($comparable) {
            $leftContent = $this->files->get($left->file_path) ?? '';
            $rightContent = $this->files->get($right->file_path) ?? '';
            $contentIdentical = $leftContent === $rightContent;
            $contentDiff = $contentIdentical ? [] : $this->lineDiff($leftContent, $rightContent);
        }

        return [
            'left' => $left,
            'right' => $right,
            'metadata_diff' => $metadataDiff,
            'content_comparable' => $comparable,
            'content_identical' => $contentIdentical,
            'content_diff' => $contentDiff,
        ];
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
        $document->archived_at = Carbon::now();
        $document->save();

        event(new DocumentArchived($document));

        return $document->load(['folder', 'author', 'owner', 'currentVersion']);
    }

    public function publish(Document $document, ?User $actor = null): Document
    {
        if ($document->status === DocumentStatus::Published) {
            throw ValidationException::withMessages([
                'document' => ['Ce document est déjà publié.'],
            ]);
        }

        if ($document->status !== DocumentStatus::Validated) {
            throw ValidationException::withMessages([
                'document' => ['Seul un document validé peut être publié.'],
            ]);
        }

        $document->status = DocumentStatus::Published;
        $document->save();

        event(new DocumentPublished($document, $actor));

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

        event(new DocumentUnarchived($document));

        return $document->load(['folder', 'author', 'owner', 'currentVersion']);
    }

    public function delete(Document $document): void
    {
        $reference = $document->reference;
        $document->status = DocumentStatus::Deleted;
        $document->save();
        $document->delete();

        event(new DocumentDeleted($document, $reference));
    }

    public function restore(Document $document): Document
    {
        $document->restore();
        $document->status = DocumentStatus::Draft;
        $document->save();

        event(new DocumentRestored($document));

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
            'is_locked' => false,
        ]);
    }

    private function lockCurrentVersion(Document $document): void
    {
        $current = $document->currentVersion;
        if (! $current || $current->is_locked) {
            return;
        }

        $current->is_locked = true;
        $current->save();
    }

    private function isTextComparable(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, ['application/json', 'application/xml', 'application/javascript'], true);
    }

    /**
     * Diff ligne à ligne minimal (LCS).
     * ponytail: O(n*m) ok pour docs texte courts; upgrade: Myers / external diff lib
     *
     * @return list<array{type: 'equal'|'add'|'remove', text: string}>
     */
    private function lineDiff(string $left, string $right): array
    {
        $a = preg_split("/\r\n|\n|\r/", $left) ?: [];
        $b = preg_split("/\r\n|\n|\r/", $right) ?: [];
        $n = count($a);
        $m = count($b);

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => 'equal', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $out[] = ['type' => 'remove', 'text' => $a[$i]];
                $i++;
            } else {
                $out[] = ['type' => 'add', 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $out[] = ['type' => 'remove', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['type' => 'add', 'text' => $b[$j++]];
        }

        return $out;
    }

    private function loadDocument(Document $document, bool $withVersions = false): Document
    {
        $relations = [
            'folder',
            'author',
            'owner',
            'currentVersion',
            'documentType',
            'workflow',
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

        if ($document->status === DocumentStatus::InValidation) {
            throw ValidationException::withMessages([
                'document' => ['Un document en validation ne peut plus être modifié.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function enrichFromFolder(array $data): array
    {
        if (empty($data['folder_id'])) {
            return $data;
        }

        $folder = Folder::query()->find($data['folder_id']);
        if (! $folder) {
            return $data;
        }

        $data['project_id'] ??= $folder->project_id;
        $data['department_id'] ??= $folder->department_id;

        return $data;
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
