<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('projects.view')
            || $actor->hasPermission('projects.manage');
    }

    public function view(User $actor, Project $project): bool
    {
        if ($actor->canManageProjectsGlobally()) {
            return true;
        }

        if (! $actor->hasPermission('projects.view') && ! $actor->hasPermission('projects.manage')) {
            return false;
        }

        return $project->isParticipant($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('projects.manage');
    }

    public function update(User $actor, Project $project): bool
    {
        if (! $actor->hasPermission('projects.manage')) {
            return false;
        }

        if ($actor->canManageProjectsGlobally()) {
            return true;
        }

        // Responsable : uniquement les projets dont il est membre
        return $project->isParticipant($actor);
    }

    public function delete(User $actor, Project $project): bool
    {
        return $this->update($actor, $project);
    }

    public function restore(User $actor, Project $project): bool
    {
        return $this->update($actor, $project);
    }
}
