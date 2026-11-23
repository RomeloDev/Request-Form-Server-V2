<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;
use App\Support\NotificationPayload;
use Illuminate\Notifications\Notification;

/**
 * Base class for workflow notifications stored in the database.
 *
 * Concrete notifications own their meaning (kind, target, title, and
 * message) and receive only immutable scalar snapshot data. The payload is
 * built through the Prompt 1 contract, so every stored notification has the
 * same stable data shape.
 */
abstract class BaseDatabaseNotification extends Notification
{
    public function __construct(
        private readonly NotificationPayload $payload,
    ) {}

    /**
     * Keep database delivery exclusive; mail uses a separate notification.
     *
     * @return list<string>
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * The exact Prompt 1 contract, suitable for the notifications.data column.
     *
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        return $this->payload->toArray();
    }

    public function payload(): NotificationPayload
    {
        return $this->payload;
    }

    /**
     * Build the shared contract payload.
     */
    protected static function makePayload(
        NotificationKind $kind,
        NotificationTarget $target,
        string $title,
        string $message,
        int $requestId,
        string $requestCode,
        string $formType,
        ?string $status = null,
        ?int $approvalLevel = null,
        ?string $approvalStepType = null,
        ?string $actorName = null,
    ): NotificationPayload {
        return new NotificationPayload(
            kind: $kind,
            title: $title,
            message: $message,
            target: $target,
            requestId: $requestId,
            requestCode: $requestCode,
            formType: $formType,
            status: $status,
            approvalLevel: $approvalLevel,
            approvalStepType: $approvalStepType,
            actorName: $actorName,
        );
    }
}
