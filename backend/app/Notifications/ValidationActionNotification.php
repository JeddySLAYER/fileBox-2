<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\Validation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ValidationActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Document $document,
        private readonly Validation $validation,
        private readonly string $action,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $labels = [
            'started' => 'Un workflow de validation a démarré',
            'approved' => 'Une étape de validation a été approuvée',
            'rejected' => 'Le document a été rejeté',
            'correction_requested' => 'Une correction a été demandée',
        ];

        return [
            'type' => 'validation.'.$this->action,
            'title' => $labels[$this->action] ?? 'Mise à jour de validation',
            'message' => ($labels[$this->action] ?? 'Mise à jour')." — « {$this->document->title} ».",
            'document_id' => $this->document->id,
            'validation_id' => $this->validation->id,
            'status' => $this->validation->status?->value ?? $this->validation->status,
        ];
    }
}
