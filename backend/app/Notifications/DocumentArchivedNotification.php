<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

class DocumentArchivedNotification extends Notification
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
            'type' => 'document.archived',
            'title' => 'Document archivé',
            'message' => "« {$this->document->title} » ({$this->document->reference}) a été archivé.",
            'document_id' => $this->document->id,
        ];
    }
}
