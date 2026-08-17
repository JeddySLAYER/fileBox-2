<?php

namespace App\Services\Trash;

use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\SpaceVisibility;
use App\Services\Document\DocumentService;
use App\Services\Folder\FolderService;

class TrashService
{
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly SpaceVisibility $spaceVisibility,
        private readonly DocumentService $documentService,
        private readonly FolderService $folderService,
    ) {}

    /**
     * Vide la corbeille visible par l'utilisateur (suppression définitive).
     *
     * @return array{documents: int, folders: int}
     */
    public function emptyFor(User $user): array
    {
        $documents = 0;
        $folders = 0;

        $this->trashedDocumentsQuery($user)
            ->get()
            ->each(function (Document $document) use ($user, &$documents) {
                if ($user->can('forceDelete', $document)) {
                    $this->documentService->forceDelete($document);
                    $documents++;
                }
            });

        $this->trashedFoldersQuery($user)
            ->get()
            ->each(function (Folder $folder) use ($user, &$folders) {
                $fresh = Folder::onlyTrashed()->find($folder->id);
                if ($fresh && $user->can('forceDelete', $fresh)) {
                    $this->folderService->forceDelete($fresh);
                    $folders++;
                }
            });

        return [
            'documents' => $documents,
            'folders' => $folders,
        ];
    }

    /**
     * Purge les éléments en corbeille depuis plus de 30 jours.
     *
     * @return array{documents: int, folders: int}
     */
    public function purgeExpired(?int $days = null): array
    {
        $days ??= self::RETENTION_DAYS;
        $cutoff = now()->subDays($days);
        $documents = 0;
        $folders = 0;

        Document::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get()
            ->each(function (Document $document) use (&$documents) {
                $this->documentService->forceDelete($document);
                $documents++;
            });

        Folder::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get()
            ->each(function (Folder $folder) use (&$folders) {
                $fresh = Folder::onlyTrashed()->find($folder->id);
                if ($fresh) {
                    $this->folderService->forceDelete($fresh);
                    $folders++;
                }
            });

        return [
            'documents' => $documents,
            'folders' => $folders,
        ];
    }

    private function trashedDocumentsQuery(User $user)
    {
        $query = Document::onlyTrashed();
        $this->spaceVisibility->applyDocumentScope($query, $user);

        return $query;
    }

    private function trashedFoldersQuery(User $user)
    {
        $query = Folder::onlyTrashed();
        $this->spaceVisibility->applyFolderScope($query, $user);

        return $query;
    }
}
