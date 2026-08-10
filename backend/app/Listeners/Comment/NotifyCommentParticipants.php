<?php

namespace App\Listeners\Comment;

use App\Events\Comment\CommentPosted;
use App\Models\User;
use App\Notifications\CommentPostedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyCommentParticipants
{
    public function handle(CommentPosted $event): void
    {
        $comment = $event->comment;
        $document = $event->document;

        $ids = collect([$document->author_id, $document->owner_id]);

        // Réponse : notifier l'auteur du commentaire parent
        if ($comment->parent_id) {
            $parentAuthorId = $comment->relationLoaded('parent')
                ? $comment->parent?->user_id
                : $comment->parent()->value('user_id');

            if ($parentAuthorId) {
                $ids->push($parentAuthorId);
            }
        }

        $ids = $ids
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $comment->user_id)
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new CommentPostedNotification($comment, $document),
        );
    }
}
