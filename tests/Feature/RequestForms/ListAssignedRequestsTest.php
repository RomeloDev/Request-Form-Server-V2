<?php

namespace Tests\Feature\RequestForms;

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

class ListAssignedRequestsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

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
        $this->getJson('/api/v1/approver/request-forms')
            ->assertUnauthorized();
    }

    public function test_non_approver_roles_are_forbidden(): void
    {
        $branch = $this->branch('ROLE');

        foreach (['User', 'Admin'] as $role) {
            Sanctum::actingAs($this->user($branch, $role));

            $this->getJson('/api/v1/approver/request-forms')
                ->assertForbidden();
        }
    }

    public function test_approver_receives_only_their_assignments_with_safe_fields(): void
    {
        $branch = $this->branch('OWN');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $otherApprover = $this->user($branch, 'approver');

        $ownedRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'OWN-SRL-0000001',
            status: RequestStatus::Completed,
        );
        $ownedStep = $this->approvalStep(
            requestForm: $ownedRequest,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Approved,
            comment: 'Approved for processing.',
        );

        $otherRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'OTHER-SRL-0000002',
            status: RequestStatus::Completed,
        );
        $this->approvalStep(
            requestForm: $otherRequest,
            approver: $otherApprover,
            level: 1,
            status: ApprovalStepStatus::Approved,
        );

        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/approver/request-forms')
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Assigned requests retrieved successfully.',
            )
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedRequest->id)
            ->assertJsonPath('data.0.request_code', 'OWN-SRL-0000001')
            ->assertJsonPath('data.0.requester.id', $requester->id)
            ->assertJsonPath('data.0.branch.branch_code', 'OWN')
            ->assertJsonPath('data.0.my_step.id', $ownedStep->id)
            ->assertJsonPath('data.0.my_step.status', 'Approved')
            ->assertJsonPath('data.0.my_step.can_process', false)
            ->assertJsonMissingPath('data.0.requester.email')
            ->assertJsonMissingPath('data.0.requester.signature')
            ->assertJsonMissingPath('data.0.attachments')
            ->assertJsonMissing([
                'id' => $otherRequest->id,
                'request_code' => $otherRequest->request_code,
            ]);
    }

    public function test_pending_assignment_is_visible_only_when_all_earlier_steps_are_approved(): void
    {
        $branch = $this->branch('ORDER');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'ORDER-SRL-0000001',
            status: RequestStatus::Pending,
        );
        $firstStep = $this->approvalStep(
            requestForm: $requestForm,
            approver: $firstApprover,
            level: 1,
        );
        $secondStep = $this->approvalStep(
            requestForm: $requestForm,
            approver: $secondApprover,
            level: 2,
        );

        Sanctum::actingAs($secondApprover);

        $this->getJson('/api/v1/approver/request-forms')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $firstStep->status = ApprovalStepStatus::Approved;
        $firstStep->acted_at = now();
        $firstStep->save();

        $requestForm->status = RequestStatus::Ongoing;
        $requestForm->save();

        $this->getJson('/api/v1/approver/request-forms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestForm->id)
            ->assertJsonPath('data.0.my_step.id', $secondStep->id)
            ->assertJsonPath('data.0.my_step.status', 'Pending')
            ->assertJsonPath('data.0.my_step.can_process', true);
    }

    public function test_canceled_and_future_pending_assignments_are_excluded_but_history_is_retained(): void
    {
        $branch = $this->branch('HISTORY');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $earlierApprover = $this->user($branch, 'approver');

        $completed = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'HISTORY-COMPLETED',
            status: RequestStatus::Completed,
        );
        $this->approvalStep(
            requestForm: $completed,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Approved,
        );

        $disapproved = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'HISTORY-DISAPPROVED',
            status: RequestStatus::Disapproved,
        );
        $this->approvalStep(
            requestForm: $disapproved,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Disapproved,
        );

        $canceled = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'HISTORY-CANCELED',
            status: RequestStatus::Canceled,
        );
        $this->approvalStep(
            requestForm: $canceled,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Canceled,
        );

        $future = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'HISTORY-FUTURE',
            status: RequestStatus::Disapproved,
        );
        $this->approvalStep(
            requestForm: $future,
            approver: $earlierApprover,
            level: 1,
            status: ApprovalStepStatus::Disapproved,
        );
        $this->approvalStep(
            requestForm: $future,
            approver: $approver,
            level: 2,
        );

        Sanctum::actingAs($approver);

        $response = $this->getJson(
            '/api/v1/approver/request-forms?per_page=50',
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($completed->id, $ids);
        $this->assertContains($disapproved->id, $ids);
        $this->assertNotContains($canceled->id, $ids);
        $this->assertNotContains($future->id, $ids);
    }

    public function test_status_search_and_pagination_filters_are_applied(): void
    {
        $firstBranch = $this->branch('SEARCH-A');
        $secondBranch = $this->branch('SEARCH-B');
        $approver = $this->user($firstBranch, 'approver');
        $alphaRequester = $this->user($firstBranch);
        $betaRequester = $this->user($secondBranch);

        $alphaRequester->firstName = 'Alphonso';
        $alphaRequester->save();

        $pending = $this->requestForm(
            requester: $alphaRequester,
            branch: $firstBranch,
            requestCode: 'FILTER-ALPHA',
            status: RequestStatus::Pending,
        );
        $this->approvalStep($pending, $approver, 1);

        $completed = $this->requestForm(
            requester: $betaRequester,
            branch: $secondBranch,
            requestCode: 'FILTER-BETA',
            status: RequestStatus::Completed,
            formType: RequestFormType::PurchaseOrder,
        );
        $this->approvalStep(
            requestForm: $completed,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Approved,
        );

        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/approver/request-forms?status=Pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/v1/approver/request-forms?search=Alphonso')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/v1/approver/request-forms?search=SEARCH-B')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id);

        $this->getJson('/api/v1/approver/request-forms?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_pending_assignment_filter_includes_actionable_pending_and_ongoing_requests(): void
    {
        $branch = $this->branch('QUEUE');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $currentApprover = $this->user($branch, 'approver');

        $pendingRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'QUEUE-PENDING',
            status: RequestStatus::Pending,
        );
        $this->approvalStep($pendingRequest, $currentApprover, 1);

        $ongoingRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'QUEUE-ONGOING',
            status: RequestStatus::Ongoing,
        );
        $this->approvalStep(
            $ongoingRequest,
            $firstApprover,
            1,
            ApprovalStepStatus::Approved,
        );
        $this->approvalStep($ongoingRequest, $currentApprover, 2);

        $completedRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            requestCode: 'QUEUE-COMPLETED',
            status: RequestStatus::Completed,
        );
        $this->approvalStep(
            $completedRequest,
            $currentApprover,
            1,
            ApprovalStepStatus::Approved,
        );

        Sanctum::actingAs($currentApprover);

        $response = $this->getJson(
            '/api/v1/approver/request-forms?assignment_status=Pending&per_page=50',
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($pendingRequest->id, $ids);
        $this->assertContains($ongoingRequest->id, $ids);
        $this->assertNotContains($completedRequest->id, $ids);
    }

    public function test_identity_overrides_and_invalid_filters_are_rejected(): void
    {
        $approver = $this->user(
            $this->branch('VALIDATE'),
            'approver',
        );

        Sanctum::actingAs($approver);

        $this->getJson(
            '/api/v1/approver/request-forms?user_id=1&approver_id=1&requester_id=1&branch_id=1&approval_step_id=1&status=all&assignment_status=waiting&page=0&per_page=51',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'approver_id',
                'requester_id',
                'branch_id',
                'approval_step_id',
                'status',
                'assignment_status',
                'page',
                'per_page',
            ]);
    }

    private function branch(string $code): Branch
    {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => 'TEST',
        ]);
    }

    private function user(Branch $branch, string $role = 'User'): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Assigned{$number}",
            'lastName' => 'User',
            'contact' => "0919000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "assigned-user-{$number}",
            'employee_id' => "ASSIGNED-EMP-{$number}",
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
                'purpose' => 'Testing assigned request listing.',
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
        string $comment = '',
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = $status;
        $step->comment = $comment !== '' ? $comment : null;
        $step->acted_at = $status === ApprovalStepStatus::Pending
            ? null
            : now();

        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
