<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Notifications\Notification;

// ponytail: sync — important notifs must land without queue worker
class CommentPostedNotification extends Notification
{
    public function __construct(
        private readonly Comment $comment,
        private readonly Document $document,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $isReply = $this->comment->parent_id !== null;

        return [
            'type' => $isReply ? 'comment.replied' : 'comment.posted',
            'title' => $isReply ? 'Réponse à votre commentaire' : 'Nouveau commentaire',
            'message' => $isReply
                ? "Quelqu'un a répondu à votre commentaire sur « {$this->document->title} »."
                : "Nouveau commentaire sur « {$this->document->title} ».",
            'document_id' => $this->document->id,
            'comment_id' => $this->comment->id,
            'parent_id' => $this->comment->parent_id,
            'author_id' => $this->comment->user_id,
        ];
    }
}
