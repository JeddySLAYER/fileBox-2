<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view')
            || $actor->hasPermission('workflows.manage')
            || $actor->hasPermission('documents.share')
            || $actor->hasPermission('accesses.manage');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.view') || $actor->is($user);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.update');
    }

    public function delete(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return false;
        }

        return $actor->hasPermission('users.delete');
    }

    public function restore(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.update');
    }
}
