<?php

namespace App\Services\Folder;

use App\Enums\DocumentStatus;
use App\Events\Document\DocumentDeleted;
use App\Events\Folder\FolderCreated;
use App\Events\Folder\FolderDeleted;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FolderService
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {}

    /**
     * @param  array{parent_id?: int|null, project_id?: int|null, department_id?: int|null, trashed?: bool}  $filters
     */
    public function list(User $actor, array $filters = []): Collection
    {
        $scoped = ! $actor->hasPermission('folders.view');

        $query = Folder::query()
            ->with(['creator', 'project', 'department', 'tags'])
            ->withCount(['children', 'documents'])
            ->orderBy('name');

        if (! empty($filters['trashed'])) {
            $query->onlyTrashed();
        }

        if (array_key_exists('parent_id', $filters)) {
            $query->where('parent_id', $filters['parent_id']);
        } elseif (empty($filters['trashed']) && ! $scoped) {
            $query->whereNull('parent_id')
                ->where('is_project_root', false);
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

        return $query
            ->withExists([
                'favorites as is_favorited' => fn ($q) => $q->where('user_id', $actor->id),
            ])
            ->get();
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
            ->with([
                'children' => fn ($q) => $q->orderBy('name')->with([
                    'children' => fn ($q2) => $q2->orderBy('name')->with([
                        'children' => fn ($q3) => $q3->orderBy('name'),
                    ]),
                ]),
            ])
            ->withCount(['children', 'documents'])
            ->whereNull('parent_id')
            ->where('is_project_root', false)
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
     * @param  array{name: string, parent_id?: int|null, project_id?: int|null, department_id?: int|null, tag_ids?: array<int>}  $data
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
