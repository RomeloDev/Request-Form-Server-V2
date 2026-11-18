<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApprovalDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const DASHBOARD_ENDPOINT = '/api/v1/dashboard';

    private const ASSIGNED_ENDPOINT = '/api/v1/approver/request-forms';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_and_user_receive_null_approval(): void
    {
        $branch = $this->branch('NO-APPROVAL');

        foreach (['Admin', 'User'] as $role) {
            Sanctum::actingAs($this->user($branch, $role));

            $this->getJson(self::DASHBOARD_ENDPOINT)
                ->assertOk()
                ->assertJsonPath('data.approval', null);
        }
    }

    public function test_approver_summary_counts_outcomes_and_excludes_other_approvers(): void
    {
        $branch = $this->branch('OUTCOMES');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $otherApprover = $this->user($branch, 'approver');

        $approved = $this->requestForm(
            $requester,
            $branch,
            'OUTCOME-APPROVED',
            RequestStatus::Completed,
        );
        $this->approvalStep(
            $approved,
            $approver,
            1,
            ApprovalStepStatus::Approved,
        );

        $disapproved = $this->requestForm(
            $requester,
            $branch,
            'OUTCOME-DISAPPROVED',
            RequestStatus::Disapproved,
        );
        $this->approvalStep(
            $disapproved,
            $approver,
            1,
            ApprovalStepStatus::Disapproved,
        );

        $skipped = $this->requestForm(
            $requester,
            $branch,
            'OUTCOME-SKIPPED',
            RequestStatus::Disapproved,
        );
        $this->approvalStep(
            $skipped,
            $approver,
            2,
            ApprovalStepStatus::Skipped,
        );

        $canceled = $this->requestForm(
            $requester,
            $branch,
            'OUTCOME-CANCELED',
            RequestStatus::Canceled,
        );
        $this->approvalStep(
            $canceled,
            $approver,
            1,
            ApprovalStepStatus::Canceled,
        );

        /*
         * These steps belong to another approver and must never leak into
         * this approver's summary.
         */
        $otherRequest = $this->requestForm(
            $requester,
            $branch,
            'OUTCOME-OTHER',
            RequestStatus::Completed,
        );
        $this->approvalStep(
            $otherRequest,
            $otherApprover,
            1,
            ApprovalStepStatus::Approved,
        );

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::DASHBOARD_ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total_assigned' => 4,
            'approved' => 1,
            'awaiting_action' => 0,
            'disapproved' => 1,
            'closed_without_action' => 2,
        ], $response->json('data.approval.summary'));

        $response
            ->assertJsonCount(
                12,
                'data.approval.activity.monthly_decisions',
            )
            ->assertJsonCount(
                7,
                'data.approval.activity.weekly_assignments',
            );
    }

    public function test_immediately_actionable_pending_step_counts_as_awaiting_action(): void
    {
        $branch = $this->branch('IMMEDIATE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        $requestForm = $this->requestForm(
            $requester,
            $branch,
            'IMMEDIATE-PENDING',
            RequestStatus::Pending,
        );
        $this->approvalStep($requestForm, $approver, 1);

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::DASHBOARD_ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total_assigned' => 1,
            'approved' => 0,
            'awaiting_action' => 1,
            'disapproved' => 0,
            'closed_without_action' => 0,
        ], $response->json('data.approval.summary'));
    }

    public function test_pending_step_waits_for_every_earlier_level_to_be_approved(): void
    {
        $branch = $this->branch('LEVELS');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $currentApprover = $this->user($branch, 'approver');

        $futureRequest = $this->requestForm(
            $requester,
            $branch,
            'LEVELS-FUTURE',
            RequestStatus::Pending,
        );
        $futureEarlierStep = $this->approvalStep(
            $futureRequest,
            $firstApprover,
            1,
        );
        $this->approvalStep($futureRequest, $currentApprover, 2);

        $readyRequest = $this->requestForm(
            $requester,
            $branch,
            'LEVELS-READY',
            RequestStatus::Ongoing,
        );
        $this->approvalStep(
            $readyRequest,
            $firstApprover,
            1,
            ApprovalStepStatus::Approved,
        );
        $this->approvalStep($readyRequest, $currentApprover, 2);

        Sanctum::actingAs($currentApprover);

        $this->assertSame(
            1,
            $this->getJson(self::DASHBOARD_ENDPOINT)
                ->assertOk()
                ->json('data.approval.summary.awaiting_action'),
        );

        /*
         * Once the only earlier level is approved, the future step becomes
         * actionable and the dashboard must reflect that immediately.
         */
        $futureEarlierStep->status = ApprovalStepStatus::Approved;
        $futureEarlierStep->acted_at = now();
        $futureEarlierStep->save();

        $futureRequest->status = RequestStatus::Ongoing;
        $futureRequest->save();

        $this->assertSame(
            2,
            $this->getJson(self::DASHBOARD_ENDPOINT)
                ->assertOk()
                ->json('data.approval.summary.awaiting_action'),
        );
    }

    public function test_pending_steps_on_canceled_or_soft_deleted_requests_are_not_actionable(): void
    {
        $branch = $this->branch('INACTIVE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        $activeRequest = $this->requestForm(
            $requester,
            $branch,
            'INACTIVE-ACTIVE',
            RequestStatus::Pending,
        );
        $this->approvalStep($activeRequest, $approver, 1);

        $canceledRequest = $this->requestForm(
            $requester,
            $branch,
            'INACTIVE-CANCELED',
            RequestStatus::Canceled,
        );
        $this->approvalStep($canceledRequest, $approver, 1);

        $deletedRequest = $this->requestForm(
            $requester,
            $branch,
            'INACTIVE-DELETED',
            RequestStatus::Pending,
        );
        $this->approvalStep($deletedRequest, $approver, 1);
        $deletedRequest->delete();

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::DASHBOARD_ENDPOINT)
            ->assertOk();

        /*
         * The canceled request still has a non-deleted approval step, so it
         * contributes to total_assigned. The soft-deleted request does not.
         */
        $this->assertSame([
            'total_assigned' => 2,
            'approved' => 0,
            'awaiting_action' => 1,
            'disapproved' => 0,
            'closed_without_action' => 0,
        ], $response->json('data.approval.summary'));
    }

    public function test_approver_without_assignments_receives_all_zero_summary(): void
    {
        $approver = $this->user($this->branch('EMPTY-APPROVAL'), 'approver');

        Sanctum::actingAs($approver);

        $response = $this->getJson(self::DASHBOARD_ENDPOINT)
            ->assertOk();

        $this->assertSame([
            'total_assigned' => 0,
            'approved' => 0,
            'awaiting_action' => 0,
            'disapproved' => 0,
            'closed_without_action' => 0,
        ], $response->json('data.approval.summary'));

        $response
            ->assertJsonCount(
                12,
                'data.approval.activity.monthly_decisions',
            )
            ->assertJsonCount(
                7,
                'data.approval.activity.weekly_assignments',
            );

        $this->assertSame(
            0,
            array_sum(array_column(
                $response->json('data.approval.activity.monthly_decisions'),
                'approved',
            )),
        );
        $this->assertSame(
            0,
            array_sum(array_column(
                $response->json('data.approval.activity.monthly_decisions'),
                'disapproved',
            )),
        );
        $this->assertSame(
            0,
            array_sum(array_column(
                $response->json('data.approval.activity.weekly_assignments'),
                'total',
            )),
        );
    }

    public function test_dashboard_awaiting_action_agrees_with_process_requests_eligibility(): void
    {
        $branch = $this->branch('PARITY');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $currentApprover = $this->user($branch, 'approver');
        $otherApprover = $this->user($branch, 'approver');

        // 1. Immediately actionable.
        $immediate = $this->requestForm(
            $requester,
            $branch,
            'PARITY-IMMEDIATE',
            RequestStatus::Pending,
        );
        $this->approvalStep($immediate, $currentApprover, 1);

        // 2. Actionable after an earlier approval.
        $ready = $this->requestForm(
            $requester,
            $branch,
            'PARITY-READY',
            RequestStatus::Ongoing,
        );
        $this->approvalStep(
            $ready,
            $firstApprover,
            1,
            ApprovalStepStatus::Approved,
        );
        $this->approvalStep($ready, $currentApprover, 2);

        // 3. Future level: an earlier step is still pending.
        $future = $this->requestForm(
            $requester,
            $branch,
            'PARITY-FUTURE',
            RequestStatus::Pending,
        );
        $this->approvalStep($future, $firstApprover, 1);
        $this->approvalStep($future, $currentApprover, 2);

        // 4. Canceled request.
        $canceled = $this->requestForm(
            $requester,
            $branch,
            'PARITY-CANCELED',
            RequestStatus::Canceled,
        );
        $this->approvalStep($canceled, $currentApprover, 1);

        // 5. Soft-deleted request.
        $deleted = $this->requestForm(
            $requester,
            $branch,
            'PARITY-DELETED',
            RequestStatus::Pending,
        );
        $this->approvalStep($deleted, $currentApprover, 1);
        $deleted->delete();

        // 6. Completed history, not a Pending assignment.
        $completed = $this->requestForm(
            $requester,
            $branch,
            'PARITY-COMPLETED',
            RequestStatus::Completed,
        );
        $this->approvalStep(
            $completed,
            $currentApprover,
            1,
            ApprovalStepStatus::Approved,
        );

        // 7. Another approver's actionable assignment must not leak in.
        $otherRequest = $this->requestForm(
            $requester,
            $branch,
            'PARITY-OTHER',
            RequestStatus::Pending,
        );
        $this->approvalStep($otherRequest, $otherApprover, 1);

        Sanctum::actingAs($currentApprover);

        $dashboardAwaitingAction = $this->getJson(self::DASHBOARD_ENDPOINT)
            ->assertOk()
            ->json('data.approval.summary.awaiting_action');

        $assignedResponse = $this->getJson(
            self::ASSIGNED_ENDPOINT.'?assignment_status=Pending&per_page=50',
        )->assertOk();

        $this->assertSame(2, $dashboardAwaitingAction);
        $this->assertSame(
            $dashboardAwaitingAction,
            $assignedResponse->json('meta.total'),
        );

        $assignedRequestIds = array_column(
            $assignedResponse->json('data'),
            'id',
        );

        sort($assignedRequestIds);

        $expectedRequestIds = [$immediate->id, $ready->id];
        sort($expectedRequestIds);

        $this->assertSame($expectedRequestIds, $assignedRequestIds);
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
            'firstName' => "Approval{$number}",
            'lastName' => 'User',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "approval-dashboard-{$number}",
            'employee_id' => "APPROVAL-DASH-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        string $requestCode,
        RequestStatus $status,
        RequestFormType $formType = RequestFormType::StockRequisition,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing the approval dashboard summary.',
            ],
        ]);

        $requestForm->request_code = $requestCode;
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepStatus $status = ApprovalStepStatus::Pending,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = $status;
        $step->acted_at = $status === ApprovalStepStatus::Pending
            ? null
            : now();

        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
