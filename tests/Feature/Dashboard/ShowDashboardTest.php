<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShowDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/dashboard';

    /**
     * Keys that must never appear anywhere in the dashboard payload.
     *
     * Keeping the deny-list recursive means a future nested field cannot
     * quietly leak an internal identifier or private relationship.
     */
    private const FORBIDDEN_KEYS = [
        'user_id',
        'requester_id',
        'approver_id',
        'approval_step_id',
        'step_id',
        'form_data',
        'grand_total',
        'comment',
        'comments',
        'attachments',
        'signature',
        'signatures',
        'profile_picture',
        'profile_picture_path',
        'role',
        'roles',
        'permission',
        'permissions',
        'branch_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_admin_can_access_the_dashboard(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        $response = $this->getJson(self::ENDPOINT);

        $this->assertDashboardContract($response);
        $response->assertJsonPath('data.approval', null);
    }

    public function test_user_can_access_the_dashboard(): void
    {
        Sanctum::actingAs($this->user('User'));

        $response = $this->getJson(self::ENDPOINT);

        $this->assertDashboardContract($response);
        $response->assertJsonPath('data.approval', null);
    }

    public function test_approver_can_access_the_dashboard(): void
    {
        Sanctum::actingAs($this->user('approver'));

        $response = $this->getJson(self::ENDPOINT);

        $this->assertDashboardContract($response);

        $response
            ->assertJsonStructure([
                'data' => [
                    'approval' => [
                        'summary' => [
                            'total_assigned',
                            'approved',
                            'awaiting_action',
                            'disapproved',
                            'closed_without_action',
                        ],
                        'activity' => [
                            'monthly_decisions',
                            'weekly_assignments',
                        ],
                    ],
                ],
            ])
            ->assertJsonCount(
                12,
                'data.approval.activity.monthly_decisions',
            )
            ->assertJsonCount(
                7,
                'data.approval.activity.weekly_assignments',
            );

        $this->assertSame([
            'total_assigned' => 0,
            'approved' => 0,
            'awaiting_action' => 0,
            'disapproved' => 0,
            'closed_without_action' => 0,
        ], $response->json('data.approval.summary'));
    }

    public function test_account_without_an_approved_application_role_is_forbidden(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    #[DataProvider('prohibitedParameters')]
    public function test_identity_authorization_and_scoping_parameters_are_rejected(
        string $parameter,
    ): void {
        Sanctum::actingAs($this->user('User'));

        $this->getJson(
            self::ENDPOINT.'?'.http_build_query([$parameter => '1']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($parameter);
    }

    public function test_response_uses_one_envelope_and_only_allowlisted_fields(): void
    {
        Sanctum::actingAs($this->user('approver'));

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $payload = $response->json();

        $this->assertKeysExactly($payload, ['data', 'message'], 'response');

        $data = $payload['data'];

        $this->assertKeysExactly($data, ['personal', 'approval'], 'data');

        $personal = $data['personal'];

        $this->assertKeysExactly(
            $personal,
            ['summary', 'recent_requests'],
            'data.personal',
        );
        $this->assertKeysExactly(
            $personal['summary'],
            [
                'total',
                'pending',
                'ongoing',
                'completed',
                'disapproved',
                'canceled',
            ],
            'data.personal.summary',
        );

        $approval = $data['approval'];

        $this->assertKeysExactly(
            $approval,
            ['summary', 'activity'],
            'data.approval',
        );
        $this->assertKeysExactly(
            $approval['summary'],
            [
                'total_assigned',
                'approved',
                'awaiting_action',
                'disapproved',
                'closed_without_action',
            ],
            'data.approval.summary',
        );
        $this->assertKeysExactly(
            $approval['activity'],
            ['monthly_decisions', 'weekly_assignments'],
            'data.approval.activity',
        );

        foreach ($approval['activity']['monthly_decisions'] as $month) {
            $this->assertKeysExactly(
                $month,
                ['month', 'label', 'approved', 'disapproved'],
                'monthly decision',
            );
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}$/',
                $month['month'],
            );
            $this->assertCountsAreNonNegativeIntegers(
                $month,
                ['approved', 'disapproved'],
            );
        }

        foreach ($approval['activity']['weekly_assignments'] as $day) {
            $this->assertKeysExactly(
                $day,
                ['date', 'label', 'total'],
                'weekly assignment',
            );
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $day['date'],
            );
            $this->assertCountsAreNonNegativeIntegers($day, ['total']);
        }

        $this->assertCountsAreNonNegativeIntegers(
            $personal['summary'],
            array_keys($personal['summary']),
        );
        $this->assertCountsAreNonNegativeIntegers(
            $approval['summary'],
            array_keys($approval['summary']),
        );

        $this->assertNoForbiddenKeys($data);
    }

    #[DataProvider('dateRangeParameters')]
    public function test_date_range_and_period_parameters_are_rejected(
        string $parameter,
    ): void {
        Sanctum::actingAs($this->user('approver'));

        $this->getJson(
            self::ENDPOINT.'?'.http_build_query([$parameter => '2026']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($parameter);
    }

    public function test_unrecognized_role_is_forbidden(): void
    {
        Role::findOrCreate('Manager', 'web');

        $user = $this->user();
        $user->assignRole('Manager');

        Sanctum::actingAs($user);

        $this->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function prohibitedParameters(): array
    {
        return [
            'user id' => ['user_id'],
            'requester id' => ['requester_id'],
            'approver id' => ['approver_id'],
            'role' => ['role'],
            'roles' => ['roles'],
            'branch id' => ['branch_id'],
            'status' => ['status'],
            'total' => ['total'],
            'permission' => ['permission'],
            'permissions' => ['permissions'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dateRangeParameters(): array
    {
        return [
            'date from' => ['date_from'],
            'date to' => ['date_to'],
            'from' => ['from'],
            'to' => ['to'],
            'year' => ['year'],
            'month' => ['month'],
        ];
    }

    private function assertDashboardContract(
        TestResponse $response,
    ): void {
        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Dashboard retrieved successfully.',
            )
            ->assertJsonStructure([
                'data' => [
                    'personal' => [
                        'summary' => [
                            'total',
                            'pending',
                            'ongoing',
                            'completed',
                            'disapproved',
                            'canceled',
                        ],
                        'recent_requests',
                    ],
                    'approval',
                ],
                'message',
            ])
            ->assertJsonPath('data.personal.recent_requests', []);

        $this->assertSame([
            'total' => 0,
            'pending' => 0,
            'ongoing' => 0,
            'completed' => 0,
            'disapproved' => 0,
            'canceled' => 0,
        ], $response->json('data.personal.summary'));
    }

    /**
     * Assert the payload contains exactly the expected keys, order-independent.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<int, string>  $expected
     */
    private function assertKeysExactly(
        array $actual,
        array $expected,
        string $context,
    ): void {
        $keys = array_keys($actual);
        sort($keys);
        sort($expected);

        $this->assertSame(
            $expected,
            $keys,
            "Unexpected response keys in {$context}.",
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function assertCountsAreNonNegativeIntegers(
        array $payload,
        array $keys,
    ): void {
        foreach ($keys as $key) {
            $this->assertIsInt(
                $payload[$key],
                "{$key} must be an integer.",
            );
            $this->assertGreaterThanOrEqual(
                0,
                $payload[$key],
                "{$key} must be non-negative.",
            );
        }
    }

    private function assertNoForbiddenKeys(
        mixed $value,
        string $path = 'data',
    ): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $this->assertNotContains(
                    $key,
                    self::FORBIDDEN_KEYS,
                    "Forbidden key {$key} exposed at {$path}.",
                );
            }

            $this->assertNoForbiddenKeys($child, "{$path}.{$key}");
        }
    }

    private function user(?string $role = null): User
    {
        $user = User::factory()->create([
            'firstName' => 'Dashboard',
            'lastName' => 'Viewer',
            'contact' => '09181234567',
            'position' => 'Employee',
            'userName' => fake()->unique()->userName(),
            'employee_id' => fake()->unique()->numerify('DASH-#####'),
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
