<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('departments.manage');
    }

    public function view(User $actor, Department $department): bool
    {
        return $actor->hasPermission('departments.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('departments.manage');
    }

    public function update(User $actor, Department $department): bool
    {
        return $actor->hasPermission('departments.manage');
    }

    public function delete(User $actor, Department $department): bool
    {
        return $actor->hasPermission('departments.manage');
    }

    public function restore(User $actor, Department $department): bool
    {
        return $actor->hasPermission('departments.manage');
    }
}
