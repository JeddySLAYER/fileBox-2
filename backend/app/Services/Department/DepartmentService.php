<?php

namespace App\Services\Department;

use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\SoftDeleteArchive;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    private const MANAGER_ROLE_SLUG = 'responsable_departement';

    private const FALLBACK_ROLE_SLUG = 'collaborateur';

    /**
     * @param  array{search?: string}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Department::query()
            ->with('manager')
            ->withCount(['users', 'projects'])
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"]);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, code?: string, description?: string|null, manager_id?: int|null}  $data
     */
    public function create(array $data): Department
    {
        return DB::transaction(function () use ($data) {
            $department = Department::query()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? Str::upper(Str::slug($data['name'], '_')),
                'description' => $data['description'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
            ]);

            if (! empty($data['manager_id'])) {
                $this->promoteManager($department->id, (int) $data['manager_id']);
            }

            return $department->load('manager')->loadCount(['users', 'projects']);
        });
    }

    /**
     * @param  array{name?: string, code?: string, description?: string|null, manager_id?: int|null}  $data
     */
    public function update(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data) {
            $previousManagerId = $department->manager_id;

            $department->fill(collect($data)->only(['name', 'code', 'description', 'manager_id'])->all());
            $department->save();

            if (array_key_exists('manager_id', $data)) {
                $newManagerId = $data['manager_id'] !== null && $data['manager_id'] !== ''
                    ? (int) $data['manager_id']
                    : null;

                if ($previousManagerId && $previousManagerId !== $newManagerId) {
                    $this->demoteManager($department->id, (int) $previousManagerId);
                }

                if ($newManagerId) {
                    $this->promoteManager($department->id, $newManagerId);
                }
            }

            return $department->load('manager')->loadCount(['users', 'projects']);
        });
    }

    public function delete(Department $department): void
    {
        SoftDeleteArchive::archive($department, ['code']);
    }

    /**
     * Nomme un responsable (remplace l’éventuel précédent).
     * Le département de l’utilisateur devient celui-ci.
     */
    public function assignManager(Department $department, User $user): void
    {
        $previousManagerId = $department->manager_id;
        if ($previousManagerId && (int) $previousManagerId !== (int) $user->id) {
            $department->manager_id = $user->id;
            $department->save();
            $this->demoteManager($department->id, (int) $previousManagerId);
        } else {
            $department->manager_id = $user->id;
            $department->save();
        }

        $this->promoteManager($department->id, (int) $user->id);
    }

    /** Retire le poste de responsable du département (si c’est bien lui). */
    public function clearManagerIf(Department $department, int $userId): void
    {
        if ((int) $department->manager_id !== $userId) {
            return;
        }

        $department->manager_id = null;
        $department->save();
        $this->demoteManager($department->id, $userId);
    }

    private function promoteManager(int $departmentId, int $userId): void
    {
        Department::query()
            ->where('manager_id', $userId)
            ->where('id', '!=', $departmentId)
            ->update(['manager_id' => null]);

        $department = Department::query()->findOrFail($departmentId);
        if ((int) $department->manager_id !== $userId) {
            $department->manager_id = $userId;
            $department->save();
        }

        $user = User::query()->findOrFail($userId);
        if ($user->roles()->whereIn('slug', User::ROLES_WITHOUT_DEPARTMENT)->exists()) {
            throw ValidationException::withMessages([
                'manager_id' => ['Un administrateur, chef de projet, directeur ou invité ne peut pas être responsable d’un département.'],
            ]);
        }

        $user->department_id = $departmentId;
        $user->save();

        $roleId = $this->managerRoleId();
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }

        $this->attachToDepartmentProjects($departmentId, $userId);
    }

    private function demoteManager(int $departmentId, int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        if ($user->department_id === null) {
            $user->department_id = $departmentId;
            $user->save();
        }

        $stillManagesAnother = Department::query()
            ->where('manager_id', $userId)
            ->exists();

        if ($stillManagesAnother) {
            return;
        }

        $roleId = $this->managerRoleId();
        if ($roleId) {
            $user->roles()->detach($roleId);
        }

        // Sans autre rôle → collaborateur
        if ($user->roles()->count() === 0) {
            $fallbackId = Role::query()->where('slug', self::FALLBACK_ROLE_SLUG)->value('id');
            if ($fallbackId) {
                $user->roles()->attach($fallbackId);
            }
        }
    }

    private function attachToDepartmentProjects(int $departmentId, int $userId): void
    {
        $projectIds = Project::query()
            ->where(function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId)
                    ->orWhereHas('departments', fn ($d) => $d->where('departments.id', $departmentId));
            })
            ->pluck('id');

        foreach ($projectIds as $projectId) {
            Project::query()->find($projectId)?->members()->syncWithoutDetaching([$userId]);
        }
    }

    private function managerRoleId(): ?int
    {
        return Role::query()->where('slug', self::MANAGER_ROLE_SLUG)->value('id');
    }
}
