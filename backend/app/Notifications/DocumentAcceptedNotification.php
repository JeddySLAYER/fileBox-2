<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

class DocumentAcceptedNotification extends Notification
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
            'type' => 'document.accepted',
            'title' => 'Proposition acceptée',
            'message' => "« {$this->document->title} » ({$this->document->reference}) a été accepté sans circuit de validation.",
            'document_id' => $this->document->id,
            'project_id' => $this->document->project_id,
        ];
    }
}
