<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\RequestApprovalAttachment;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CheckIssuanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_ROUTE = '/api/v1/request-forms/check-issuances';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_check_issuance_enters_the_generic_sequential_approval_workflow(): void
    {
        $branch = $this->branch('FLOW');
        $requester = $this->user($branch);
        $notedBy = $this->user($branch, 'approver');
        $firstApprovedBy = $this->user($branch, 'approver');
        $secondApprovedBy = $this->user($branch, 'approver');
        $requestForm = $this->createCheckRequest(
            requester: $requester,
            approvedByIds: [$firstApprovedBy->id, $secondApprovedBy->id],
            notedByIds: [$notedBy->id],
        );

        $this->assertSame(
            [
                ApprovalStepType::NotedBy,
                ApprovalStepType::ApprovedBy,
                ApprovalStepType::ApprovedBy,
            ],
            $requestForm->approvalSteps->pluck('step_type')->all(),
        );

        Sanctum::actingAs($firstApprovedBy);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'It is not yet your turn to process this request.',
            );

        Sanctum::actingAs($notedBy);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
            'comment' => '  Check details noted.  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Ongoing->value)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CheckIssuance->value,
            )
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.form_data.payee', 'Example Payee')
            ->assertJsonPath('data.form_data.bank', 'Example Bank')
            ->assertJsonPath(
                'data.form_data.account_no',
                '0012-3456-7890',
            )
            ->assertJsonPath('data.form_data.swift_code', 'EXAMPHMM')
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonPath(
                'data.approval_steps.0.comment',
                'Check details noted.',
            );

        Sanctum::actingAs($firstApprovedBy);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Ongoing->value);

        Sanctum::actingAs($secondApprovedBy);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Completed->value)
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.grand_total', '260.85');

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'form_type' => RequestFormType::CheckIssuance->value,
            'currency' => 'PHP',
            'status' => RequestStatus::Completed->value,
            'grand_total' => '260.85',
        ]);
    }

    public function test_disapproval_skips_later_steps_and_ends_the_workflow(): void
    {
        $branch = $this->branch('REJECT');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');
        $requestForm = $this->createCheckRequest(
            requester: $requester,
            approvedByIds: [$firstApprover->id, $secondApprover->id],
        );

        Sanctum::actingAs($firstApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'disapprove',
            'comment' => 'The account details require correction.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request disapproved successfully.',
            )
            ->assertJsonPath(
                'data.status',
                RequestStatus::Disapproved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Disapproved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Skipped->value,
            )
            ->assertJsonPath(
                'data.form_data.account_no',
                '0012-3456-7890',
            );

        $laterStep = $requestForm
            ->fresh()
            ->approvalSteps
            ->firstWhere('level', 2);

        $this->assertSame(ApprovalStepStatus::Skipped, $laterStep->status);
        $this->assertNull($laterStep->acted_at);

        Sanctum::actingAs($secondApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This request can no longer be processed.',
            );
    }

    public function test_avp_delegated_staff_is_inserted_and_can_store_a_private_approval_attachment(): void
    {
        Storage::fake('local');

        $requesterBranch = $this->branch('BRANCH');
        $headOffice = $this->branch('HEAD-OFFICE');
        $requester = $this->user($requesterBranch);
        $avp = $this->user($headOffice, 'approver', 'AVP - Finance');
        $staff = $this->user($requesterBranch, 'approver');
        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        $requestForm = $this->createCheckRequest(
            requester: $requester,
            approvedByIds: [$avp->id],
        );

        $this->assertSame(
            [
                ApprovalStepType::DelegatedApproval,
                ApprovalStepType::ApprovedBy,
            ],
            $requestForm->approvalSteps->pluck('step_type')->all(),
        );

        Sanctum::actingAs($staff);

        $this->post(
            $this->decisionUrl($requestForm),
            [
                '_method' => 'PATCH',
                'action' => 'approve',
                'attachments' => [
                    UploadedFile::fake()->image('signed-check-review.png'),
                ],
            ],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Ongoing->value)
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::DelegatedApproval->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonCount(1, 'data.approval_steps.0.attachments')
            ->assertJsonMissingPath(
                'data.approval_steps.0.attachments.0.disk',
            )
            ->assertJsonMissingPath(
                'data.approval_steps.0.attachments.0.path',
            );

        $attachment = RequestApprovalAttachment::query()->sole();

        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * @param  list<int>  $approvedByIds
     * @param  list<int>  $notedByIds
     */
    private function createCheckRequest(
        User $requester,
        array $approvedByIds,
        array $notedByIds = [],
    ): RequestForm {
        Sanctum::actingAs($requester);

        $this->postJson(self::STORE_ROUTE, [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'payee' => 'Example Payee',
                'bank' => 'Example Bank',
                'account_no' => '0012-3456-7890',
                'swift_code' => 'EXAMPHMM',
            ],
            'noted_by_ids' => $notedByIds,
            'approved_by_ids' => $approvedByIds,
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'First payment item',
                    'unit_cost' => '100.20',
                    'remarks' => null,
                ],
                [
                    'quantity' => 3,
                    'description' => 'Second payment item',
                    'unit_cost' => '20.15',
                    'remarks' => null,
                ],
            ],
        ])->assertCreated();

        return RequestForm::query()->sole();
    }

    private function decisionUrl(RequestForm $requestForm): string
    {
        return "/api/v1/approver/request-forms/{$requestForm->id}/decision";
    }

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$code}-{$number}",
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Test Company',
            'acronym' => 'TEST',
        ]);
    }

    private function user(
        Branch $branch,
        ?string $role = 'User',
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "CheckFlow{$number}",
            'lastName' => 'User',
            'contact' => "0911000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "check-flow-{$number}",
            'employee_id' => "CHECK-FLOW-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
