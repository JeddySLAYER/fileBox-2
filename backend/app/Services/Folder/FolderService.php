<?php

namespace App\Services\Folder;

use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessService;
use App\Services\ActivityLog\ActivityLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class FolderService
{
    public function __construct(
        private readonly AccessService $accessService,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @param  array{parent_id?: int|null, project_id?: int|null, department_id?: int|null, trashed?: bool}  $filters
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $scoped = ! $actor->hasPermission('folders.view');

        $query = Folder::query()
            ->with(['creator', 'project', 'department'])
            ->withCount(['children', 'documents'])
            ->orderBy('name');

        if (! empty($filters['trashed'])) {
            $query->onlyTrashed();
        }

        if (array_key_exists('parent_id', $filters)) {
            $query->where('parent_id', $filters['parent_id']);
        } elseif (empty($filters['trashed']) && ! $scoped) {
            $query->whereNull('parent_id');
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if ($scoped) {
            $ids = $this->accessService->accessibleFolderIds($actor);
            $query->whereIn('id', $ids ?: [0]);
        }

        return $query->get();
    }

    public function tree(User $actor, ?int $projectId = null, ?int $departmentId = null): Collection
    {
        if (! $actor->hasPermission('folders.view')) {
            // ponytail: flat list for access-only users; upgrade: build subtree from accessible roots
            return $this->list($actor, array_filter([
                'project_id' => $projectId,
                'department_id' => $departmentId,
            ], fn ($v) => $v !== null));
        }

        $query = Folder::query()
            ->with(['children' => fn ($q) => $q->orderBy('name')])
            ->withCount(['children', 'documents'])
            ->whereNull('parent_id')
            ->orderBy('name');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    /**
     * @param  array{name: string, parent_id?: int|null, project_id?: int|null, department_id?: int|null}  $data
     */
    public function create(User $actor, array $data): Folder
    {
        if (! empty($data['parent_id'])) {
            $parent = Folder::query()->findOrFail($data['parent_id']);
            $data['project_id'] ??= $parent->project_id;
            $data['department_id'] ??= $parent->department_id;
        }

        $folder = Folder::query()->create([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'created_by' => $actor->id,
        ])->load(['creator', 'project', 'department'])->loadCount(['children', 'documents']);

        $this->activityLog->log(
            action: 'folder.created',
            user: $actor,
            subject: $folder,
            description: "Dossier créé : {$folder->name}",
        );

        return $folder;
    }

    /**
     * @param  array{name?: string, parent_id?: int|null, project_id?: int|null, department_id?: int|null}  $data
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

        return $folder->load(['creator', 'project', 'department'])->loadCount(['children', 'documents']);
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
        if ($folder->children()->exists()) {
            throw ValidationException::withMessages([
                'folder' => ['Impossible de supprimer un dossier contenant des sous-dossiers.'],
            ]);
        }

        if ($folder->documents()->exists()) {
            throw ValidationException::withMessages([
                'folder' => ['Impossible de supprimer un dossier contenant des documents.'],
            ]);
        }

        $name = $folder->name;
        $folder->delete();

        $this->activityLog->log(
            action: 'folder.deleted',
            subject: $folder,
            description: "Dossier supprimé : {$name}",
        );
    }

    public function restore(Folder $folder): Folder
    {
        $folder->restore();

        return $folder->load(['creator', 'project', 'department'])->loadCount(['children', 'documents']);
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
}
