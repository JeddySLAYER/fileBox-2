<?php

namespace App\Listeners\Document;

use App\Events\Document\DocumentArchived;
use App\Models\User;
use App\Notifications\DocumentArchivedNotification;
use App\Support\ValidationActors;
use Illuminate\Support\Facades\Notification;

class NotifyDocumentArchived
{
    public function handle(DocumentArchived $event): void
    {
        $document = $event->activitySubject();
        if (! $document instanceof \App\Models\Document) {
            return;
        }

        $ids = ValidationActors::authorOwnerIds($document, $event->activityUser()?->id);
        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new DocumentArchivedNotification($document),
        );
    }
}
