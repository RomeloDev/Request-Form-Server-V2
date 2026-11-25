<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\BranchHeadAssignment;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
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

    public function test_admin_receives_organization_aggregates(): void
    {
        Branch::factory()->count(2)->create();

        $admin = $this->user('Admin');
        $verifiedUser = $this->user('User');
        $unverifiedUser = $this->user('User', verified: false);
        $approver = $this->user('approver');

        Position::query()->create([
            'label' => 'Accountant',
            'value' => 'Accountant',
        ]);

        AvpStaffAssignment::query()->create([
            'avp_user_id' => $verifiedUser->id,
            'staff_user_id' => $approver->id,
        ]);

        BranchHeadAssignment::query()->create([
            'user_id' => $unverifiedUser->id,
        ]);

        $feedback = new Feedback([
            'name' => 'Dashboard Tester',
            'email' => 'dashboard@example.test',
            'department' => 'Finance',
            'opinion' => 'Good',
            'message' => 'Admin dashboard aggregation fixture.',
        ]);
        $feedback->feedback_code = 'FDBK-ADMIN-DASHBOARD';
        $feedback->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total_users' => 3,
            'verified_users' => 2,
            'unverified_users' => 1,
            'branches' => 2,
            'positions' => 1,
            'approvers' => 1,
            'avp_staff_assignments' => 1,
            'branch_head_assignments' => 1,
            'feedbacks' => 1,
        ], $response->json('data.administration.summary'));
    }

    public function test_admin_request_summary_is_system_wide_and_excludes_deleted_requests(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->user('Admin');
        $requester = $this->user('User');

        $this->requestForm(
            $requester,
            $branch,
            'ADMIN-PENDING-1',
            RequestStatus::Pending,
        );
        $this->requestForm(
            $requester,
            $branch,
            'ADMIN-PENDING-2',
            RequestStatus::Pending,
        );
        $this->requestForm(
            $requester,
            $branch,
            'ADMIN-COMPLETED-1',
            RequestStatus::Completed,
        );
        $this->requestForm(
            $requester,
            $branch,
            'ADMIN-CANCELED-1',
            RequestStatus::Canceled,
        );

        $deleted = $this->requestForm(
            $requester,
            $branch,
            'ADMIN-DELETED-1',
            RequestStatus::Pending,
        );
        $deleted->delete();

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total' => 4,
            'pending' => 2,
            'ongoing' => 0,
            'completed' => 1,
            'disapproved' => 0,
            'canceled' => 1,
        ], $response->json('data.administration.request_summary'));
    }

    public function test_admin_recent_requests_are_limited_ordered_and_sanitized(): void
    {
        $branch = Branch::factory()->create();
        $admin = $this->user('Admin');
        $requester = $this->user('User');
        $requests = [];

        foreach (range(1, 6) as $number) {
            $requests[] = $this->requestForm(
                $requester,
                $branch,
                "ADMIN-RECENT-{$number}",
                createdAt: now()->addMinutes($number),
            );
        }

        Sanctum::actingAs($admin);

        $recent = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.administration.recent_requests');

        $this->assertCount(5, $recent);
        $this->assertSame(
            array_reverse(array_map(
                fn (RequestForm $request): int => $request->id,
                array_slice($requests, 1),
            )),
            array_column($recent, 'id'),
        );

        foreach ($recent as $request) {
            $this->assertSame(
                ['id', 'request_code', 'form_type', 'status', 'branch', 'created_at'],
                array_keys($request),
            );
            $this->assertArrayNotHasKey('requester_id', $request);
            $this->assertArrayNotHasKey('form_data', $request);
            $this->assertArrayNotHasKey('grand_total', $request);
        }
    }

    public function test_non_admin_accounts_never_receive_administration_data(): void
    {
        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson(self::ENDPOINT)
                ->assertOk()
                ->assertJsonPath('data.administration', null);
        }
    }

    private function user(
        string $role,
        bool $verified = true,
    ): User {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
            'firstName' => "AdminDashboard{$number}",
            'lastName' => 'Tester',
            'contact' => "0918000{$number}",
            'position' => 'Employee',
            'userName' => "admin-dashboard-{$number}",
            'employee_id' => "ADMIN-DASH-{$number}",
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
        RequestStatus $status = RequestStatus::Pending,
        mixed $createdAt = null,
    ): RequestForm {
        $request = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Admin dashboard regression fixture.',
            ],
        ]);
        $request->request_code = $requestCode;
        $request->form_type = RequestFormType::StockRequisition;
        $request->status = $status;
        $request->grand_total = '100.00';

        if ($createdAt !== null) {
            $request->created_at = $createdAt;
            $request->updated_at = $createdAt;
        }

        $request->requester()->associate($requester);
        $request->branch()->associate($branch);
        $request->save();

        return $request;
    }
}
