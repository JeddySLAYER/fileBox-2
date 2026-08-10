<?php

namespace App\Http\Controllers\Api\Document;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\MoveDocumentRequest;
use App\Http\Requests\Document\SaveDocumentContentRequest;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Requests\Document\StoreDocumentVersionRequest;
use App\Http\Requests\Document\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\VersionResource;
use App\Models\Document;
use App\Models\Version;
use App\Services\Document\DocumentService;
use App\Services\Storage\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly FileStorageService $files,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $documents = $this->documentService->list(
            actor: $request->user(),
            filters: $request->only(['search', 'folder_id', 'project_id', 'status', 'trashed']),
            perPage: (int) $request->integer('per_page', 15),
        );

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $document = $this->documentService->create(
            actor: $request->user(),
            data: $request->safe()->except('file'),
            file: $request->file('file'),
        );

        return response()->json([
            'message' => 'Document créé avec version initiale.',
            'document' => new DocumentResource($document),
        ], 201);
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load([
            'folder',
            'project',
            'department',
            'author',
            'owner',
            'currentVersion.creator',
            'versions.creator',
            'documentType',
            'tags',
            'workflow',
        ])->loadExists([
            'favorites as is_favorited' => fn ($q) => $q->where('user_id', request()->user()->id),
        ]);

        return response()->json([
            'document' => new DocumentResource($document),
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $document = $this->documentService->update($document, $request->validated());

        return response()->json([
            'message' => 'Document mis à jour.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $this->documentService->delete($document);

        return response()->json([
            'message' => 'Document placé dans la corbeille.',
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $document = Document::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $document);

        $document = $this->documentService->restore($document);

        return response()->json([
            'message' => 'Document restauré.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function move(MoveDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $document = $this->documentService->move($document, $request->integer('folder_id'));

        return response()->json([
            'message' => 'Document déplacé.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function archive(Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $document = $this->documentService->archive($document);

        return response()->json([
            'message' => 'Document archivé.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function unarchive(Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $document = $this->documentService->unarchive($document);

        return response()->json([
            'message' => 'Document désarchivé.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function publish(Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $document = $this->documentService->publish($document, request()->user());

        return response()->json([
            'message' => 'Document publié.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function propose(Document $document): JsonResponse
    {
        $this->authorize('propose', $document);

        $document = $this->documentService->propose($document, request()->user());

        return response()->json([
            'message' => 'Document proposé à validation.',
            'document' => new DocumentResource($document),
        ]);
    }

    public function storeVersion(StoreDocumentVersionRequest $request, Document $document): JsonResponse
    {
        $this->authorize('version', $document);

        $document = $this->documentService->createVersion(
            document: $document,
            actor: $request->user(),
            file: $request->file('file'),
            changeSummary: $request->validated('change_summary'),
        );

        return response()->json([
            'message' => 'Nouvelle version créée via upload.',
            'document' => new DocumentResource($document),
        ], 201);
    }

    /**
     * Édition en ligne (is_editable=true uniquement).
     */
    public function saveContent(SaveDocumentContentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('version', $document);

        $document = $this->documentService->saveContent(
            document: $document,
            actor: $request->user(),
            content: $request->validated('content'),
            fileName: $request->validated('file_name'),
            changeSummary: $request->validated('change_summary'),
        );

        return response()->json([
            'message' => 'Contenu sauvegardé (nouvelle version).',
            'document' => new DocumentResource($document),
        ]);
    }

    /**
     * Lecture du contenu pour l'éditeur en ligne (is_editable=true uniquement).
     */
    public function content(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $content = $this->documentService->readContent($document);

        return response()->json([
            'is_editable' => true,
            'content' => $content,
            'file_name' => $document->currentVersion?->file_name,
            'mime_type' => $document->currentVersion?->mime_type,
        ]);
    }

    public function versions(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $versions = $document->versions()->with('creator')->orderByDesc('version_number')->get();

        return VersionResource::collection($versions);
    }

    public function compareVersions(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'left_version_id' => ['required', 'integer', 'exists:versions,id'],
            'right_version_id' => ['required', 'integer', 'exists:versions,id', 'different:left_version_id'],
        ]);

        $left = Version::query()->findOrFail($data['left_version_id']);
        $right = Version::query()->findOrFail($data['right_version_id']);
        $comparison = $this->documentService->compareVersions($document, $left, $right);

        return response()->json([
            'left' => new VersionResource($comparison['left']),
            'right' => new VersionResource($comparison['right']),
            'metadata_diff' => $comparison['metadata_diff'],
            'content_comparable' => $comparison['content_comparable'],
            'content_identical' => $comparison['content_identical'],
            'content_diff' => $comparison['content_diff'],
        ]);
    }

    public function download(Document $document): BinaryFileResponse
    {
        $this->authorize('download', $document);

        $version = $document->currentVersion;

        if (! $version) {
            abort(404, 'Aucune version disponible.');
        }

        return $this->downloadVersionFile($version);
    }

    public function downloadVersion(Document $document, Version $version): BinaryFileResponse
    {
        $this->authorize('download', $document);

        if ($version->document_id !== $document->id) {
            abort(404, 'Version introuvable.');
        }

        return $this->downloadVersionFile($version);
    }

    /**
     * Prévisualisation inline (PDF / images / texte).
     */
    public function preview(Document $document): BinaryFileResponse|JsonResponse
    {
        $this->authorize('view', $document);

        $version = $document->currentVersion;
        if (! $version) {
            abort(404, 'Aucune version disponible.');
        }

        return $this->previewVersionFile($version);
    }

    public function previewVersion(Document $document, Version $version): BinaryFileResponse|JsonResponse
    {
        $this->authorize('view', $document);

        if ($version->document_id !== $document->id) {
            abort(404, 'Version introuvable.');
        }

        return $this->previewVersionFile($version);
    }

    /**
     * URL signée temporaire pour prévisualisation sans Bearer (lien partageable court).
     */
    public function previewUrl(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $minutes = max(1, min(120, (int) $request->integer('expires_minutes', 15)));

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'documents.preview.signed',
            now()->addMinutes($minutes),
            ['document' => $document->id],
        );

        return response()->json([
            'url' => $url,
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        ]);
    }

    /**
     * Prévisualisation via URL signée (middleware signed, hors auth).
     */
    public function signedPreview(Document $document): BinaryFileResponse|JsonResponse
    {
        $version = $document->currentVersion;
        if (! $version) {
            abort(404, 'Aucune version disponible.');
        }

        return $this->previewVersionFile($version);
    }

    private function downloadVersionFile(Version $version): BinaryFileResponse
    {
        if (! $this->files->exists($version->file_path)) {
            abort(404, 'Fichier introuvable sur le stockage.');
        }

        return response()->download(
            $this->files->absolutePath($version->file_path),
            $version->file_name,
        );
    }

    private function previewVersionFile(Version $version): BinaryFileResponse|JsonResponse
    {
        if (! $this->files->exists($version->file_path)) {
            abort(404, 'Fichier introuvable sur le stockage.');
        }

        if (! $this->files->isPreviewable($version->mime_type)) {
            return response()->json([
                'message' => 'Ce type de fichier n\'est pas prévisualisable. Utilisez le téléchargement.',
                'mime_type' => $version->mime_type,
                'previewable' => false,
            ], 422);
        }

        return response()->file(
            $this->files->absolutePath($version->file_path),
            [
                'Content-Type' => $version->mime_type ?? 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($version->file_name).'"',
            ]
        );
    }
}
