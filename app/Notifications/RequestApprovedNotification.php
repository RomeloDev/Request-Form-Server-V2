<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;

/**
 * Tells the requester that an approver approved their request.
 */
final class RequestApprovedNotification extends BaseDatabaseNotification
{
    public function __construct(
        int $requestId,
        string $requestCode,
        string $formType,
        string $status,
        int $approvalLevel,
        string $approvalStepType,
        string $actorName,
    ) {
        parent::__construct(self::makePayload(
            kind: NotificationKind::RequestApproved,
            target: NotificationTarget::MyRequest,
            title: "Request approved: {$requestCode}",
            message: "{$actorName} approved your request {$requestCode} at level {$approvalLevel}.",
            requestId: $requestId,
            requestCode: $requestCode,
            formType: $formType,
            status: $status,
            approvalLevel: $approvalLevel,
            approvalStepType: $approvalStepType,
            actorName: $actorName,
        ));
    }
}
