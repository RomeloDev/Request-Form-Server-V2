<?php

namespace App\Enums;

/**
 * Describes why a user appears in a request's approval chain.
 */
enum ApprovalStepType: string
{
    /**
     * Reviews or acknowledges the request before formal approval.
     */
    case NotedBy = 'noted_by';

    /**
     * Provides formal approval for the request.
     */
    case ApprovedBy = 'approved_by';

    /**
     * Processes the request on behalf of an AVP Finance approver.
     *
     * This step is inserted by the backend from an AVP Staff assignment.
     * The requester does not select it manually.
     */
    case DelegatedApproval = 'delegated_approval';
}
