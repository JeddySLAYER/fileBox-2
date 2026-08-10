<?php

namespace App\Listeners\Document;

use App\Events\Document\DocumentPublished;
use App\Models\Access;
use App\Models\User;
use App\Notifications\DocumentPublishedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyDocumentPublished
{
    public function handle(DocumentPublished $event): void
    {
        $document = $event->activitySubject();
        if (! $document) {
            return;
        }

        $excludeId = $event->activityUser()?->id;

        $ids = collect([$document->author_id, $document->owner_id]);

        // Accès actifs sur le document (ou son dossier) = personnes concernées
        $accessQuery = Access::query()
            ->active()
            ->where(function ($q) use ($document) {
                $q->where(function ($q) use ($document) {
                    $q->where('accessible_type', $document->getMorphClass())
                        ->where('accessible_id', $document->id);
                });

                if ($document->folder_id) {
                    $q->orWhere(function ($q) use ($document) {
                        $q->where('accessible_type', 'folder')
                            ->where('accessible_id', $document->folder_id);
                    });
                }
            });

        $ids = $ids
            ->merge($accessQuery->pluck('user_id'))
            ->filter()
            ->unique()
            ->when($excludeId, fn ($c) => $c->reject(fn ($id) => $id === $excludeId))
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new DocumentPublishedNotification($document),
        );
    }
}
