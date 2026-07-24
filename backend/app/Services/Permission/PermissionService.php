<?php

namespace App\Services\Permission;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PermissionService
{
    public function list(?string $module = null): Collection
    {
        $query = Permission::query()->orderBy('module')->orderBy('name');

        if ($module) {
            $query->where('module', $module);
        }

        return $query->get();
    }

    /**
     * @param  array{name: string, slug?: string, module?: string|null, description?: string|null}  $data
     */
    public function create(array $data): Permission
    {
        return Permission::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name'], '.'),
            'module' => $data['module'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @param  array{name?: string, slug?: string, module?: string|null, description?: string|null}  $data
     */
    public function update(Permission $permission, array $data): Permission
    {
        $permission->fill(collect($data)->only(['name', 'slug', 'module', 'description'])->all());
        $permission->save();

        return $permission;
    }

    public function delete(Permission $permission): void
    {
        if ($permission->roles()->exists()) {
            throw ValidationException::withMessages([
                'permission' => ['Impossible de supprimer une permission encore liée à des rôles.'],
            ]);
        }

        $permission->delete();
    }
}
