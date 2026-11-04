<?php

namespace App\Enums;

/**
 * Lifecycle states for one approval step.
 */
enum ApprovalStepStatus: string
{
    /**
     * The approver has not acted yet.
     */
    case Pending = 'Pending';

    /**
     * The approver accepted this step.
     */
    case Approved = 'Approved';

    /**
     * The approver rejected this request.
     */
    case Disapproved = 'Disapproved';

    /**
     * The step will not run because an earlier step ended the chain.
     */
    case Skipped = 'Skipped';

    /**
     * The requester canceled the request before this step ran.
     */
    case Canceled = 'Canceled';
}
