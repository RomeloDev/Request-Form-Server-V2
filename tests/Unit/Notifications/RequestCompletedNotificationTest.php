<?php

namespace Tests\Unit\Notifications;

use App\Notifications\RequestCompletedNotification;
use Tests\Support\NotificationContract;
use Tests\TestCase;

class RequestCompletedNotificationTest extends TestCase
{
    public function test_it_uses_only_the_database_channel(): void
    {
        $this->assertSame(['database'], $this->notification()->via(null));
    }

    public function test_it_serializes_the_expected_database_payload(): void
    {
        $this->assertSame(
            NotificationContract::payload([
                'kind' => 'request_completed',
                'title' => 'Request completed: REQ-000123',
                'message' => 'Final approval has been recorded. Request REQ-000123 is now completed.',
                'status' => 'Completed',
                'approval_level' => 2,
            ]),
            $this->notification()->toDatabase(null),
        );
    }

    public function test_it_explains_that_final_approval_completed_the_request(): void
    {
        $payload = $this->notification()->toDatabase(null);

        $this->assertSame('request_completed', $payload['kind']);
        $this->assertSame('my_request', $payload['target']);
        $this->assertStringContainsString('Final approval', $payload['message']);
        $this->assertStringContainsString('completed', $payload['message']);
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

    private function notification(): RequestCompletedNotification
    {
        return new RequestCompletedNotification(
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: 'Completed',
            approvalLevel: 2,
            approvalStepType: 'approved_by',
            actorName: 'Jane Doe',
        );
    }
}
