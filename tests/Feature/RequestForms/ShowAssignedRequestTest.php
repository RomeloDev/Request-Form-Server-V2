<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShowAssignedRequestTest extends TestCase
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
        $this->getJson('/api/v1/approver/request-forms/999999')
            ->assertUnauthorized();
    }

    public function test_non_approver_cannot_use_the_approver_details_endpoint(): void
    {
        $branch = $this->branch('ROLE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->getJson(
            "/api/v1/approver/request-forms/{$requestForm->id}",
        )->assertForbidden();
    }

    public function test_unassigned_approver_cannot_view_request_details(): void
    {
        $branch = $this->branch('PRIVATE');
        $requester = $this->user($branch);
        $assignedApprover = $this->user($branch, 'approver');
        $unassignedApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep($requestForm, $assignedApprover);

        Sanctum::actingAs($unassignedApprover);

        $this->getJson(
            "/api/v1/approver/request-forms/{$requestForm->id}",
        )->assertForbidden();
    }

    public function test_assigned_approver_can_view_complete_safe_request_details(): void
    {
        $branch = $this->branch('DETAIL');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $item = new RequestItem([
            'quantity' => 2,
            'description' => 'A4 bond paper',
            'unit_cost' => '125.00',
            'remarks' => 'For accounting use',
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        $attachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/private.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $step = $this->approvalStep($requestForm, $approver);

        Sanctum::actingAs($approver);

        $this->getJson(
            "/api/v1/approver/request-forms/{$requestForm->id}",
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Assigned request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.requester.id', $requester->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.items.0.description', 'A4 bond paper')
            ->assertJsonPath(
                'data.attachments.0.id',
                $attachment->id,
            )
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'quotation.pdf',
            )
            ->assertJsonPath('data.approval_steps.0.id', $step->id)
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            )
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester.signature')
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path')
            ->assertJsonMissingPath('data.approval_steps.0.approver.email')
            ->assertJsonMissingPath('data.approval_steps.0.approver.signature');
    }

    public function test_missing_and_soft_deleted_requests_return_custom_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/approver/request-forms/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Assigned request not found.');

        $requestForm = $this->requestForm($requester, $branch);
        $this->approvalStep($requestForm, $approver);
        $requestForm->delete();

        $this->getJson(
            "/api/v1/approver/request-forms/{$requestForm->id}",
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Assigned request not found.');
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep($requestForm, $approver);

        Sanctum::actingAs($approver);

        $this->getJson(
            "/api/v1/approver/request-forms/{$requestForm->id}?user_id=1&approver_id=1&requester_id=1&approval_step_id=1",
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'approver_id',
                'requester_id',
                'approval_step_id',
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
            'firstName' => "AssignedDetails{$number}",
            'lastName' => 'User',
            'contact' => "0920000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "assigned-details-{$number}",
            'employee_id' => "ASSIGNED-DETAIL-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing assigned request details.',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Pending;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
