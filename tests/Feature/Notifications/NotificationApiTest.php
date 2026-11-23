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

class NotificationApiTest extends TestCase
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

    public function test_guest_cannot_list_notifications(): void
    {
        $this->getJson('/api/v1/notifications')
            ->assertUnauthorized();
    }

    public function test_guest_cannot_read_unread_count(): void
    {
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertUnauthorized();
    }

    public function test_admin_approver_and_user_can_each_access_their_own_notifications(): void
    {
        foreach (['Admin', 'User', 'approver'] as $index => $role) {
            $user = $this->user($role);
            $this->notify($user, "ROLE-{$index}", '2026-11-20 08:00:00');

            Sanctum::actingAs($user);

            $this->getJson('/api/v1/notifications')
                ->assertOk()
                ->assertJsonPath('data.0.request_code', "ROLE-{$index}");

            $this->getJson('/api/v1/notifications/unread-count')
                ->assertOk()
                ->assertExactJson(['data' => ['unread_count' => 1]]);
        }
    }

    public function test_authenticated_user_without_a_role_can_access_only_their_own_notifications(): void
    {
        $user = $this->userWithoutRole();
        $other = $this->user('User');

        $this->notify($user, 'NO-ROLE-001', '2026-11-20 08:00:00');
        $this->notify($other, 'OTHER-001', '2026-11-20 09:00:00');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_code', 'NO-ROLE-001');

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 1]]);
    }

    public function test_user_sees_only_their_own_notifications(): void
    {
        $owner = $this->user('User');
        $other = $this->user('User');

        $this->notify($owner, 'OWNER-001', '2026-11-20 08:00:00');
        $this->notify($other, 'OTHER-001', '2026-11-20 09:00:00');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/notifications');

        $codes = collect($response->json('data'))
            ->pluck('request_code')
            ->all();

        $this->assertSame(['OWNER-001'], $codes);
    }

    public function test_notifications_are_ordered_newest_first(): void
    {
        $user = $this->user('User');

        $this->notify($user, 'ORDER-OLD', '2026-11-20 08:00:00');
        $this->notify($user, 'ORDER-MIDDLE', '2026-11-20 09:00:00');
        $this->notify($user, 'ORDER-NEW', '2026-11-20 10:00:00');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $this->assertSame(
            ['ORDER-NEW', 'ORDER-MIDDLE', 'ORDER-OLD'],
            collect($response->json('data'))
                ->pluck('request_code')
                ->all(),
        );
    }

    public function test_default_pagination_returns_ten_per_page(): void
    {
        $user = $this->user('User');

        for ($index = 1; $index <= 12; $index++) {
            $this->notify(
                $user,
                sprintf('PAGE-%03d', $index),
                sprintf('2026-11-20 %02d:00:00', $index),
            );
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'links',
                    'path',
                    'per_page',
                    'to',
                    'total',
                ],
                'message',
            ]);
    }

    public function test_allowed_per_page_values_are_accepted(): void
    {
        $user = $this->user('User');

        for ($index = 1; $index <= 12; $index++) {
            $this->notify(
                $user,
                sprintf('PERPAGE-%03d', $index),
                sprintf('2026-11-20 %02d:00:00', $index),
            );
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(12, 'data');

        $this->getJson('/api/v1/notifications?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(12, 'data');

        $this->getJson('/api/v1/notifications?page=2&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_unsupported_and_above_cap_pagination_values_are_rejected(): void
    {
        $user = $this->user('User');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications?per_page=15')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/notifications?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/notifications?per_page=5000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/v1/notifications?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    public function test_unread_only_filters_to_unread_notifications(): void
    {
        $user = $this->user('User');

        $this->notify($user, 'UNREAD-001', '2026-11-20 08:00:00');
        $read = $this->notify($user, 'READ-001', '2026-11-20 09:00:00');
        $this->notify($user, 'UNREAD-002', '2026-11-20 10:00:00');

        $read->markAsRead();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications?unread_only=true')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.request_code', 'UNREAD-002')
            ->assertJsonPath('data.1.request_code', 'UNREAD-001');
    }

    public function test_unread_only_false_and_omitted_return_all_notifications(): void
    {
        $user = $this->user('User');

        $this->notify($user, 'ALL-001', '2026-11-20 08:00:00');
        $read = $this->notify($user, 'ALL-002', '2026-11-20 09:00:00');

        $read->markAsRead();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications?unread_only=false')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_unread_count_is_accurate_and_returns_no_rows(): void
    {
        $user = $this->user('User');

        $this->notify($user, 'COUNT-001', '2026-11-20 08:00:00');
        $read = $this->notify($user, 'COUNT-002', '2026-11-20 09:00:00');
        $this->notify($user, 'COUNT-003', '2026-11-20 10:00:00');

        $read->markAsRead();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications/unread-count');

        $response
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 2]]);

        $this->assertSame(['data'], array_keys($response->json()));
    }

    public function test_prohibited_identity_fields_are_rejected(): void
    {
        $user = $this->user('User');

        Sanctum::actingAs($user);

        $query = 'user_id=1&notifiable_id=1&notifiable_type=App%5CModels%5CUser'
            .'&role=Admin&type=some-type';

        $this->getJson("/api/v1/notifications?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'notifiable_id',
                'notifiable_type',
                'role',
                'type',
            ]);

        $this->getJson("/api/v1/notifications/unread-count?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'notifiable_id',
                'notifiable_type',
                'role',
                'type',
            ]);
    }

    public function test_resource_output_does_not_expose_internal_columns_or_raw_data(): void
    {
        $user = $this->user('User');

        $this->notify($user, 'SAFE-001', '2026-11-20 08:00:00');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->notifications()->firstOrFail()->id)
            ->assertJsonMissingPath('data.0.type')
            ->assertJsonMissingPath('data.0.notifiable_type')
            ->assertJsonMissingPath('data.0.notifiable_id')
            ->assertJsonMissingPath('data.0.updated_at')
            ->assertJsonMissingPath('data.0.data')
            ->assertJsonMissingPath('data.0.raw');

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
        ], array_keys($response->json('data.0')));

        $this->assertStringNotContainsString(
            'RequestApprovedNotification',
            (string) $response->getContent(),
        );
    }

    /**
     * Store one contract notification for the given user with a known time.
     */
    private function notify(
        User $user,
        string $requestCode,
        string $createdAt,
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
            'created_at' => $createdAt,
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
            'firstName' => "Notification{$number}",
            'lastName' => 'Recipient',
            'contact' => "0919000{$number}",
            'position' => 'Employee',
            'userName' => "notifications-{$number}",
            'employee_id' => "NOTIF-EMP-{$number}",
        ]);
    }
}
