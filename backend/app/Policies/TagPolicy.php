<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('tags.manage')
            || $actor->hasPermission('documents.view');
    }

    public function view(User $actor, Tag $tag): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('tags.manage');
    }

    public function update(User $actor, Tag $tag): bool
    {
        return $actor->hasPermission('tags.manage');
    }

    public function delete(User $actor, Tag $tag): bool
    {
        return $actor->hasPermission('tags.manage');
    }
}
