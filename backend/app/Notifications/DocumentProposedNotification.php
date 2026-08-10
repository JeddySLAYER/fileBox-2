<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

class DocumentProposedNotification extends Notification
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
            'type' => 'document.proposed',
            'title' => 'Document proposé à validation',
            'message' => "« {$this->document->title} » ({$this->document->reference}) a été proposé et attend un démarrage de workflow.",
            'document_id' => $this->document->id,
            'project_id' => $this->document->project_id,
        ];
    }
}
