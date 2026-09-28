<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List the authenticated user's notifications with the unread badge
     * count in one round trip (powers the header bell + dropdown).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $this->notifications->forUser($user, perPage: (int) $request->input('per_page', 20));

        return ApiResponse::success([
            'notifications' => NotificationResource::collection($page)->toArray($request),
            'unread_count' => $this->notifications->unreadCount($user),
        ]);
    }

    /**
     * Mark a notification as read (idempotent).
     */
    public function read(Request $request, Notification $notification): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $notification);

        $notification = $this->notifications->markAsRead($notification);

        return ApiResponse::success([
            'notification' => new NotificationResource($notification),
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * Delete a notification (dismiss from the bell dropdown).
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $notification);

        $notification->delete();

        return ApiResponse::success([
            'message' => 'Notification deleted.',
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }
}
