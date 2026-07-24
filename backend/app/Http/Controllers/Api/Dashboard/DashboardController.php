<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('dashboard.view'), 403);

        return response()->json([
            'dashboard' => $this->dashboardService->overview(),
        ]);
    }
}
