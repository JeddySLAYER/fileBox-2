<?php

namespace App\Services\Validation;

use App\Enums\ValidationStatus;
use App\Models\User;
use App\Models\Validation;
use App\Notifications\ValidationReminderNotification;
use App\Support\ValidationActors;
use Illuminate\Support\Facades\Notification;

class ValidationReminderService
{
    /**
     * Envoie les rappels d’approche et de dépassement (une fois chacun).
     */
    public function sendDueReminders(): int
    {
        $count = 0;
        $now = now();

        Validation::query()
            ->where('status', ValidationStatus::Pending)
            ->whereNotNull('due_at')
            ->with(['document.project', 'workflowStep'])
            ->orderBy('id')
            ->chunkById(200, function ($batch) use (&$count, $now): void {
                foreach ($batch as $validation) {
                    if (! $validation->document) {
                        continue;
                    }

                    if ($this->notifyApproaching($validation, $now)) {
                        $count++;
                    }
                    if ($this->notifyOverdue($validation, $now)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function notifyApproaching(Validation $validation, $now): bool
    {
        if ($validation->approaching_notified_at || $validation->reminder_hours_before === null) {
            return false;
        }

        if ($validation->due_at->lte($now)) {
            return false;
        }

        $windowStart = $validation->due_at->copy()->subHours((int) $validation->reminder_hours_before);
        if ($now->lt($windowStart)) {
            return false;
        }

        $this->send($validation, 'approaching', includeManagers: false);
        $validation->approaching_notified_at = $now;
        $validation->save();

        return true;
    }

    private function notifyOverdue(Validation $validation, $now): bool
    {
        if ($validation->overdue_notified_at) {
            return false;
        }

        if ($validation->due_at->gt($now)) {
            return false;
        }

        $this->send($validation, 'overdue', includeManagers: true);
        $validation->overdue_notified_at = $now;
        $validation->save();

        return true;
    }

    private function send(Validation $validation, string $kind, bool $includeManagers): void
    {
        $ids = ValidationActors::stepResponsibleIds($validation);

        if ($includeManagers) {
            $ids = $ids
                ->merge(ValidationActors::projectManagerIds($validation->document))
                ->merge(ValidationActors::workflowManagerIds());
        }

        $ids = $ids->unique()->filter()->values();
        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new ValidationReminderNotification($validation->document, $validation, $kind),
        );
    }
}
