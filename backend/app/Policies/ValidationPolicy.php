<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Validation;

class ValidationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('validations.act')
            || $actor->hasPermission('workflows.manage')
            || $actor->hasPermission('documents.view');
    }

    public function act(User $actor, Validation $validation): bool
    {
        return $actor->hasPermission('validations.act')
            || $actor->hasPermission('workflows.manage');
    }
}
