<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\Validation;
use Illuminate\Notifications\Notification;

class ValidationReminderNotification extends Notification
{
    public function __construct(
        private readonly Document $document,
        private readonly Validation $validation,
        private readonly string $kind,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $stepName = $this->validation->workflowStep?->name ?? 'étape en cours';
        $due = $this->validation->due_at?->format('d/m/Y H:i');

        if ($this->kind === 'overdue') {
            $title = 'Échéance de validation dépassée';
            $message = "L’étape « {$stepName} » du document « {$this->document->title} » a dépassé son délai"
                .($due ? " (échéance {$due})" : '').'. Merci de traiter cette validation.';
        } else {
            $title = 'Rappel : validation bientôt due';
            $message = "L’étape « {$stepName} » du document « {$this->document->title} » arrive à échéance"
                .($due ? " le {$due}" : ' bientôt').'. Merci de la traiter.';
        }

        return [
            'type' => 'validation.reminder.'.$this->kind,
            'title' => $title,
            'message' => $message,
            'document_id' => $this->document->id,
            'validation_id' => $this->validation->id,
            'kind' => $this->kind,
            'due_at' => $this->validation->due_at?->toIso8601String(),
        ];
    }
}
