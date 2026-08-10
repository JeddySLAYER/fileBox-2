<?php

namespace App\Listeners\Access;

use App\Events\Access\AccessGranted;
use App\Notifications\AccessGrantedNotification;

class NotifyAccessGranted
{
    public function handle(AccessGranted $event): void
    {
        $event->access->user->notify(new AccessGrantedNotification($event->access));
    }
}
