<?php

namespace Tests\Support;

/**
 * Shared expectations for the Prompt 1 notification data contract.
 */
final class NotificationContract
{
    /**
     * The exact serialized key order.
     *
     * @var list<string>
     */
    public const KEYS = [
        'kind',
        'title',
        'message',
        'target',
        'request_id',
        'request_code',
        'form_type',
        'status',
        'approval_level',
        'approval_step_type',
        'actor_name',
    ];

    /**
     * A complete valid payload for the approved kind, overridable in tests.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'request_approved',
            'title' => 'Request approved: REQ-000123',
            'message' => 'Jane Doe approved your request REQ-000123 at level 1.',
            'target' => 'my_request',
            'request_id' => 123,
            'request_code' => 'REQ-000123',
            'form_type' => 'Purchase Order Requisition Slip',
            'status' => 'Ongoing',
            'approval_level' => 1,
            'approval_step_type' => 'approved_by',
            'actor_name' => 'Jane Doe',
        ], $overrides);
    }
}
