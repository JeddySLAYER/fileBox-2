<?php

namespace App\Http\Controllers\Api\ActivityLog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLog\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(
            $request->user()->hasPermission('settings.manage')
            || $request->user()->hasPermission('dashboard.view'),
            403
        );

        $logs = $this->activityLogService->list(
            filters: $request->only(['action', 'user_id', 'subject_type', 'search']),
            perPage: (int) $request->integer('per_page', 30),
        );

        return ActivityLogResource::collection($logs);
    }

    /**
     * Queue des logs techniques Laravel (laravel.log).
     */
    public function system(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);

        $lines = max(10, min(500, (int) $request->integer('lines', 100)));

        return response()->json([
            'lines' => $this->activityLogService->tailSystemLog($lines),
        ]);
    }
}
