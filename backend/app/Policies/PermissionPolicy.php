<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;

class PermissionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('permissions.manage')
            || $actor->hasPermission('roles.manage');
    }

    public function view(User $actor, Permission $permission): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('permissions.manage');
    }

    public function update(User $actor, Permission $permission): bool
    {
        return $actor->hasPermission('permissions.manage');
    }

    public function delete(User $actor, Permission $permission): bool
    {
        return $actor->hasPermission('permissions.manage');
    }
}
