<?php

namespace App\Listeners;

use App\Contracts\LogsActivity;
use App\Services\ActivityLog\ActivityLogService;

class RecordActivityLog
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function handle(LogsActivity $event): void
    {
        $this->activityLog->log(
            action: $event->activityAction(),
            user: $event->activityUser(),
            subject: $event->activitySubject(),
            description: $event->activityDescription(),
            properties: $event->activityProperties(),
        );
    }
}
