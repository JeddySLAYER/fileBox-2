<?php

namespace App\Listeners\Document;

use App\Events\Document\DocumentAccepted;
use App\Models\User;
use App\Notifications\DocumentAcceptedNotification;
use Illuminate\Support\Facades\Notification;

class NotifyDocumentAccepted
{
    public function handle(DocumentAccepted $event): void
    {
        $document = $event->activitySubject();
        if (! $document) {
            return;
        }

        $ids = collect([$document->author_id, $document->owner_id])
            ->unique()
            ->reject(fn ($id) => $id === $event->activityUser()?->id)
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new DocumentAcceptedNotification($document),
        );
    }
}
