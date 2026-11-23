<?php

namespace App\Enums;

/**
 * The domain events a user can be notified about.
 */
enum NotificationKind: string
{
    /**
     * An approver must act on a request assigned to them.
     */
    case RequestActionRequired = 'request_action_required';

    /**
     * A request moved forward because an approver approved it.
     */
    case RequestApproved = 'request_approved';

    /**
     * A request was rejected by an approver.
     */
    case RequestDisapproved = 'request_disapproved';

    /**
     * A request reached its terminal Completed state.
     */
    case RequestCompleted = 'request_completed';

    /**
     * A completed request was shared with the recipient.
     */
    case RequestShared = 'request_shared';
}
