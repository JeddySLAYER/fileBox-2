<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Support\ReportingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasPermission('dashboard.view') && (new ReportingScope($user))->canAccess()) {
            return response()->json([
                'dashboard' => $this->dashboardService->overview($user),
            ]);
        }

        return response()->json([
            'dashboard' => $this->dashboardService->home($user),
        ]);
    }
}
