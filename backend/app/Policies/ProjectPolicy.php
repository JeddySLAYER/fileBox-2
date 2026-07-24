<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('projects.manage');
    }

    public function view(User $actor, Project $project): bool
    {
        if ($actor->hasPermission('projects.manage')) {
            return true;
        }

        if ((int) $project->manager_id === (int) $actor->id) {
            return true;
        }

        return $project->members()->where('users.id', $actor->id)->exists();
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('projects.manage');
    }

    public function update(User $actor, Project $project): bool
    {
        return $actor->hasPermission('projects.manage');
    }

    public function delete(User $actor, Project $project): bool
    {
        return $actor->hasPermission('projects.manage');
    }

    public function restore(User $actor, Project $project): bool
    {
        return $actor->hasPermission('projects.manage');
    }
}
