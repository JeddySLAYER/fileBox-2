<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('roles.manage')
            || $actor->hasPermission('workflows.manage');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.manage')
            || $actor->hasPermission('workflows.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('roles.manage');
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.manage');
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.manage');
    }
}
