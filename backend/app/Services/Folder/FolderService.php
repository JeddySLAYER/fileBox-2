<?php

namespace App\Services\Folder;

use App\Enums\DocumentStatus;
use App\Events\Document\DocumentDeleted;
use App\Events\Folder\FolderCreated;
use App\Events\Folder\FolderDeleted;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\SpaceVisibility;
use App\Services\Document\DocumentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FolderService
{
    public function __construct(
        private readonly SpaceVisibility $spaceVisibility,
        private readonly DocumentService $documentService,
    ) {}

    /**
     * @param  array{parent_id?: int|null, project_id?: int|null, department_id?: int|null, trashed?: bool, project_roots?: bool}  $filters
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $query = Folder::query()
            ->with(['creator', 'project', 'department', 'tags'])
            ->withCount([
                'children',
                'documents' => fn ($q) => $q->where('status', '!=', DocumentStatus::Archived),
            ])
            ->orderBy('name');

        if (! empty($filters['trashed'])) {
            $query->onlyTrashed();
        }

        $this->spaceVisibility->applyFolderScope($query, $actor);

        if (! empty($filters['project_roots'])) {
            $query->where('is_project_root', true);
        } elseif (array_key_exists('parent_id', $filters)) {
            $query->where('parent_id', $filters['parent_id']);
        } elseif (empty($filters['trashed'])) {
            $this->spaceVisibility->restrictToExplorerRoots($query, $actor);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        return $query
            ->withExists([
                'favorites as is_favorited' => fn ($q) => $q->where('user_id', $actor->id),
            ])
            ->get();
    }

    public function tree(User $actor, ?int $projectId = null, ?int $departmentId = null): Collection
    {
        $constrain = function ($q) use ($actor): void {
            $this->spaceVisibility->applyFolderScope($q, $actor);
            $q->orderBy('name');
        };

        $query = Folder::query()
            ->with([
                'children' => function ($q) use ($constrain) {
                    $constrain($q);
                    $q->with([
                        'children' => function ($q2) use ($constrain) {
                            $constrain($q2);
                            $q2->with([
                                'children' => function ($q3) use ($constrain) {
                                    $constrain($q3);
                                },
                            ]);
                        },
                    ]);
                },
            ])
            ->withCount([
                'children',
                'documents' => fn ($q) => $q->where('status', '!=', DocumentStatus::Archived),
            ])
            ->orderBy('name');

        $this->spaceVisibility->applyFolderScope($query, $actor);

        if ($projectId) {
            $query->where('project_id', $projectId)
                ->where(function ($q) {
                    $q->where('is_project_root', true)
                        ->orWhereNull('parent_id');
                });
        } else {
            $this->spaceVisibility->restrictToExplorerRoots($query, $actor);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    /**
     * @param  array{name: string, parent_id?: int|null, project_id?: int|null, department_id?: int|null, tag_ids?: array<int>}  $data
     */
    public function create(User $actor, array $data): Folder
    {
        if (! empty($data['parent_id'])) {
            $parent = Folder::query()->findOrFail($data['parent_id']);
            abort_unless($this->spaceVisibility->canViewFolder($actor, $parent), 403);
            $data['project_id'] = $parent->project_id;
            $data['department_id'] = $parent->department_id;
        } else {
            $data['project_id'] = null;
            if (! empty($data['department_id'])) {
                $this->assertCanCreateDepartmentPublicFolder($actor, (int) $data['department_id']);
            } else {
                $data['department_id'] = null;
            }
        }

        $folder = Folder::query()->create([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'created_by' => $actor->id,
        ]);

        if (! empty($data['tag_ids'])) {
            $folder->tags()->sync($data['tag_ids']);
        }

        $folder->load(['creator', 'project', 'department', 'tags'])->loadCount(['children', 'documents']);

        event(new FolderCreated($folder, $actor));

        return $folder;
    }

    /**
     * @param  array{name?: string, parent_id?: int|null, project_id?: int|null, department_id?: int|null, tag_ids?: array<int>}  $data
     */
    public function update(Folder $folder, array $data): Folder
    {
        if (array_key_exists('parent_id', $data)) {
            $this->assertValidParent($folder, $data['parent_id']);
        }

        $folder->fill(collect($data)->only([
            'name',
            'parent_id',
            'project_id',
            'department_id',
        ])->all());
        $folder->save();

        if (array_key_exists('tag_ids', $data)) {
            $folder->tags()->sync($data['tag_ids'] ?? []);
        }

        return $folder->load(['creator', 'project', 'department', 'tags'])->loadCount(['children', 'documents']);
    }

    public function move(Folder $folder, ?int $parentId): Folder
    {
        $this->assertValidParent($folder, $parentId);

        $folder->parent_id = $parentId;
        $folder->save();

        return $folder->load(['creator', 'project', 'department'])->loadCount(['children', 'documents']);
    }

    public function delete(Folder $folder): void
    {
        $name = $folder->name;

        DB::transaction(function () use ($folder) {
            $this->deleteRecursive($folder);
        });

        event(new FolderDeleted($folder, $name));
    }

    /** Soft-delete le dossier, ses sous-dossiers et documents (corbeille). */
    private function deleteRecursive(Folder $folder): void
    {
        $children = $folder->children()->get();
        foreach ($children as $child) {
            $this->deleteRecursive($child);
        }

        $documents = $folder->documents()->get();
        foreach ($documents as $document) {
            $reference = $document->reference;
            $document->status = DocumentStatus::Deleted;
            $document->save();
            $document->delete();
            event(new DocumentDeleted($document, $reference));
        }

        if (! $folder->trashed()) {
            $folder->delete();
        }
    }

    public function restore(Folder $folder): Folder
    {
        $folder->restore();

        return $folder->load(['creator', 'project', 'department'])->loadCount(['children', 'documents']);
    }

    public function forceDelete(Folder $folder): void
    {
        DB::transaction(function () use ($folder) {
            $this->forceDeleteRecursive($folder);
        });
    }

    private function forceDeleteRecursive(Folder $folder): void
    {
        $children = $folder->children()->withTrashed()->get();
        foreach ($children as $child) {
            $this->forceDeleteRecursive($child);
        }

        $documents = $folder->documents()->withTrashed()->get();
        foreach ($documents as $document) {
            $this->documentService->forceDelete($document);
        }

        $folder->tags()->detach();
        $folder->accesses()->delete();
        $folder->favorites()->delete();
        $folder->forceDelete();
    }

    private function assertValidParent(Folder $folder, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $folder->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['Un dossier ne peut pas être son propre parent.'],
            ]);
        }

        $parent = Folder::query()->findOrFail($parentId);
        $current = $parent;

        // ponytail: walk ancestors O(depth); upgrade: path/ltree materialized path
        while ($current) {
            if ($current->id === $folder->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Déplacement invalide : cycle détecté dans l\'arborescence.'],
                ]);
            }
            $current = $current->parent;
        }
    }

    private function assertCanCreateDepartmentPublicFolder(User $actor, int $departmentId): void
    {
        if (! $actor->hasPermission('projects.manage')) {
            throw ValidationException::withMessages([
                'department_id' => ['Seuls les profils autorisés à créer un projet peuvent créer un dossier public de département.'],
            ]);
        }

        if ($actor->isDepartmentScopedProjectManager()) {
            if (! $actor->department_id) {
                throw ValidationException::withMessages([
                    'department_id' => ['Votre compte n’est rattaché à aucun département.'],
                ]);
            }

            if ((int) $actor->department_id !== $departmentId) {
                throw ValidationException::withMessages([
                    'department_id' => ['Vous ne pouvez créer un dossier public que pour votre département.'],
                ]);
            }
        }
    }
}
