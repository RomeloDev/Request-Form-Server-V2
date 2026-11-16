<?php

namespace Tests\Feature\Admin;

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

class ShowRequestReportTest extends TestCase
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
        $this->getJson('/api/v1/admin/request-reports/999999')
            ->assertUnauthorized();
    }

    public function test_ordinary_user_is_forbidden(): void
    {
        $branch = $this->branch('USER-DENY');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($this->user($branch));

        $this->getJson($this->endpoint($requestForm))
            ->assertForbidden();
    }

    public function test_assigned_approver_is_still_forbidden_from_admin_report_details(): void
    {
        $branch = $this->branch('APPROVER-DENY');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);

        $this->approvalStep($requestForm, $approver);

        Sanctum::actingAs($approver);

        $this->getJson($this->endpoint($requestForm))
            ->assertForbidden();
    }

    public function test_admin_can_view_complete_report_details_without_sensitive_storage_fields(): void
    {
        $ownerBranch = $this->branch('OWNER');
        $adminBranch = $this->branch('ADMIN');
        $owner = $this->user($ownerBranch);
        $approver = $this->user(
            $ownerBranch,
            'approver',
            'Finance Manager',
        );
        $admin = $this->user($adminBranch, 'Admin');
        $requestForm = $this->requestForm($owner, $ownerBranch);

        $item = new RequestItem([
            'quantity' => 2,
            'description' => 'Cheque book printing',
            'unit_cost' => '125.00',
            'remarks' => 'Urgent',
            'item_data' => ['reference' => 'ITEM-REF-1'],
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/private.pdf",
            'original_name' => 'supporting-document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ]);

        $step = $this->approvalStep($requestForm, $approver);
        $step->attachments()->create([
            'disk' => 'local',
            'path' => "approval-steps/{$step->id}/private-proof.pdf",
            'original_name' => 'approval-proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson($this->endpoint($requestForm));

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request report retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CheckIssuance->value,
            )
            ->assertJsonPath('data.form_data.payee', 'Learning Vendor')
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonPath('data.branch.id', $ownerBranch->id)
            ->assertJsonPath(
                'data.items.0.description',
                'Cheque book printing',
            )
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'supporting-document.pdf',
            )
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            )
            ->assertJsonPath(
                'data.approval_steps.0.attachments.0.original_name',
                'approval-proof.pdf',
            )
            ->assertJsonMissingPath('data.requester_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester.signature')
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path')
            ->assertJsonMissingPath(
                'data.approval_steps.0.attachments.0.disk',
            )
            ->assertJsonMissingPath(
                'data.approval_steps.0.attachments.0.path',
            );
    }

    public function test_missing_request_returns_the_custom_not_found_response(): void
    {
        $branch = $this->branch('MISSING');

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson('/api/v1/admin/request-reports/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Request report not found.');
    }

    public function test_soft_deleted_request_is_not_available_to_admin(): void
    {
        $branch = $this->branch('DELETED');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);
        $requestForm->delete();

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->endpoint($requestForm))
            ->assertNotFound()
            ->assertJsonPath('message', 'Request report not found.');
    }

    public function test_identity_and_soft_delete_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->endpoint($requestForm).'?'.http_build_query([
            'user_id' => 1,
            'requester_id' => 1,
            'branch_id' => 1,
            'role' => 'Admin',
            'include_deleted' => true,
            'deleted_at' => '2026-11-16',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
                'role',
                'include_deleted',
                'deleted_at',
            ]);
    }

    private function endpoint(RequestForm $requestForm): string
    {
        return "/api/v1/admin/request-reports/{$requestForm->id}";
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

    private function user(
        Branch $branch,
        string $role = 'User',
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "ReportDetails{$number}",
            'lastName' => 'User',
            'contact' => "0920000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "report-details-user-{$number}",
            'employee_id' => "REPORT-DETAIL-{$number}",
            'signature' => 'signatures/private-signature.png',
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => null,
            'currency' => 'PHP',
            'form_data' => [
                'payee' => 'Learning Vendor',
                'account_no' => '0012345678',
                'bank' => 'Learning Bank',
                'swift_code' => 'LRNBPHMM',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-CIRS-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::CheckIssuance;
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
