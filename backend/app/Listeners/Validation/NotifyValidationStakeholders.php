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
        $document = $event->document;
        $validation = $event->validation->fresh(['workflowStep']);
        $action = $event->notificationAction;

        $ids = match ($action) {
            'started' => ValidationActors::stepResponsibleIds($validation)
                ->merge(ValidationActors::authorOwnerIds($document, $event->excludeUserId)),
            'approved' => $this->afterApproveIds($document, $validation, $event->excludeUserId),
            'rejected', 'correction_requested' => ValidationActors::authorOwnerIds($document, $event->excludeUserId)
                ->merge(ValidationActors::projectManagerIds($document))
                ->merge(ValidationActors::administratorIds()),
            default => ValidationActors::authorOwnerIds($document, $event->excludeUserId),
        };

        $ids = $ids
            ->unique()
            ->filter()
            ->when($event->excludeUserId, fn ($c) => $c->reject(fn ($id) => $id === $event->excludeUserId))
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $notifyAction = $action;
        if ($action === 'approved' && $document->fresh()->status === DocumentStatus::Validated) {
            $notifyAction = 'completed';
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new ValidationActionNotification($document, $validation, $notifyAction),
        );
    }

    /** @return Collection<int, int> */
    private function afterApproveIds($document, Validation $validation, ?int $excludeUserId): Collection
    {
        $document = $document->fresh();

        if ($document->status === DocumentStatus::Validated) {
            return ValidationActors::authorOwnerIds($document, $excludeUserId)
                ->merge(ValidationActors::projectManagerIds($document));
        }

        $next = Validation::query()
            ->where('document_id', $document->id)
            ->where('status', ValidationStatus::Pending)
            ->with('workflowStep')
            ->get()
            ->sortBy(fn (Validation $v) => $v->workflowStep?->step_order ?? 0)
            ->first();

        if ($next) {
            return ValidationActors::stepResponsibleIds($next);
        }

        return ValidationActors::authorOwnerIds($document, $excludeUserId);
    }
}
