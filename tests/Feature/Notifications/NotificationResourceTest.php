<?php

namespace Tests\Feature\Notifications;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Notifications\RequestApprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const EXPECTED_KEYS = [
        'id',
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
        'read_at',
        'created_at',
    ];

    public function test_it_returns_only_the_safe_contract_with_iso_timestamps(): void
    {
        $notification = $this->sendApprovedNotification();

        $data = (new NotificationResource($notification))->toArray(request());

        $this->assertSame(self::EXPECTED_KEYS, array_keys($data));

        $this->assertSame($notification->id, $data['id']);
        $this->assertSame('request_approved', $data['kind']);
        $this->assertSame('Request approved: REQ-000123', $data['title']);
        $this->assertSame(
            'Jane Doe approved your request REQ-000123 at level 1.',
            $data['message'],
        );
        $this->assertSame('my_request', $data['target']);
        $this->assertSame(123, $data['request_id']);
        $this->assertSame('REQ-000123', $data['request_code']);
        $this->assertSame('Purchase Order Requisition Slip', $data['form_type']);
        $this->assertSame('Ongoing', $data['status']);
        $this->assertSame(1, $data['approval_level']);
        $this->assertSame('approved_by', $data['approval_step_type']);
        $this->assertSame('Jane Doe', $data['actor_name']);
        $this->assertNull($data['read_at']);
        $this->assertSame(
            $notification->created_at->toISOString(),
            $data['created_at'],
        );
    }

    public function test_it_formats_a_read_timestamp_as_iso_8601(): void
    {
        $notification = $this->sendApprovedNotification();

        $notification->markAsRead();
        $notification->refresh();

        $data = (new NotificationResource($notification))->toArray(request());

        $this->assertSame(
            $notification->read_at->toISOString(),
            $data['read_at'],
        );
    }

    public function test_it_never_exposes_internal_columns_or_class_names(): void
    {
        $notification = $this->sendApprovedNotification();

        $data = (new NotificationResource($notification))->toArray(request());
        $json = json_encode($data);

        $this->assertArrayNotHasKey('type', $data);
        $this->assertArrayNotHasKey('notifiable_type', $data);
        $this->assertArrayNotHasKey('notifiable_id', $data);
        $this->assertArrayNotHasKey('updated_at', $data);
        $this->assertArrayNotHasKey('data', $data);
        $this->assertStringNotContainsString('RequestApprovedNotification', (string) $json);
        $this->assertStringNotContainsString('DatabaseNotification', (string) $json);
    }

    public function test_it_does_not_expose_malformed_raw_payloads(): void
    {
        $user = $this->user();

        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\UnknownNotification',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => [
                'kind' => 'not_a_real_kind',
                'target' => 'not_a_real_target',
                'title' => 'Raw title',
                'url' => 'https://evil.example',
                'user_id' => 1,
                'comment' => 'raw comment',
                'disk' => 'local',
                'path' => 'request-forms/private.pdf',
                'storage_path' => 'private/request-forms/private.pdf',
                'attachment' => ['original_name' => 'private.pdf'],
            ],
            'read_at' => null,
        ]);

        $data = (new NotificationResource($notification))->toArray(request());

        $this->assertSame(self::EXPECTED_KEYS, array_keys($data));
        $this->assertNull($data['kind']);
        $this->assertNull($data['target']);
        $this->assertNull($data['title']);
        $this->assertNull($data['request_id']);
        $this->assertArrayNotHasKey('url', $data);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('comment', $data);
        $this->assertArrayNotHasKey('disk', $data);
        $this->assertArrayNotHasKey('path', $data);
        $this->assertArrayNotHasKey('storage_path', $data);
        $this->assertArrayNotHasKey('attachment', $data);
    }

    public function test_it_rejects_an_entire_payload_when_one_contract_field_is_malformed(): void
    {
        $user = $this->user();
        $validPayload = (new RequestApprovedNotification(
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: 'Ongoing',
            approvalLevel: 1,
            approvalStepType: 'approved_by',
            actorName: 'Jane Doe',
        ))->toDatabase(null);
        $validPayload['request_id'] = '123';

        $notification = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => RequestApprovedNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => $validPayload,
            'read_at' => null,
        ]);

        $data = (new NotificationResource($notification))->toArray(request());

        $this->assertNull($data['kind']);
        $this->assertNull($data['title']);
        $this->assertNull($data['request_id']);
        $this->assertNull($data['request_code']);
    }

    private function sendApprovedNotification(): DatabaseNotification
    {
        $user = $this->user();

        Notification::send($user, new RequestApprovedNotification(
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: 'Ongoing',
            approvalLevel: 1,
            approvalStepType: 'approved_by',
            actorName: 'Jane Doe',
        ));

        return $user->notifications()->firstOrFail();
    }

    /**
     * The users table requires registration fields beyond the factory defaults.
     */
    private function user(): User
    {
        return User::factory()->create([
            'firstName' => 'Notification',
            'lastName' => 'Recipient',
            'contact' => '09190001234',
            'position' => 'Employee',
            'userName' => fake()->unique()->userName(),
            'employee_id' => fake()->unique()->numerify('NOTIF-EMP-######'),
        ]);
    }
}
