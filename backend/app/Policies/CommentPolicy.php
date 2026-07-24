<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Document;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $actor, ?Document $document = null): bool
    {
        return $actor->hasPermission('documents.view')
            || $actor->hasPermission('comments.create');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('comments.create');
    }

    public function update(User $actor, Comment $comment): bool
    {
        return $actor->id === $comment->user_id
            || $actor->hasPermission('documents.update');
    }

    public function delete(User $actor, Comment $comment): bool
    {
        return $actor->id === $comment->user_id
            || $actor->hasPermission('documents.delete');
    }
}
