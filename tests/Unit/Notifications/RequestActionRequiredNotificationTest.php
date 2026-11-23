<?php

namespace Tests\Unit\Notifications;

use App\Notifications\RequestActionRequiredNotification;
use Tests\Support\NotificationContract;
use Tests\TestCase;

class RequestActionRequiredNotificationTest extends TestCase
{
    public function test_it_uses_only_the_database_channel(): void
    {
        $this->assertSame(['database'], $this->notification()->via(null));
    }

    public function test_it_serializes_the_expected_database_payload(): void
    {
        $this->assertSame(
            NotificationContract::payload([
                'kind' => 'request_action_required',
                'title' => 'Action required: REQ-000123',
                'message' => 'Request REQ-000123 is waiting for your approval at level 1.',
                'target' => 'assigned_request',
                'actor_name' => null,
            ]),
            $this->notification()->toDatabase(null),
        );
    }

    public function test_it_does_not_serialize_models_urls_comments_or_sensitive_fields(): void
    {
        $payload = $this->notification()->toDatabase(null);

        $this->assertSame(NotificationContract::KEYS, array_keys($payload));

        foreach ([
            'user_id',
            'url',
            'comment',
            'email',
            'notifiable_id',
            'notifiable_type',
            'type',
            'created_at',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    private function notification(): RequestActionRequiredNotification
    {
        return new RequestActionRequiredNotification(
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: 'Ongoing',
            approvalLevel: 1,
            approvalStepType: 'approved_by',
        );
    }
}
