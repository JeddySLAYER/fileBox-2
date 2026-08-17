<?php

namespace App\Services\Access;

use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Visibilité GED : un utilisateur ne voit que ses espaces
 * (département, projets dont il est membre, dossiers personnels, partages ACL).
 * Admin et direction voient les espaces organisationnels (département / projet),
 * mais pas les espaces privés d’autrui (sauf partage explicite).
 */
class SpaceVisibility
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {}

    public function canSeeAllSpaces(User $user): bool
    {
        return $user->hasRole('administrateur') || $user->hasRole('direction');
    }

    /** Espace privé = hors projet et hors dossier public de département. */
    public function isPersonalFolder(Folder $folder): bool
    {
        return $folder->project_id === null && $folder->department_id === null;
    }

    public function isPersonalDocument(Document $document): bool
    {
        if ($document->project_id !== null || $document->department_id !== null) {
            return false;
        }

        if ($document->folder_id) {
            $document->loadMissing('folder');
            if ($document->folder && ! $this->isPersonalFolder($document->folder)) {
                return false;
            }
        }

        return true;
    }

    public function canViewFolder(User $user, Folder $folder): bool
    {
        if ($this->isPersonalFolder($folder)) {
            return $this->canViewPersonalFolder($user, $folder);
        }

        if ($this->canSeeAllSpaces($user)) {
            return true;
        }

        if ($this->accessService->userCan($user, $folder, 'view')) {
            return true;
        }

        if ((int) $folder->created_by === (int) $user->id) {
            return true;
        }

        if ($folder->project_id) {
            $folder->loadMissing('project');

            return $folder->project?->isParticipant($user) ?? false;
        }

        if ($folder->department_id && $this->belongsToDepartment($user, (int) $folder->department_id)) {
            return true;
        }

        return false;
    }

    public function canViewDocument(User $user, Document $document): bool
    {
        if ($this->isPersonalDocument($document)) {
            return $this->canViewPersonalDocument($user, $document);
        }

        if ($this->canSeeAllSpaces($user)) {
            return true;
        }

        if ((int) $document->author_id === (int) $user->id
            || (int) $document->owner_id === (int) $user->id) {
            return true;
        }

        if ($this->accessService->userCan($user, $document, 'view')) {
            return true;
        }

        if ($document->folder_id) {
            $document->loadMissing('folder');
            if ($document->folder && $this->canViewFolder($user, $document->folder)) {
                return true;
            }
        }

        if ($document->project_id) {
            $document->loadMissing('project');

            return $document->project?->isParticipant($user) ?? false;
        }

        return false;
    }

    public function applyFolderScope(Builder|Relation $query, User $user): void
    {
        $aclIds = $this->accessService->accessibleFolderIds($user);

        if ($this->canSeeAllSpaces($user)) {
            // Org (département / projet) + ses dossiers privés + partages privés.
            $query->where(function (Builder $q) use ($user, $aclIds) {
                $q->where(function (Builder $org) {
                    $org->whereNotNull('project_id')
                        ->orWhereNotNull('department_id');
                })->orWhere('created_by', $user->id);

                if ($aclIds !== []) {
                    $q->orWhereIn('id', $aclIds);
                }
            });

            return;
        }

        $projectIds = $this->projectIds($user);
        $departmentIds = $this->departmentIds($user);

        $query->where(function (Builder $q) use ($user, $projectIds, $departmentIds, $aclIds) {
            $q->where('created_by', $user->id);

            if ($aclIds !== []) {
                $q->orWhereIn('id', $aclIds);
            }

            if ($projectIds !== []) {
                $q->orWhereIn('project_id', $projectIds);
            }

            if ($departmentIds !== []) {
                $q->orWhere(function (Builder $dept) use ($departmentIds) {
                    $dept->whereIn('department_id', $departmentIds)
                        ->whereNull('project_id');
                });
            }
        });
    }

    public function applyDocumentScope(Builder|Relation $query, User $user): void
    {
        $aclDocIds = $this->accessService->accessibleDocumentIds($user);

        if ($this->canSeeAllSpaces($user)) {
            $query->where(function (Builder $q) use ($user, $aclDocIds) {
                $q->where(function (Builder $org) {
                    $org->whereNotNull('project_id')
                        ->orWhereNotNull('department_id');
                })->orWhere('author_id', $user->id)
                    ->orWhere('owner_id', $user->id);

                if ($aclDocIds !== []) {
                    $q->orWhereIn('id', $aclDocIds);
                }

                $q->orWhereHas('folder', function (Builder $folderQuery) use ($user) {
                    $folderQuery->withTrashed();
                    $this->applyFolderScope($folderQuery, $user);
                });
            });

            return;
        }

        $query->where(function (Builder $q) use ($user, $aclDocIds) {
            $q->where('author_id', $user->id)
                ->orWhere('owner_id', $user->id);

            if ($aclDocIds !== []) {
                $q->orWhereIn('id', $aclDocIds);
            }

            $q->orWhereHas('folder', function (Builder $folderQuery) use ($user) {
                $folderQuery->withTrashed();
                $this->applyFolderScope($folderQuery, $user);
            });
        });
    }

    /** @return list<int> */
    public function visibleFolderIds(User $user): array
    {
        $query = Folder::query();
        $this->applyFolderScope($query, $user);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function restrictToExplorerRoots(Builder|Relation $query, User $user): void
    {
        $query->where('is_project_root', false);

        if ($this->canSeeAllSpaces($user)) {
            $query->whereNull('parent_id')
                ->where(function (Builder $q) use ($user) {
                    $q->where(function (Builder $org) {
                        $org->whereNotNull('project_id')
                            ->orWhereNotNull('department_id');
                    })->orWhere('created_by', $user->id);

                    $aclIds = $this->accessService->accessibleFolderIds($user);
                    if ($aclIds !== []) {
                        $q->orWhereIn('id', $aclIds);
                    }
                });

            return;
        }

        $visibleIds = $this->visibleFolderIds($user);
        $query->where(function (Builder $q) use ($visibleIds) {
            $q->whereNull('parent_id');
            if ($visibleIds === []) {
                $q->orWhereNotNull('parent_id');
            } else {
                $q->orWhereNotIn('parent_id', $visibleIds);
            }
        });
    }

    /**
     * Documents à la racine explorateur : sans dossier, ou dans un dossier non navigable
     * (partage ACL d’un fichier dont le parent n’est pas visible).
     */
    public function restrictToExplorerRootDocuments(Builder|Relation $query, User $user): void
    {
        if ($this->canSeeAllSpaces($user)) {
            // Les docs privés d’autrui sont exclus par applyDocumentScope.
            $query->whereNull('folder_id');

            return;
        }

        $visibleFolderIds = $this->visibleFolderIds($user);
        $query->where(function (Builder $q) use ($visibleFolderIds) {
            $q->whereNull('folder_id');
            if ($visibleFolderIds === []) {
                $q->orWhereNotNull('folder_id');
            } else {
                $q->orWhereNotIn('folder_id', $visibleFolderIds);
            }
        });
    }

    /** @return list<int> */
    public function projectIds(User $user): array
    {
        $memberIds = $user->projects()->pluck('projects.id')->all();
        $managedIds = $user->managedProjects()->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_merge($memberIds, $managedIds))));
    }

    /** @return list<int> */
    public function departmentIds(User $user): array
    {
        $ids = [];
        if ($user->department_id) {
            $ids[] = (int) $user->department_id;
        }

        $ids = array_merge($ids, $user->managedDepartments()->pluck('id')->all());

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function belongsToDepartment(User $user, int $departmentId): bool
    {
        return in_array($departmentId, $this->departmentIds($user), true);
    }

    private function canViewPersonalFolder(User $user, Folder $folder): bool
    {
        if ((int) $folder->created_by === (int) $user->id) {
            return true;
        }

        return $this->accessService->userCan($user, $folder, 'view');
    }

    private function canViewPersonalDocument(User $user, Document $document): bool
    {
        if ((int) $document->author_id === (int) $user->id
            || (int) $document->owner_id === (int) $user->id) {
            return true;
        }

        if ($this->accessService->userCan($user, $document, 'view')) {
            return true;
        }

        if ($document->folder_id) {
            $document->loadMissing('folder');
            if ($document->folder && $this->isPersonalFolder($document->folder)) {
                return $this->canViewPersonalFolder($user, $document->folder);
            }
        }

        return false;
    }
}
