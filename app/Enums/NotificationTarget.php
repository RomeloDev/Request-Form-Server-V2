<?php

namespace App\Enums;

/**
 * Which request list a notification belongs to.
 */
enum NotificationTarget: string
{
    /**
     * The user submitted the request.
     */
    case MyRequest = 'my_request';

    /**
     * The user is expected to approve the request.
     */
    case AssignedRequest = 'assigned_request';

    /**
     * The request was shared with the user.
     */
    case SharedRequest = 'shared_request';
}
