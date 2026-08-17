<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Notifications\Notification;

class DocumentProposedAuthorNotification extends Notification
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
            'type' => 'document.proposed.author',
            'title' => 'Document proposé à validation',
            'message' => "Votre document « {$this->document->title} » ({$this->document->reference}) a été proposé à validation. Un responsable décidera de l’accepter ou d’y assigner un workflow.",
            'document_id' => $this->document->id,
            'project_id' => $this->document->project_id,
        ];
    }
}
