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

class CashDisbursementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_ROUTE = '/api/v1/request-forms/cash-disbursements';

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

    public function test_cash_request_enters_the_generic_sequential_approval_workflow(): void
    {
        $branch = $this->branch('FLOW');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');
        $requestForm = $this->createCashRequest(
            $requester,
            [$firstApprover->id, $secondApprover->id],
            'USD',
        );

        Sanctum::actingAs($firstApprover);

        $this->getJson('/api/v1/approver/request-forms?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $requestForm->id)
            ->assertJsonPath('data.0.my_step.level', 1)
            ->assertJsonPath('data.0.my_step.status', 'Pending')
            ->assertJsonPath('data.0.my_step.can_process', true);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
            'comment' => '  Verified against the voucher.  ',
        ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request approved successfully.',
            )
            ->assertJsonPath('data.status', RequestStatus::Ongoing->value)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_total', '60.45')
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.comment',
                'Verified against the voucher.',
            )
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Pending->value,
            );

        Sanctum::actingAs($secondApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                RequestStatus::Completed->value,
            )
            ->assertJsonPath(
                'data.currency',
                'USD',
            )
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Approved->value,
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Completed->value,
            'currency' => 'USD',
            'form_type' => RequestFormType::CashDisbursement->value,
            'grand_total' => '260.85',
        ]);
        $this->assertSame(
            'Cash Disbursement Requisition Slip',
            $requestForm->fresh()->form_type->value,
        );
    }

    public function test_only_the_current_sequential_approver_can_act(): void
    {
        $branch = $this->branch('ORDER');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');
        $requestForm = $this->createCashRequest(
            $requester,
            [$firstApprover->id, $secondApprover->id],
        );

        Sanctum::actingAs($secondApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'It is not yet your turn to process this request.',
            );

        $steps = $requestForm->fresh()->approvalSteps;

        $this->assertSame(
            [
                ApprovalStepStatus::Pending,
                ApprovalStepStatus::Pending,
            ],
            $steps->pluck('status')->all(),
        );
        $this->assertSame(
            RequestStatus::Pending,
            $requestForm->fresh()->status,
        );
    }

    public function test_disapproval_skips_later_steps_and_ends_the_workflow(): void
    {
        $branch = $this->branch('REJECT');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');
        $requestForm = $this->createCashRequest(
            $requester,
            [$firstApprover->id, $secondApprover->id],
            'EUR',
        );

        Sanctum::actingAs($firstApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'disapprove',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'disapprove',
            'comment' => 'The voucher total does not match the items.',
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
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Disapproved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Skipped->value,
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

        $this->assertSame(
            ApprovalStepStatus::Skipped,
            $laterStep->fresh()->status,
        );
        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Disapproved->value,
            'currency' => 'EUR',
            'grand_total' => '260.85',
        ]);
    }

    public function test_repeated_decisions_are_rejected(): void
    {
        $branch = $this->branch('REPEAT');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->createCashRequest(
            $requester,
            [$approver->id],
        );

        Sanctum::actingAs($approver);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                RequestStatus::Completed->value,
            );

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This request can no longer be processed.',
            );
    }

    public function test_delegated_staff_attachment_follows_the_private_file_contract(): void
    {
        Storage::fake('local');

        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO');
        $requester = $this->user($requesterBranch);
        $avp = $this->user($headOffice, 'approver', 'AVP - Finance');
        $staff = $this->user($requesterBranch, 'approver');

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        $requestForm = $this->createCashRequest(
            $requester,
            [$avp->id],
            'EUR',
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
                    UploadedFile::fake()->image('signed-voucher.png'),
                ],
            ],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                RequestStatus::Ongoing->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::DelegatedApproval->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonCount(1, 'data.approval_steps.0.attachments')
            ->assertJsonPath(
                'data.approval_steps.0.attachments.0.original_name',
                'signed-voucher.png',
            )
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
     * Create a real Cash Disbursement through the protected endpoint.
     *
     * @param  list<int>  $approvedByIds
     */
    private function createCashRequest(
        User $requester,
        array $approvedByIds,
        string $currency = 'PHP',
    ): RequestForm {
        Sanctum::actingAs($requester);

        $this->postJson(self::STORE_ROUTE, [
            'kind_of_request' => 'Office Supplies',
            'currency' => $currency,
            'noted_by_ids' => [],
            'approved_by_ids' => $approvedByIds,
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'A4 bond paper',
                    'unit_cost' => '100.20',
                    'remarks' => null,
                ],
                [
                    'quantity' => 3,
                    'description' => 'Black ballpens',
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
            'firstName' => "CashFlow{$number}",
            'lastName' => 'User',
            'contact' => "0910000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "cash-flow-{$number}",
            'employee_id' => "CASH-FLOW-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
