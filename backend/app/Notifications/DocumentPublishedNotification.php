<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

// ponytail: sync — important notifs must land without queue worker
class DocumentPublishedNotification extends Notification
{
    public function __construct(
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
            'type' => 'document.published',
            'title' => 'Document publié',
            'message' => "« {$this->document->title} » ({$this->document->reference}) a été publié.",
            'document_id' => $this->document->id,
        ];
    }
}
