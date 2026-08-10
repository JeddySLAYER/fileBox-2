<?php

namespace App\Services\User;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use App\Services\Department\DepartmentService;
use App\Support\SoftDeleteArchive;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    private const MANAGER_ROLE_SLUG = 'responsable_departement';

    public function __construct(
        private readonly DepartmentService $departmentService,
    ) {}
    /**
     * @param  array{search?: string, department_id?: int, role?: string, is_active?: bool}  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['roles', 'department'])
            ->latest();

        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('slug', $filters['role']));
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, email: string, department_id?: int|null, role_ids?: array<int>, is_active?: bool}  $data
     * @return array{user: User, temporary_password: string}
     */
    public function create(array $data): array
    {
        $temporaryPassword = Str::password(12);

        $user = DB::transaction(function () use ($data, $temporaryPassword) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $temporaryPassword,
                'department_id' => $data['department_id'] ?? null,
                'must_change_password' => true,
                'temporary_password_expires_at' => Carbon::now()->addDay(),
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['role_ids'])) {
                $data['role_ids'] = $this->assertExclusiveInviteRole($data['role_ids']);
                $user->roles()->sync($data['role_ids']);
            }

            $this->enforceInviteDepartmentRule($user, $data);
            $this->syncResponsableAssignment($user->fresh(), $data);

            return $user->load(['roles.permissions', 'department']);
        });

        $mailSent = true;
        $mailError = null;

        try {
            $user->notify(new TemporaryPasswordNotification($temporaryPassword, 'created'));
        } catch (\Throwable $e) {
            report($e);
            $mailSent = false;
            $mailError = $e->getMessage();
        }

        return [
            'user' => $user,
            'temporary_password' => $temporaryPassword,
            'mail_sent' => $mailSent,
            'mail_error' => $mailError,
        ];
    }

    /**
     * @param  array{name?: string, email?: string, department_id?: int|null, role_ids?: array<int>, is_active?: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $wasActive = (bool) $user->is_active;
            $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];

            if ($deactivating && Auth::id() === $user->id) {
                throw ValidationException::withMessages([
                    'is_active' => ['Vous ne pouvez pas désactiver votre propre compte.'],
                ]);
            }

            $user->fill(collect($data)->only([
                'name',
                'email',
                'department_id',
                'is_active',
            ])->all());

            $user->save();

            if (array_key_exists('role_ids', $data)) {
                $data['role_ids'] = $this->assertExclusiveInviteRole($data['role_ids'] ?? []);
                $user->roles()->sync($data['role_ids']);
            }

            $this->enforceInviteDepartmentRule($user, $data);
            $this->syncResponsableAssignment($user->fresh(), $data);

            // Fin de session immédiate si le compte vient d'être désactivé
            if ($wasActive && ! $user->is_active) {
                $user->tokens()->delete();
            }

            return $user->load(['roles.permissions', 'department']);
        });
    }

    /**
     * Un invité ne peut pas être rattaché à un département.
     *
     * @param  array{department_id?: int|null, role_ids?: array<int>}  $data
     */
    private function enforceInviteDepartmentRule(User $user, array $data): void
    {
        $inviteId = Role::query()->where('slug', 'invite')->value('id');
        if (! $inviteId) {
            return;
        }

        $roleIds = array_key_exists('role_ids', $data)
            ? array_map('intval', $data['role_ids'] ?? [])
            : $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

        if (! in_array((int) $inviteId, $roleIds, true)) {
            return;
        }

        if (array_key_exists('department_id', $data)
            && $data['department_id'] !== null
            && $data['department_id'] !== '') {
            throw ValidationException::withMessages([
                'department_id' => ['Un invité ne peut pas être rattaché à un département.'],
            ]);
        }

        if ($user->department_id !== null) {
            $user->department_id = null;
            $user->save();
        }
    }

    /**
     * Le rôle invité est exclusif : aucun autre rôle en parallèle.
     *
     * @param  array<int>  $roleIds
     * @return array<int>
     */
    private function assertExclusiveInviteRole(array $roleIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $roleIds)));
        if ($ids === []) {
            return $ids;
        }

        $inviteId = Role::query()->where('slug', 'invite')->value('id');
        if (! $inviteId) {
            return $ids;
        }

        if (in_array((int) $inviteId, $ids, true) && count($ids) > 1) {
            throw ValidationException::withMessages([
                'role_ids' => ['Le rôle Invité ne peut pas être combiné avec un autre rôle.'],
            ]);
        }

        return $ids;
    }

    /**
     * Si le rôle responsable est attribué : devient manager du département
     * (remplacement confirmé si un autre responsable existe déjà).
     *
     * @param  array{department_id?: int|null, role_ids?: array<int>, replace_department_manager?: bool}  $data
     */
    private function syncResponsableAssignment(User $user, array $data): void
    {
        if (! array_key_exists('role_ids', $data) && ! array_key_exists('department_id', $data)) {
            return;
        }

        $managerRoleId = Role::query()->where('slug', self::MANAGER_ROLE_SLUG)->value('id');
        if (! $managerRoleId) {
            return;
        }

        $roleIds = array_key_exists('role_ids', $data)
            ? array_map('intval', $data['role_ids'] ?? [])
            : $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

        $wantsManager = in_array((int) $managerRoleId, $roleIds, true);

        $departmentId = array_key_exists('department_id', $data)
            ? ($data['department_id'] !== null && $data['department_id'] !== '' ? (int) $data['department_id'] : null)
            : ($user->department_id ? (int) $user->department_id : null);

        if ($wantsManager) {
            if (! $departmentId) {
                throw ValidationException::withMessages([
                    'department_id' => ['Un département est requis pour le rôle responsable de département.'],
                ]);
            }

            $department = Department::query()->findOrFail($departmentId);
            $currentManagerId = $department->manager_id ? (int) $department->manager_id : null;

            if ($currentManagerId && $currentManagerId !== (int) $user->id) {
                if (empty($data['replace_department_manager'])) {
                    $current = User::query()->find($currentManagerId);
                    throw ValidationException::withMessages([
                        'replace_department_manager' => [
                            sprintf(
                                '« %s » est déjà responsable de « %s ». Confirmez le remplacement.',
                                $current?->name ?? 'Un utilisateur',
                                $department->name,
                            ),
                        ],
                    ]);
                }
            }

            $this->departmentService->assignManager($department, $user);

            return;
        }

        // Rôle responsable retiré explicitement → retire le poste de manager
        if (! array_key_exists('role_ids', $data)) {
            return;
        }

        foreach (Department::query()->where('manager_id', $user->id)->get() as $managed) {
            $this->departmentService->clearManagerIf($managed, (int) $user->id);
        }
    }

    public function delete(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'user' => ['Vous ne pouvez pas supprimer votre propre compte.'],
            ]);
        }

        $user->tokens()->delete();
        SoftDeleteArchive::archive($user, ['email']);
    }

    /**
     * @return array{user: User, temporary_password: string}
     */
    public function resetTemporaryPassword(User $user): array
    {
        $temporaryPassword = Str::password(12);

        $user->password = $temporaryPassword;
        $user->must_change_password = true;
        $user->temporary_password_expires_at = Carbon::now()->addDay();
        $user->save();

        $user->tokens()->delete();
        $mailSent = true;
        $mailError = null;

        try {
            $user->notify(new TemporaryPasswordNotification($temporaryPassword, 'reset'));
        } catch (\Throwable $e) {
            report($e);
            $mailSent = false;
            $mailError = $e->getMessage();
        }

        return [
            'user' => $user->load(['roles.permissions', 'department']),
            'temporary_password' => $temporaryPassword,
            'mail_sent' => $mailSent,
            'mail_error' => $mailError,
        ];
    }
}
