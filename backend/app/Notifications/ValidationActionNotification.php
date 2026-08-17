<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\Validation;
use App\Support\ValidationActors;
use Illuminate\Notifications\Notification;

class ValidationActionNotification extends Notification
{
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
            'started' => 'Validation requise',
            'approved' => 'Étape de validation approuvée',
            'rejected' => 'Document rejeté',
            'correction_requested' => 'Correction demandée',
            'completed' => 'Workflow de validation terminé',
        ];

        $this->validation->loadMissing('workflowStep');
        $stepName = $this->validation->workflowStep?->name;

        $isValidator = ValidationActors::stepResponsibleIds($this->validation)
            ->contains((int) $notifiable->id);

        if ($this->action === 'started' && ! $isValidator) {
            return [
                'type' => 'validation.started',
                'title' => 'Document en validation',
                'message' => "Le document « {$this->document->title} » a été envoyé en circuit de validation.",
                'document_id' => $this->document->id,
                'validation_id' => $this->validation->id,
                'status' => $this->validation->status?->value ?? $this->validation->status,
                'href' => "/documents/{$this->document->id}?tab=validations",
            ];
        }

        $message = match ($this->action) {
            'started' => "Votre action est attendue sur « {$this->document->title} »"
                .($stepName ? " (étape : {$stepName})" : '')
                .'. Consultez Validations → À suivre.',
            'approved' => "L’étape"
                .($stepName ? " « {$stepName} »" : '')
                ." de « {$this->document->title} » a été approuvée. Le circuit se poursuit — suivez l’évolution sur la fiche document.",
            'completed' => "Le document « {$this->document->title} » a été validé.",
            default => ($labels[$this->action] ?? 'Mise à jour de validation')." — « {$this->document->title} ».",
        };

        return [
            'type' => 'validation.'.$this->action,
            'title' => $labels[$this->action] ?? 'Mise à jour de validation',
            'message' => $message,
            'document_id' => $this->document->id,
            'validation_id' => $this->validation->id,
            'status' => $this->validation->status?->value ?? $this->validation->status,
            'href' => $this->action === 'started'
                ? '/validations?tab=suivre'
                : "/documents/{$this->document->id}?tab=validations",
        ];
    }
}
