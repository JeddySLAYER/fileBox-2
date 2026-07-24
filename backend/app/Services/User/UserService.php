<?php

namespace App\Services\User;

use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use App\Support\SoftDeleteArchive;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
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
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['role_ids'])) {
                $user->roles()->sync($data['role_ids']);
            }

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
            $user->fill(collect($data)->only([
                'name',
                'email',
                'department_id',
                'is_active',
            ])->all());

            $user->save();

            if (array_key_exists('role_ids', $data)) {
                $user->roles()->sync($data['role_ids'] ?? []);
            }

            return $user->load(['roles.permissions', 'department']);
        });
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
