<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;

/**
 * Tells the requester that final approval completed their request.
 */
final class RequestCompletedNotification extends BaseDatabaseNotification
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
            kind: NotificationKind::RequestCompleted,
            target: NotificationTarget::MyRequest,
            title: "Request completed: {$requestCode}",
            message: "Final approval has been recorded. Request {$requestCode} is now completed.",
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
