<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;

/**
 * Tells a newly added recipient that a completed request was shared with them.
 */
final class RequestSharedNotification extends BaseDatabaseNotification
{
    public function __construct(
        int $requestId,
        string $requestCode,
        string $formType,
        string $status,
        string $actorName,
    ) {
        parent::__construct(self::makePayload(
            kind: NotificationKind::RequestShared,
            target: NotificationTarget::SharedRequest,
            title: "Request shared: {$requestCode}",
            message: "{$actorName} shared request {$requestCode} with you.",
            requestId: $requestId,
            requestCode: $requestCode,
            formType: $formType,
            status: $status,
            actorName: $actorName,
        ));
    }
}
