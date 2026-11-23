<?php

namespace Tests\Unit\Notifications;

use App\Notifications\RequestSharedNotification;
use Tests\Support\NotificationContract;
use Tests\TestCase;

class RequestSharedNotificationTest extends TestCase
{
    public function test_it_uses_only_the_database_channel(): void
    {
        $this->assertSame(['database'], $this->notification()->via(null));
    }

    public function test_it_serializes_the_expected_database_payload(): void
    {
        $this->assertSame(
            NotificationContract::payload([
                'kind' => 'request_shared',
                'title' => 'Request shared: REQ-000123',
                'message' => 'Jane Doe shared request REQ-000123 with you.',
                'target' => 'shared_request',
                'status' => 'Completed',
                'approval_level' => null,
                'approval_step_type' => null,
            ]),
            $this->notification()->toDatabase(null),
        );
    }

    public function test_it_uses_the_shared_request_target_and_shared_kind(): void
    {
        $payload = $this->notification()->toDatabase(null);

        $this->assertSame('request_shared', $payload['kind']);
        $this->assertSame('shared_request', $payload['target']);
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

    private function notification(): RequestSharedNotification
    {
        return new RequestSharedNotification(
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: 'Completed',
            actorName: 'Jane Doe',
        );
    }
}
