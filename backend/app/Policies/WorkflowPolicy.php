<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;

class WorkflowPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('workflows.manage')
            || $actor->hasPermission('validations.act')
            || $actor->hasPermission('documents.view');
    }

    public function view(User $actor, Workflow $workflow): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('workflows.manage');
    }

    public function update(User $actor, Workflow $workflow): bool
    {
        return $actor->hasPermission('workflows.manage');
    }

    public function delete(User $actor, Workflow $workflow): bool
    {
        return $actor->hasPermission('workflows.manage');
    }

    public function restore(User $actor, Workflow $workflow): bool
    {
        return $actor->hasPermission('workflows.manage');
    }
}
