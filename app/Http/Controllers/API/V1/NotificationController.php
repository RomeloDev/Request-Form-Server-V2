<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListNotificationsRequest;
use App\Http\Requests\Notifications\MarkAllNotificationsReadRequest;
use App\Http\Requests\Notifications\MarkNotificationReadRequest;
use App\Http\Requests\Notifications\UnreadNotificationCountRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * Return the authenticated user's notifications, newest first.
     *
     * The identity is always the authenticated user; no client input can
     * widen the query to another notifiable.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $filters = $request->validated();

        $unreadOnly = filter_var(
            $filters['unread_only'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        $notifications = $request->user()
            ->notifications()
            ->when(
                $unreadOnly,
                fn ($query) => $query->whereNull('read_at'),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional([
                'message' => 'Notifications retrieved successfully.',
            ])
            ->response();
    }

    /**
     * Return only the authenticated user's unread notification count.
     */
    public function unreadCount(UnreadNotificationCountRequest $request): JsonResponse
    {
        $this->authorize('viewUnreadCount', DatabaseNotification::class);

        return response()->json([
            'data' => [
                'unread_count' => $request->user()
                    ->unreadNotifications()
                    ->count(),
            ],
        ]);
    }

    /**
     * Mark one of the authenticated user's notifications as read.
     *
     * The UUID is resolved only through the authenticated user's
     * relationship, so another user's id is indistinguishable from a
     * missing one. The conditional update is atomic, so simultaneous calls
     * from multiple tabs cannot replace the first read_at value.
     */
    public function markAsRead(
        MarkNotificationReadRequest $request,
        string $notification,
    ): JsonResponse {
        /*
         * Reject malformed identifiers before touching the database. This
         * keeps the 404 identical to an unknown id and avoids driver-specific
         * UUID errors.
         */
        if (! Str::isUuid($notification)) {
            return response()->json(
                ['message' => 'Notification not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $record = $request->user()
            ->notifications()
            ->find($notification);

        if (! $record instanceof DatabaseNotification) {
            return response()->json(
                ['message' => 'Notification not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->authorize('markAsRead', $record);

        $request->user()
            ->notifications()
            ->whereKey($record->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $record->refresh();

        return (new NotificationResource($record))
            ->additional([
                'message' => 'Notification marked as read.',
            ])
            ->response();
    }

    /**
     * Mark all of the authenticated user's unread notifications as read.
     *
     * The update starts from unreadNotifications(), so it can never touch
     * another user's rows or overwrite an existing read_at value.
     */
    public function markAllAsRead(
        MarkAllNotificationsReadRequest $request,
    ): JsonResponse {
        $this->authorize('markAllAsRead', DatabaseNotification::class);

        $updated = $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }
}
