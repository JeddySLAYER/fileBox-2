<?php

namespace App\Listeners\Document;

use App\Events\Document\DocumentProposed;
use App\Models\User;
use App\Notifications\DocumentProposedAuthorNotification;
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
        $actorId = $event->activityUser()?->id;

        $managerIds = collect();

        if ($document->project?->manager_id) {
            $managerIds->push($document->project->manager_id);
        }

        $managerIds = $managerIds
            ->merge(
                User::query()
                    ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workflows.manage'))
                    ->pluck('id')
            )
            ->unique()
            ->reject(fn ($id) => $id === $actorId)
            ->filter()
            ->values();

        if ($managerIds->isNotEmpty()) {
            Notification::send(
                User::query()->whereIn('id', $managerIds)->get(),
                new DocumentProposedNotification($document),
            );
        }

        // Informer le collaborateur (auteur / propriétaire) que son dépôt est bien proposé.
        $authorIds = collect([$document->author_id, $document->owner_id])
            ->unique()
            ->filter()
            ->values();

        if ($authorIds->isNotEmpty()) {
            Notification::send(
                User::query()->whereIn('id', $authorIds)->get(),
                new DocumentProposedAuthorNotification($document),
            );
        }
    }
}
