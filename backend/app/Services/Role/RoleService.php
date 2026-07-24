<?php

namespace App\Services\Role;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleService
{
    /** @var list<string> */
    private const PROTECTED_SLUGS = [
        'administrateur',
        'collaborateur',
        'invite',
    ];

    public function list(): Collection
    {
        return Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, slug?: string, description?: string|null, permission_ids?: array<int>}  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'description' => $data['description'] ?? null,
            ]);

            if (! empty($data['permission_ids'])) {
                $role->permissions()->sync($data['permission_ids']);
            }

            return $role->load('permissions')->loadCount('users');
        });
    }

    /**
     * @param  array{name?: string, slug?: string, description?: string|null, permission_ids?: array<int>}  $data
     */
    public function update(Role $role, array $data): Role
    {
        if (isset($data['slug']) && $data['slug'] !== $role->slug && $this->isProtected($role)) {
            throw ValidationException::withMessages([
                'slug' => ['Le slug d\'un rôle système ne peut pas être modifié.'],
            ]);
        }

        return DB::transaction(function () use ($role, $data) {
            $role->fill(collect($data)->only(['name', 'slug', 'description'])->all());
            $role->save();

            if (array_key_exists('permission_ids', $data)) {
                $role->permissions()->sync($data['permission_ids'] ?? []);
            }

            return $role->load('permissions')->loadCount('users');
        });
    }

    public function delete(Role $role): void
    {
        if ($this->isProtected($role)) {
            throw ValidationException::withMessages([
                'role' => ['Ce rôle système ne peut pas être supprimé.'],
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => ['Impossible de supprimer un rôle encore attribué à des utilisateurs.'],
            ]);
        }

        $role->permissions()->detach();
        $role->delete();
    }

    /**
     * @param  array<int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        $role->permissions()->sync($permissionIds);

        return $role->load('permissions')->loadCount('users');
    }

    private function isProtected(Role $role): bool
    {
        return in_array($role->slug, self::PROTECTED_SLUGS, true);
    }
}
