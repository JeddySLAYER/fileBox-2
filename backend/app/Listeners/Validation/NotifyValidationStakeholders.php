<?php

namespace App\Listeners\Validation;

use App\Enums\DocumentStatus;
use App\Enums\ValidationStatus;
use App\Events\Validation\ValidationActionTaken;
use App\Models\User;
use App\Models\Validation;
use App\Notifications\ValidationActionNotification;
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
            'started' => $this->stepResponsibleIds($validation),
            'approved' => $this->afterApproveIds($document, $validation, $event->excludeUserId),
            'rejected', 'correction_requested' => $this->authorOwnerIds($document, $event->excludeUserId),
            default => $this->authorOwnerIds($document, $event->excludeUserId),
        };

        $ids = $ids->unique()->filter()->values();

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
    private function authorOwnerIds($document, ?int $excludeUserId): Collection
    {
        return collect([$document->author_id, $document->owner_id])
            ->filter()
            ->unique()
            ->when($excludeUserId, fn ($c) => $c->reject(fn ($id) => $id === $excludeUserId))
            ->values();
    }

    /** @return Collection<int, int> */
    private function stepResponsibleIds(Validation $validation): Collection
    {
        $step = $validation->workflowStep;
        $ids = collect();

        if ($step?->responsible_user_id) {
            $ids->push($step->responsible_user_id);
        }

        if ($step?->responsible_role_id) {
            $ids = $ids->merge(
                User::query()
                    ->whereHas('roles', fn ($q) => $q->where('roles.id', $step->responsible_role_id))
                    ->pluck('id')
            );
        }

        // Fallback : acteurs validations.act si aucune responsabilité ciblée
        if ($ids->isEmpty()) {
            $ids = User::query()
                ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'validations.act'))
                ->pluck('id');
        }

        return $ids->unique()->filter()->values();
    }

    /** @return Collection<int, int> */
    private function afterApproveIds($document, Validation $validation, ?int $excludeUserId): Collection
    {
        $document = $document->fresh();

        if ($document->status === DocumentStatus::Validated) {
            return $this->authorOwnerIds($document, $excludeUserId);
        }

        $next = Validation::query()
            ->where('document_id', $document->id)
            ->where('status', ValidationStatus::Pending)
            ->with('workflowStep')
            ->get()
            ->sortBy(fn (Validation $v) => $v->workflowStep?->step_order ?? 0)
            ->first();

        if ($next) {
            return $this->stepResponsibleIds($next);
        }

        return $this->authorOwnerIds($document, $excludeUserId);
    }
}
