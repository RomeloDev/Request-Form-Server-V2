<?php

namespace App\Support;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;
use InvalidArgumentException;

/**
 * Immutable server-side payload for a database notification.
 *
 * This is a deliberate allowlist: it carries only the fields the
 * notification UI needs. It never embeds Eloquent models, user ids, email
 * addresses, comments, attachments, storage paths, complete form data,
 * client-supplied URLs, or timestamps owned by the notifications table.
 */
final class NotificationPayload
{
    /**
     * @param  string  $title  Non-empty, server-generated heading.
     * @param  string  $message  Non-empty, server-generated body.
     * @param  int  $requestId  Positive request_forms.id.
     * @param  string  $requestCode  Non-empty human reference.
     * @param  string  $formType  Non-empty form type label.
     * @param  int|null  $approvalLevel  Positive chain level.
     */
    public function __construct(
        public readonly NotificationKind $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly NotificationTarget $target,
        public readonly int $requestId,
        public readonly string $requestCode,
        public readonly string $formType,
        public readonly ?string $status = null,
        public readonly ?int $approvalLevel = null,
        public readonly ?string $approvalStepType = null,
        public readonly ?string $actorName = null,
    ) {
        $expectedTarget = match ($kind) {
            NotificationKind::RequestActionRequired => NotificationTarget::AssignedRequest,
            NotificationKind::RequestApproved,
            NotificationKind::RequestDisapproved,
            NotificationKind::RequestCompleted => NotificationTarget::MyRequest,
            NotificationKind::RequestShared => NotificationTarget::SharedRequest,
        };

        if ($target !== $expectedTarget) {
            throw new InvalidArgumentException(
                "The {$kind->value} notification must use the {$expectedTarget->value} target."
            );
        }

        if ($requestId <= 0) {
            throw new InvalidArgumentException(
                'The request id must be a positive integer.'
            );
        }

        foreach ([
            'title' => $title,
            'message' => $message,
            'request code' => $requestCode,
            'form type' => $formType,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    "The {$field} must not be empty."
                );
            }
        }

        foreach ([
            'status' => $status,
            'approval step type' => $approvalStepType,
            'actor name' => $actorName,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException(
                    "The {$field} must be null or a non-empty string."
                );
            }
        }

        if ($approvalLevel !== null && $approvalLevel <= 0) {
            throw new InvalidArgumentException(
                'The approval level must be a positive integer.'
            );
        }

        /*
         * The approval level and step type describe the same chain position.
         * Accepting one without the other would produce an ambiguous payload.
         */
        if ($approvalLevel === null && $approvalStepType !== null) {
            throw new InvalidArgumentException(
                'The approval step type requires an approval level.'
            );
        }

        if ($approvalLevel !== null && $approvalStepType === null) {
            throw new InvalidArgumentException(
                'The approval level requires an approval step type.'
            );
        }
    }

    /**
     * Rebuild and validate a payload read from notifications.data.
     *
     * Database JSON is still untrusted at the API boundary: legacy rows,
     * manual edits, or corrupted data must not bypass the domain contract.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $kind = self::enumString($data, 'kind');
        $target = self::enumString($data, 'target');

        return new self(
            kind: NotificationKind::tryFrom($kind)
                ?? throw new InvalidArgumentException(
                    'The notification kind is invalid.'
                ),
            title: self::requiredString($data, 'title'),
            message: self::requiredString($data, 'message'),
            target: NotificationTarget::tryFrom($target)
                ?? throw new InvalidArgumentException(
                    'The notification target is invalid.'
                ),
            requestId: self::requiredInteger($data, 'request_id'),
            requestCode: self::requiredString($data, 'request_code'),
            formType: self::requiredString($data, 'form_type'),
            status: self::nullableString($data, 'status'),
            approvalLevel: self::nullableInteger(
                $data,
                'approval_level',
            ),
            approvalStepType: self::nullableString(
                $data,
                'approval_step_type',
            ),
            actorName: self::nullableString($data, 'actor_name'),
        );
    }

    /**
     * Serialize the contract to a plain array for notifications.data.
     *
     * Every key is present, including nullables, so consumers can rely on a
     * stable shape.
     *
     * @return array{
     *     kind: string,
     *     title: string,
     *     message: string,
     *     target: string,
     *     request_id: int,
     *     request_code: string,
     *     form_type: string,
     *     status: string|null,
     *     approval_level: int|null,
     *     approval_step_type: string|null,
     *     actor_name: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'title' => $this->title,
            'message' => $this->message,
            'target' => $this->target->value,
            'request_id' => $this->requestId,
            'request_code' => $this->requestCode,
            'form_type' => $this->formType,
            'status' => $this->status,
            'approval_level' => $this->approvalLevel,
            'approval_step_type' => $this->approvalStepType,
            'actor_name' => $this->actorName,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function enumString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "The {$key} field must be a string."
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(
        array $data,
        string $key,
    ): string {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "The {$key} field must be a string."
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(
        array $data,
        string $key,
    ): ?string {
        if (! array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "The {$key} field is required."
            );
        }

        $value = $data[$key];

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(
                "The {$key} field must be null or a string."
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredInteger(
        array $data,
        string $key,
    ): int {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException(
                "The {$key} field must be an integer."
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableInteger(
        array $data,
        string $key,
    ): ?int {
        if (! array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "The {$key} field is required."
            );
        }

        $value = $data[$key];

        if ($value !== null && ! is_int($value)) {
            throw new InvalidArgumentException(
                "The {$key} field must be null or an integer."
            );
        }

        return $value;
    }
}
