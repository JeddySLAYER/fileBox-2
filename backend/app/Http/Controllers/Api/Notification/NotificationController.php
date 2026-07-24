<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $this->notificationService->list(
            user: $request->user(),
            unreadOnly: $request->boolean('unread'),
            perPage: (int) $request->integer('per_page', 20),
        );

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->notificationService->unreadCount($request->user()),
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationService->markAsRead($request->user(), $id);

        return response()->json([
            'message' => 'Notification marquée comme lue.',
            'notification' => new NotificationResource($notification),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues.',
            'marked' => $count,
        ]);
    }
}
