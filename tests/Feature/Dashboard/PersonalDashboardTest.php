<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PersonalDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/dashboard';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_personal_summary_counts_every_status_with_canceled_separate(): void
    {
        $branch = $this->branch('SUMMARY');
        $requester = $this->user($branch);

        foreach ([
            RequestStatus::Pending,
            RequestStatus::Pending,
            RequestStatus::Ongoing,
            RequestStatus::Completed,
            RequestStatus::Disapproved,
            RequestStatus::Canceled,
        ] as $index => $status) {
            $this->requestForm(
                requester: $requester,
                branch: $branch,
                requestCode: "SUMMARY-STATUS-{$index}",
                status: $status,
            );
        }

        Sanctum::actingAs($requester);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total' => 6,
            'pending' => 2,
            'ongoing' => 1,
            'completed' => 1,
            'disapproved' => 1,
            'canceled' => 1,
        ], $response->json('data.personal.summary'));
    }

    public function test_another_users_requests_do_not_affect_the_summary_or_recent_list(): void
    {
        $branch = $this->branch('ISOLATION');
        $requester = $this->user($branch);
        $otherUser = $this->user($branch);

        $mine = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'MINE-ONLY-1',
            status: RequestStatus::Pending,
        );

        foreach ([
            RequestStatus::Pending,
            RequestStatus::Ongoing,
            RequestStatus::Completed,
            RequestStatus::Disapproved,
            RequestStatus::Canceled,
        ] as $index => $status) {
            $this->requestForm(
                requester: $otherUser,
                branch: $branch,
                requestCode: "OTHER-ONLY-{$index}",
                status: $status,
            );
        }

        Sanctum::actingAs($requester);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total' => 1,
            'pending' => 1,
            'ongoing' => 0,
            'completed' => 0,
            'disapproved' => 0,
            'canceled' => 0,
        ], $response->json('data.personal.summary'));

        $recentRequests = $response->json('data.personal.recent_requests');

        $this->assertCount(1, $recentRequests);
        $this->assertSame($mine->id, $recentRequests[0]['id']);
    }

    public function test_soft_deleted_requests_are_excluded_from_summary_and_recent_list(): void
    {
        $branch = $this->branch('TRASHED');
        $requester = $this->user($branch);

        $active = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ACTIVE-REQUEST',
            status: RequestStatus::Pending,
            createdAt: now()->subDay(),
        );

        $deletedCompleted = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DELETED-COMPLETED',
            status: RequestStatus::Completed,
            createdAt: now(),
        );
        $deletedCompleted->delete();

        $deletedPending = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'DELETED-PENDING',
            status: RequestStatus::Pending,
            createdAt: now()->addMinute(),
        );
        $deletedPending->delete();

        Sanctum::actingAs($requester);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total' => 1,
            'pending' => 1,
            'ongoing' => 0,
            'completed' => 0,
            'disapproved' => 0,
            'canceled' => 0,
        ], $response->json('data.personal.summary'));

        $recentRequests = $response->json('data.personal.recent_requests');

        $this->assertCount(1, $recentRequests);
        $this->assertSame($active->id, $recentRequests[0]['id']);

        $response
            ->assertJsonMissing([
                'request_code' => $deletedCompleted->request_code,
            ])
            ->assertJsonMissing([
                'request_code' => $deletedPending->request_code,
            ]);
    }

    public function test_recent_requests_are_newest_first_tie_broken_by_id_and_limited_to_five(): void
    {
        $branch = $this->branch('ORDER');
        $requester = $this->user($branch);
        $base = now()->subHour();

        $oldest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-OLDEST',
            createdAt: $base->copy()->subDays(5),
        );

        $second = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-SECOND',
            createdAt: $base->copy()->subDays(4),
        );

        $third = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-THIRD',
            createdAt: $base->copy()->subDays(3),
        );

        $fourth = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-FOURTH',
            createdAt: $base->copy()->subDays(2),
        );

        $tieOlder = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-TIE-OLDER',
            createdAt: $base->copy()->subDay(),
        );

        $tieNewer = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-TIE-NEWER',
            createdAt: $base->copy()->subDay(),
        );

        Sanctum::actingAs($requester);

        $recentRequests = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.personal.recent_requests');

        $this->assertCount(5, $recentRequests);

        $this->assertSame([
            $tieNewer->id,
            $tieOlder->id,
            $fourth->id,
            $third->id,
            $second->id,
        ], array_column($recentRequests, 'id'));

        $this->assertNotContains($oldest->id, array_column($recentRequests, 'id'));
    }

    public function test_recent_request_uses_only_the_approved_safe_shape(): void
    {
        $branch = $this->branch('SHAPE');
        $requester = $this->user($branch);

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'SHAPE-REQUEST-1',
            status: RequestStatus::Completed,
            formType: RequestFormType::PurchaseOrder,
            kindOfRequest: 'Sensitive Category',
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $recentRequest = $response->json('data.personal.recent_requests.0');

        $this->assertSame([
            'id',
            'request_code',
            'form_type',
            'status',
            'branch',
            'created_at',
        ], array_keys($recentRequest));

        $this->assertSame([
            'branch_code',
            'branch_name',
        ], array_keys($recentRequest['branch']));

        $this->assertSame($requestForm->id, $recentRequest['id']);
        $this->assertSame(
            $requestForm->request_code,
            $recentRequest['request_code'],
        );
        $this->assertSame(
            RequestFormType::PurchaseOrder->value,
            $recentRequest['form_type'],
        );
        $this->assertSame(
            RequestStatus::Completed->value,
            $recentRequest['status'],
        );
        $this->assertSame([
            'branch_code' => $branch->branch_code,
            'branch_name' => $branch->branch_name,
        ], $recentRequest['branch']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $recentRequest['created_at'],
        );

        foreach ([
            'data.personal.recent_requests.0.form_data',
            'data.personal.recent_requests.0.requester',
            'data.personal.recent_requests.0.requester_id',
            'data.personal.recent_requests.0.grand_total',
            'data.personal.recent_requests.0.currency',
            'data.personal.recent_requests.0.items',
            'data.personal.recent_requests.0.approval_steps',
            'data.personal.recent_requests.0.shared_recipients',
            'data.personal.recent_requests.0.attachments',
            'data.personal.recent_requests.0.cancellation_reason',
            'data.personal.recent_requests.0.branch.id',
        ] as $path) {
            $response->assertJsonMissingPath($path);
        }
    }

    public function test_approver_receives_personal_aggregation_with_the_approval_scaffold(): void
    {
        $branch = $this->branch('APPROVER');
        $approver = $this->user($branch, 'approver');
        $otherUser = $this->user($branch);

        $pending = $this->requestForm(
            requester: $approver,
            branch: $branch,
            requestCode: 'APPROVER-OWN-PENDING',
            status: RequestStatus::Pending,
        );

        $canceled = $this->requestForm(
            requester: $approver,
            branch: $branch,
            requestCode: 'APPROVER-OWN-CANCELED',
            status: RequestStatus::Canceled,
        );

        $this->requestForm(
            requester: $otherUser,
            branch: $branch,
            requestCode: 'APPROVER-OTHER-PENDING',
            status: RequestStatus::Pending,
        );

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(
                12,
                'data.approval.activity.monthly_decisions',
            )
            ->assertJsonCount(
                7,
                'data.approval.activity.weekly_assignments',
            );

        $this->assertSame([
            'total' => 2,
            'pending' => 1,
            'ongoing' => 0,
            'completed' => 0,
            'disapproved' => 0,
            'canceled' => 1,
        ], $response->json('data.personal.summary'));

        $recentRequests = $response->json('data.personal.recent_requests');

        $this->assertCount(2, $recentRequests);
        $this->assertSame(
            [$canceled->id, $pending->id],
            array_column($recentRequests, 'id'),
        );
    }

    public function test_account_without_requests_receives_zero_summary_and_empty_recent_list(): void
    {
        $requester = $this->user($this->branch('EMPTY'));

        Sanctum::actingAs($requester);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk()
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

    public function test_admin_dashboard_remains_self_scoped(): void
    {
        $branch = $this->branch('ADMIN');
        $admin = $this->user($branch, 'Admin');
        $otherUser = $this->user($branch);

        $adminRequest = $this->requestForm(
            requester: $admin,
            branch: $branch,
            requestCode: 'ADMIN-OWN-1',
            status: RequestStatus::Completed,
        );

        foreach (range(1, 3) as $number) {
            $this->requestForm(
                requester: $otherUser,
                branch: $branch,
                requestCode: "ADMIN-OTHER-{$number}",
                status: RequestStatus::Pending,
            );
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.approval', null);

        $this->assertSame([
            'total' => 1,
            'pending' => 0,
            'ongoing' => 0,
            'completed' => 1,
            'disapproved' => 0,
            'canceled' => 0,
        ], $response->json('data.personal.summary'));

        $recentRequests = $response->json('data.personal.recent_requests');

        $this->assertCount(1, $recentRequests);
        $this->assertSame($adminRequest->id, $recentRequests[0]['id']);
    }

    private function branch(string $code): Branch
    {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => $code,
        ]);
    }

    private function user(Branch $branch, string $role = 'User'): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Dashboard{$number}",
            'lastName' => 'Owner',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "dashboard-owner-{$number}",
            'employee_id' => "DASHBOARD-EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
        RequestStatus $status = RequestStatus::Pending,
        RequestFormType $formType = RequestFormType::StockRequisition,
        string $kindOfRequest = 'For Stock',
        mixed $createdAt = null,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => $kindOfRequest,
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Sensitive dashboard fixture.',
            ],
        ]);

        $requestForm->request_code = $requestCode;
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        if ($createdAt !== null) {
            $requestForm->created_at = $createdAt;
            $requestForm->updated_at = $createdAt;
        }

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
