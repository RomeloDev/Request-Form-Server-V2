<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\RequestApprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationMutationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_guest_cannot_mark_one_notification_read(): void
    {
        $this->patchJson(
            '/api/v1/notifications/'.Str::uuid().'/read',
        )->assertUnauthorized();
    }

    public function test_guest_cannot_mark_all_notifications_read(): void
    {
        $this->patchJson('/api/v1/notifications/read-all')
            ->assertUnauthorized();
    }

    public function test_admin_approver_and_user_can_mark_their_own_notification_read(): void
    {
        foreach (['Admin', 'User', 'approver'] as $index => $role) {
            $user = $this->user($role);
            $notification = $this->notify($user, "ROLE-{$index}");

            Sanctum::actingAs($user);

            $this->patchJson("/api/v1/notifications/{$notification->id}/read")
                ->assertOk()
                ->assertJsonPath('data.id', $notification->id)
                ->assertJsonPath('message', 'Notification marked as read.');

            $this->assertNotNull($notification->fresh()->read_at);
        }
    }

    public function test_authenticated_user_without_a_role_can_mark_only_their_notifications_read(): void
    {
        $user = $this->userWithoutRole();
        $other = $this->user('User');
        $one = $this->notify($user, 'NO-ROLE-ONE');
        $two = $this->notify($user, 'NO-ROLE-TWO');
        $foreign = $this->notify($other, 'FOREIGN');

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/notifications/{$one->id}/read")
            ->assertOk();

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertNotNull($one->fresh()->read_at);
        $this->assertNotNull($two->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_owner_can_mark_a_notification_read_and_response_is_safe(): void
    {
        $user = $this->user('User');
        $notification = $this->notify($user, 'SAFE-READ-001');

        Sanctum::actingAs($user);

        $response = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.request_code', 'SAFE-READ-001')
            ->assertJsonPath('message', 'Notification marked as read.')
            ->assertJsonMissingPath('data.type')
            ->assertJsonMissingPath('data.notifiable_type')
            ->assertJsonMissingPath('data.notifiable_id')
            ->assertJsonMissingPath('data.updated_at')
            ->assertJsonMissingPath('data.data');

        $this->assertNotNull($response->json('data.read_at'));

        $this->assertSame([
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
        ], array_keys($response->json('data')));

        $this->assertStringNotContainsString(
            'RequestApprovedNotification',
            (string) $response->getContent(),
        );
    }

    public function test_marking_another_users_notification_returns_not_found(): void
    {
        $owner = $this->user('User');
        $attacker = $this->user('User');
        $notification = $this->notify($owner, 'FOREIGN-001');

        Sanctum::actingAs($attacker);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertNotFound()
            ->assertJsonPath('message', 'Notification not found.');

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_unknown_and_malformed_identifiers_return_not_found(): void
    {
        $user = $this->user('User');

        Sanctum::actingAs($user);

        $this->patchJson(
            '/api/v1/notifications/'.Str::uuid().'/read',
        )->assertNotFound()
            ->assertJsonPath('message', 'Notification not found.');

        $this->patchJson('/api/v1/notifications/not-a-uuid/read')
            ->assertNotFound()
            ->assertJsonPath('message', 'Notification not found.');
    }

    public function test_marking_the_same_notification_twice_is_idempotent(): void
    {
        $user = $this->user('User');
        $notification = $this->notify($user, 'IDEMPOTENT-001');

        Sanctum::actingAs($user);

        $first = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
        )->assertOk();

        $firstReadAt = $first->json('data.read_at');

        $second = $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
        )->assertOk();

        $this->assertNotNull($firstReadAt);
        $this->assertSame($firstReadAt, $second->json('data.read_at'));
        $this->assertSame(
            $firstReadAt,
            $notification->fresh()->read_at?->toISOString(),
        );
    }

    public function test_mark_all_updates_only_the_authenticated_users_unread_rows(): void
    {
        $owner = $this->user('User');
        $other = $this->user('User');

        $this->notify($owner, 'OWNER-UNREAD-1');
        $this->notify($owner, 'OWNER-UNREAD-2');
        $ownerRead = $this->notify($owner, 'OWNER-READ-1');
        $ownerRead->markAsRead();

        $otherUnread = $this->notify($other, 'OTHER-UNREAD-1');
        $otherRead = $this->notify($other, 'OTHER-READ-1');
        $otherRead->markAsRead();
        $otherReadAt = $otherRead->fresh()->read_at?->toISOString();

        Sanctum::actingAs($owner);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', 'All notifications marked as read.')
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $other->fresh()->unreadNotifications()->count());
        $this->assertNull($otherUnread->fresh()->read_at);
        $this->assertSame(
            $otherReadAt,
            $otherRead->fresh()->read_at?->toISOString(),
        );
    }

    public function test_mark_all_returns_zero_when_nothing_is_unread(): void
    {
        $user = $this->user('User');
        $notification = $this->notify($user, 'ALREADY-READ-001');
        $notification->markAsRead();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 0);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 0);
    }

    public function test_prohibited_ownership_and_state_fields_are_rejected(): void
    {
        $user = $this->user('User');
        $notification = $this->notify($user, 'PROHIBITED-001');

        Sanctum::actingAs($user);

        $payload = [
            'user_id' => 1,
            'notification_id' => $notification->id,
            'notifiable_id' => 1,
            'notifiable_type' => User::class,
            'read_at' => '2020-01-01T00:00:00Z',
            'kind' => 'request_approved',
            'type' => 'some-type',
            'data' => ['kind' => 'request_approved'],
            'role' => 'Admin',
        ];

        $errors = [
            'user_id',
            'notification_id',
            'notifiable_id',
            'notifiable_type',
            'read_at',
            'kind',
            'type',
            'data',
            'role',
        ];

        $this->patchJson(
            "/api/v1/notifications/{$notification->id}/read",
            $payload,
        )->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->patchJson('/api/v1/notifications/read-all', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertNull($notification->fresh()->read_at);
    }

    /**
     * Store one contract notification for the given user.
     */
    private function notify(
        User $user,
        string $requestCode,
    ): DatabaseNotification {
        $notification = new RequestApprovedNotification(
            requestId: 1,
            requestCode: $requestCode,
            formType: 'Purchase Order Requisition Slip',
            status: 'Ongoing',
            approvalLevel: 1,
            approvalStepType: 'approved_by',
            actorName: 'Jane Doe',
        );

        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'data' => $notification->toDatabase(null),
            'read_at' => null,
        ]);
    }

    private function user(string $role): User
    {
        $user = $this->userWithoutRole();

        $user->assignRole($role);

        return $user;
    }

    private function userWithoutRole(): User
    {
        $number = ++$this->sequence;

        return User::factory()->create([
            'firstName' => "Mutation{$number}",
            'lastName' => 'Recipient',
            'contact' => "0919000{$number}",
            'position' => 'Employee',
            'userName' => "mutations-{$number}",
            'employee_id' => "NOTIF-MUT-{$number}",
        ]);
    }
}
