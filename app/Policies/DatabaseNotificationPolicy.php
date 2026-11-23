<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class DatabaseNotificationPolicy
{
    /**
     * Every authenticated account may view its personal notifications.
     *
     * The target is always the framework's DatabaseNotification class, never
     * a client-supplied id. Ownership is enforced by the controller scoping
     * every query to $request->user()->notifications().
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Every authenticated account may view its own unread count.
     *
     * The count is always derived from the authenticated user's
     * unreadNotifications relationship.
     */
    public function viewUnreadCount(User $user): bool
    {
        return true;
    }

    /**
     * Every authenticated account may mark only its own notification read.
     *
     * The controller resolves the record through the authenticated user's
     * relationship first, so a foreign UUID is a 404 before this check. This
     * ability repeats the ownership test as defense in depth.
     */
    public function markAsRead(
        User $user,
        DatabaseNotification $notification,
    ): bool {
        return $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === (string) $user->getKey();
    }

    /**
     * Every authenticated account may mark its own unread rows read.
     *
     * The update is always scoped to the authenticated user's
     * unreadNotifications relationship.
     */
    public function markAllAsRead(User $user): bool
    {
        return true;
    }
}
