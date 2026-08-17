<?php

namespace App\Listeners\Validation;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Events\Validation\ValidationActionTaken;
use App\Models\User;
use App\Models\Validation;
use App\Notifications\ValidationActionNotification;
use App\Support\ValidationActors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class NotifyValidationStakeholders
{
    public function handle(ValidationActionTaken $event): void
    {
        $document = $event->document->fresh() ?? $event->document;
        $validation = $event->validation->fresh(['workflowStep']) ?? $event->validation;
        $action = $event->notificationAction;

        if ($action === 'approved') {
            $this->notifyAfterApprove($document, $validation, $event->excludeUserId);

            return;
        }

        $ids = match ($action) {
            'started' => ValidationActors::stepResponsibleIds($validation)
                ->merge(ValidationActors::authorOwnerIds($document, $event->excludeUserId)),
            'rejected', 'correction_requested' => ValidationActors::authorOwnerIds($document, $event->excludeUserId)
                ->merge(ValidationActors::projectManagerIds($document))
                ->merge(ValidationActors::administratorIds()),
            default => ValidationActors::authorOwnerIds($document, $event->excludeUserId),
        };

        $this->sendTo($ids, $document, $validation, $action, $event->excludeUserId);
    }

    private function notifyAfterApprove($document, Validation $approved, ?int $excludeUserId): void
    {
        if ($document->status === DocumentStatus::Validated) {
            $this->sendTo(
                ValidationActors::authorOwnerIds($document, $excludeUserId)
                    ->merge(ValidationActors::projectManagerIds($document))
                    ->merge(ValidationActors::administratorIds()),
                $document,
                $approved,
                'completed',
                $excludeUserId,
            );

            return;
        }

        $next = Validation::query()
            ->where('document_id', $document->id)
            ->where('status', ValidationStatus::Pending)
            ->with('workflowStep')
            ->get()
            ->sortBy(fn (Validation $v) => $v->workflowStep?->step_order ?? 0)
            ->first();

        if ($next) {
            $nextAssignees = ValidationActors::stepResponsibleIds($next);
            $followers = ValidationActors::authorOwnerIds($document, $excludeUserId)
                ->merge(ValidationActors::projectManagerIds($document))
                ->merge(ValidationActors::administratorIds())
                ->reject(fn ($id) => $nextAssignees->contains((int) $id))
                ->values();

            // Suivi : auteur, chef projet, admin sont informés de l’avancement.
            $this->sendTo($followers, $document, $approved, 'approved', $excludeUserId);

            // Action : le validateur de l’étape suivante est sollicité.
            $this->sendTo($nextAssignees, $document, $next, 'started', $excludeUserId);

            return;
        }

        $this->sendTo(
            ValidationActors::authorOwnerIds($document, $excludeUserId),
            $document,
            $approved,
            'approved',
            $excludeUserId,
        );
    }

    /** @param  Collection<int, int>  $ids */
    private function sendTo(
        Collection $ids,
        $document,
        Validation $validation,
        string $action,
        ?int $excludeUserId,
    ): void {
        $ids = $ids
            ->unique()
            ->filter()
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($id) => $id === $excludeUserId))
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new ValidationActionNotification($document, $validation, $action),
        );
    }
}
