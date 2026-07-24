<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CommentPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return [
            'type' => 'comment.posted',
            'title' => 'Nouveau commentaire',
            'message' => "Nouveau commentaire sur « {$this->document->title} ».",
            'document_id' => $this->document->id,
            'comment_id' => $this->comment->id,
            'author_id' => $this->comment->user_id,
        ];
    }
}
