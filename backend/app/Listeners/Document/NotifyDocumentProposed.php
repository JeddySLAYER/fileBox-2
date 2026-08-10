<?php

namespace App\Listeners\Document;

use App\Events\Document\DocumentProposed;
use App\Models\User;
use App\Notifications\DocumentProposedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyDocumentProposed
{
    public function handle(DocumentProposed $event): void
    {
        $document = $event->activitySubject();
        if (! $document) {
            return;
        }

        $document->loadMissing('project');

        $ids = collect();

        if ($document->project?->manager_id) {
            $ids->push($document->project->manager_id);
        }

        // Admins / gestionnaires workflow
        $managerIds = User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workflows.manage'))
            ->pluck('id');

        $ids = $ids->merge($managerIds)
            ->unique()
            ->reject(fn ($id) => $id === $event->activityUser()?->id)
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new DocumentProposedNotification($document),
        );
    }
}
