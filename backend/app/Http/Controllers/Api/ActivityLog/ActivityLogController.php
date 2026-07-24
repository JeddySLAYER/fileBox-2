<?php

namespace App\Http\Controllers\Api\ActivityLog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLog\ActivityLogService;
use App\Support\ReportingScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('settings.manage') || $user->hasPermission('activity.view'),
            403
        );
        abort_unless((new ReportingScope($user))->canAccess(), 403);

        $logs = $this->activityLogService->list(
            actor: $user,
            filters: $request->only(['action', 'user_id', 'subject_type', 'search']),
            perPage: (int) $request->integer('per_page', 30),
        );

        return ActivityLogResource::collection($logs);
    }
}
