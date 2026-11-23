<?php

namespace Tests\Unit\Notifications;

use App\Notifications\RequestApprovedNotification;
use Tests\Support\NotificationContract;
use Tests\TestCase;

class RequestApprovedNotificationTest extends TestCase
{
    public function test_it_uses_only_the_database_channel(): void
    {
        $this->assertSame(['database'], $this->notification()->via(null));
    }

    public function test_it_serializes_the_expected_database_payload(): void
    {
        $this->assertSame(
            NotificationContract::payload(),
            $this->notification()->toDatabase(null),
        );
    }

    public function test_it_uses_the_my_request_target_and_approved_kind(): void
    {
        $payload = $this->notification()->toDatabase(null);

        $this->assertSame('request_approved', $payload['kind']);
        $this->assertSame('my_request', $payload['target']);
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

    private function notification(): RequestApprovedNotification
    {
        return new RequestApprovedNotification(
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: 'Ongoing',
            approvalLevel: 1,
            approvalStepType: 'approved_by',
            actorName: 'Jane Doe',
        );
    }
}
