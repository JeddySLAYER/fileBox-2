<?php

namespace App\Services\Project;

use App\Models\Department;
use App\Models\Folder;
use App\Models\Project;
use App\Models\User;
use App\Support\SoftDeleteArchive;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public const STATUSES = ['actif', 'en_pause', 'termine', 'archive'];

    /**
     * @param  array{search?: string, department_id?: int, status?: string}  $filters
     */
    public function list(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Project::query()
            ->with(['department', 'departments', 'manager', 'rootFolder'])
            ->withCount('members')
            ->latest();

        // Admin / direction / chef : tous. Autres (dont responsables) : membership only.
        if (! $actor->canManageProjectsGlobally()) {
            $query->where(function ($q) use ($actor) {
                $q->where('manager_id', $actor->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $actor->id));
            });
        }

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
            });
        }

        if (! empty($filters['department_id'])) {
            $deptId = (int) $filters['department_id'];
            $query->where(function ($q) use ($deptId) {
                $q->where('department_id', $deptId)
                    ->orWhereHas('departments', fn ($d) => $d->where('departments.id', $deptId));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{
     *   name: string,
     *   code?: string,
     *   description?: string|null,
     *   department_id?: int|null,
     *   department_ids?: array<int>,
     *   manager_id?: int|null,
     *   status?: string,
     *   starts_at?: string|null,
     *   ends_at?: string|null,
     *   member_ids?: array<int>
     * }  $data
     */
    public function create(User $actor, array $data): Project
    {
        $this->assertDates($data);

        return DB::transaction(function () use ($actor, $data) {
            if ($actor->isDepartmentScopedProjectManager()) {
                if (! $actor->department_id) {
                    throw ValidationException::withMessages([
                        'department_ids' => ['Votre compte n’est rattaché à aucun département.'],
                    ]);
                }
                $data['department_ids'] = [(int) $actor->department_id];
                $data['manager_id'] = $data['manager_id'] ?? $actor->id;
            }

            $departmentIds = $this->normalizeDepartmentIds($data);

            $project = Project::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? $this->generateCode($data['name']),
                'description' => $data['description'] ?? null,
                'department_id' => $departmentIds[0] ?? null,
                'manager_id' => $data['manager_id'] ?? $actor->id,
                'created_by' => $actor->id,
                'status' => $data['status'] ?? 'actif',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
            ]);

            $project->departments()->sync($departmentIds);

            $folder = Folder::query()->create([
                'name' => $project->name,
                'parent_id' => null,
                'project_id' => $project->id,
                'department_id' => $project->department_id,
                'created_by' => $actor->id,
                'is_project_root' => true,
            ]);

            $project->root_folder_id = $folder->id;
            $project->save();

            $extra = array_values(array_unique(array_map('intval', $data['member_ids'] ?? [])));
            $extra[] = (int) $actor->id;
            $this->ensureMandatoryMembers($project, $extra);

            return $project->load(['department', 'departments', 'manager', 'members', 'rootFolder'])
                ->loadCount('members');
        });
    }

    /**
     * @param  array{
     *   name?: string,
     *   code?: string,
     *   description?: string|null,
     *   department_id?: int|null,
     *   department_ids?: array<int>,
     *   manager_id?: int|null,
     *   status?: string,
     *   starts_at?: string|null,
     *   ends_at?: string|null,
     *   member_ids?: array<int>
     * }  $data
     */
    public function update(Project $project, array $data): Project
    {
        $this->assertDates($data);

        return DB::transaction(function () use ($project, $data) {
            $fill = collect($data)->only([
                'name',
                'code',
                'description',
                'manager_id',
                'status',
                'starts_at',
                'ends_at',
            ])->all();

            $departmentIds = null;
            if (array_key_exists('department_ids', $data) || array_key_exists('department_id', $data)) {
                $departmentIds = $this->normalizeDepartmentIds($data);
                $fill['department_id'] = $departmentIds[0] ?? null;
                $project->departments()->sync($departmentIds);
            }

            $project->fill($fill);
            $project->save();

            if (array_key_exists('member_ids', $data)) {
                $this->syncMembers($project, $data['member_ids'] ?? []);
            } else {
                $this->ensureMandatoryMembers($project);
            }

            if ($project->rootFolder && array_key_exists('name', $data)) {
                $project->rootFolder->update([
                    'name' => $project->name,
                    'department_id' => $project->department_id,
                ]);
            }

            return $project->load(['department', 'departments', 'manager', 'members', 'rootFolder'])
                ->loadCount('members');
        });
    }

    public function delete(Project $project): void
    {
        SoftDeleteArchive::archive($project, ['code']);
    }

    /**
     * @param  array<int>  $memberIds
     */
    public function syncMembers(Project $project, array $memberIds): Project
    {
        $this->ensureMandatoryMembers($project, array_map('intval', $memberIds), replace: true);

        return $project->load(['department', 'departments', 'manager', 'members', 'rootFolder'])
            ->loadCount('members');
    }

    /**
     * Ajoute créateur + responsables des départements liés (et optionnellement d’autres membres).
     *
     * @param  array<int>  $extraMemberIds
     */
    public function ensureMandatoryMembers(Project $project, array $extraMemberIds = [], bool $replace = false): void
    {
        $project->loadMissing('rootFolder');

        $deptIds = $project->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
        if ($project->department_id) {
            $deptIds[] = (int) $project->department_id;
        }
        $deptIds = array_values(array_unique($deptIds));

        $mandatory = $this->departmentManagerIds($deptIds);
        if ($project->created_by) {
            $mandatory[] = (int) $project->created_by;
        }
        if ($project->manager_id) {
            $mandatory[] = (int) $project->manager_id;
        }
        if ($project->rootFolder?->created_by) {
            $mandatory[] = (int) $project->rootFolder->created_by;
        }

        $ids = array_values(array_unique(array_merge(
            array_map('intval', $extraMemberIds),
            $mandatory,
        )));

        if ($replace) {
            $project->members()->sync($ids);
        } else {
            $project->members()->syncWithoutDetaching($ids);
        }
    }

    /** Resynchronise les membres obligatoires de tous les projets existants. */
    public function syncAllMandatoryMembers(): int
    {
        $count = 0;
        Project::query()->with('rootFolder')->each(function (Project $project) use (&$count) {
            $this->ensureMandatoryMembers($project);
            $count++;
        });

        return $count;
    }

    /** Candidats membres = tous les utilisateurs actifs. */
    public function memberCandidates(Project $project): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id']);
    }

    /**
     * @param  array<int>  $departmentIds
     * @return array<int>
     */
    private function departmentManagerIds(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $fromManagerColumn = Department::query()
            ->whereIn('id', $departmentIds)
            ->whereNotNull('manager_id')
            ->pluck('manager_id');

        // Filet de sécurité : rôle responsable + département_id
        $fromRole = User::query()
            ->whereIn('department_id', $departmentIds)
            ->whereHas('roles', fn ($q) => $q->where('slug', 'responsable_departement'))
            ->pluck('id');

        return $fromManagerColumn
            ->merge($fromRole)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    private function normalizeDepartmentIds(array $data): array
    {
        $ids = $data['department_ids'] ?? [];
        if ($ids === [] && ! empty($data['department_id'])) {
            $ids = [(int) $data['department_id']];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @param  array<string, mixed>  $data */
    private function assertDates(array $data): void
    {
        if (empty($data['starts_at']) || empty($data['ends_at'])) {
            return;
        }

        if ($data['ends_at'] < $data['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => ['La date de fin doit être postérieure ou égale à la date de début.'],
            ]);
        }
    }

    private function generateCode(string $name): string
    {
        $slug = Str::upper(Str::slug($name, '-'));

        return 'PRJ-'.$slug.'-'.now()->year;
    }
}
