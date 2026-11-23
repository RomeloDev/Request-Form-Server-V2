<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;

/**
 * Tells the requester that an approver disapproved their request.
 */
final class RequestDisapprovedNotification extends BaseDatabaseNotification
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
            kind: NotificationKind::RequestDisapproved,
            target: NotificationTarget::MyRequest,
            title: "Request disapproved: {$requestCode}",
            message: "{$actorName} disapproved your request {$requestCode} at level {$approvalLevel}.",
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
