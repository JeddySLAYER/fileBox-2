<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Validation;

class ValidationPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function act(User $actor, Validation $validation): bool
    {
        if ($actor->hasPermission('workflows.manage') || $actor->hasPermission('validations.act')) {
            return true;
        }

        $step = $validation->workflowStep()->first();
        if (! $step) {
            return false;
        }

        if ($step->responsible_user_id && $step->responsible_user_id === $actor->id) {
            return true;
        }

        return $step->responsible_role_id
            && $actor->roles()->where('roles.id', $step->responsible_role_id)->exists();
    }
}
