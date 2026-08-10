<?php

namespace App\Listeners\Access;

use App\Events\Access\AccessRevoked;
use App\Notifications\AccessRevokedNotification;

class NotifyAccessRevoked
{
    public function handle(AccessRevoked $event): void
    {
        $access = $event->access;
        $access->loadMissing(['user', 'accessible']);

        if (! $access->user) {
            return;
        }

        $access->user->notify(new AccessRevokedNotification($access));
    }
}
