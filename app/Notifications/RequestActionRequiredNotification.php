<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;

/**
 * Tells the first or next actionable approver that a request needs them.
 */
final class RequestActionRequiredNotification extends BaseDatabaseNotification
{
    public function __construct(
        int $requestId,
        string $requestCode,
        string $formType,
        string $status,
        int $approvalLevel,
        string $approvalStepType,
        ?string $actorName = null,
    ) {
        parent::__construct(self::makePayload(
            kind: NotificationKind::RequestActionRequired,
            target: NotificationTarget::AssignedRequest,
            title: "Action required: {$requestCode}",
            message: "Request {$requestCode} is waiting for your approval at level {$approvalLevel}.",
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
