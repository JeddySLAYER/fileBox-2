<?php

namespace App\Events\Comment;

use App\Events\DomainActivityEvent;
use App\Models\Comment;
use App\Models\Document;
use App\Models\User;

class CommentPosted extends DomainActivityEvent
{
    public function __construct(
        public readonly Comment $comment,
        public readonly Document $document,
        User $actor,
    ) {
        parent::__construct(
            action: 'comment.created',
            user: $actor,
            subject: $document,
            description: "Commentaire sur {$document->reference}",
            properties: ['comment_id' => $comment->id],
        );
    }
}
