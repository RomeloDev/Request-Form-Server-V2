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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CancelMyRequestTest extends TestCase
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

    public function test_requester_can_cancel_a_pending_request_without_deleting_its_data(): void
    {
        $branch = $this->branch('OWNER');
        $requester = $this->user($branch);
        $firstApprover = $this->user($branch, 'approver');
        $secondApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $item = $this->item($requestForm);
        $attachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/quotation.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
        $firstStep = $this->approvalStep(
            requestForm: $requestForm,
            approver: $firstApprover,
            level: 1,
            type: ApprovalStepType::NotedBy,
        );
        $secondStep = $this->approvalStep(
            requestForm: $requestForm,
            approver: $secondApprover,
            level: 2,
            type: ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'The requested items are no longer needed.'],
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request canceled successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.status',
                RequestStatus::Canceled->value,
            )
            ->assertJsonPath(
                'data.cancellation_reason',
                'The requested items are no longer needed.',
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Canceled->value,
            'cancellation_reason' => 'The requested items are no longer needed.',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('request_items', [
            'id' => $item->id,
            'request_form_id' => $requestForm->id,
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $attachment->id,
            'request_form_id' => $requestForm->id,
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $firstStep->id,
            'status' => ApprovalStepStatus::Canceled->value,
            'acted_at' => null,
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $secondStep->id,
            'status' => ApprovalStepStatus::Canceled->value,
            'acted_at' => null,
        ]);

        $this->getJson("/api/v1/request-forms/{$requestForm->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.cancellation_reason',
                'The requested items are no longer needed.',
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Canceled->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Canceled->value,
            );
    }

    public function test_guest_cannot_cancel_a_request(): void
    {
        $branch = $this->branch('GUEST');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'No longer required.'],
        )->assertUnauthorized();
    }

    public function test_another_user_cannot_cancel_the_request(): void
    {
        $branch = $this->branch('PRIVATE');
        $requester = $this->user($branch);
        $otherUser = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($otherUser);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'Attempted by another user.'],
        )->assertForbidden();

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Pending->value,
        ]);
    }

    public function test_owner_without_an_application_role_cannot_cancel_the_request(): void
    {
        $branch = $this->branch('NO-ROLE');
        $requester = $this->user($branch, role: null);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'No longer required.'],
        )->assertForbidden();
    }

    #[DataProvider('nonPendingStatusProvider')]
    public function test_non_pending_request_cannot_be_canceled(
        RequestStatus $status,
    ): void {
        $branch = $this->branch('STATUS');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: $status,
        );

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'Attempted invalid transition.'],
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only pending requests can be canceled.',
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => $status->value,
        ]);
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
    public static function nonPendingStatusProvider(): array
    {
        return [
            'ongoing' => [RequestStatus::Ongoing],
            'completed' => [RequestStatus::Completed],
            'disapproved' => [RequestStatus::Disapproved],
            'already canceled' => [RequestStatus::Canceled],
        ];
    }

    public function test_pending_request_with_approval_activity_cannot_be_canceled(): void
    {
        $branch = $this->branch('ACTED');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);
        $step = $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            level: 1,
            type: ApprovalStepType::NotedBy,
            status: ApprovalStepStatus::Approved,
            actedAt: now(),
        );

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'Attempted after approval activity.'],
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'A request cannot be canceled after approval activity has started.',
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Pending->value,
            'cancellation_reason' => null,
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $step->id,
            'status' => ApprovalStepStatus::Approved->value,
        ]);
    }

    public function test_cancellation_reason_is_required(): void
    {
        $branch = $this->branch('REASON');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->patchJson($this->cancelUrl($requestForm), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cancellation_reason',
            ]);

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Pending->value,
        ]);
    }

    public function test_invalid_and_server_owned_fields_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            [
                'cancellation_reason' => ['not', 'a', 'string'],
                'user_id' => 1,
                'requester_id' => 1,
                'branch_id' => 1,
                'status' => RequestStatus::Canceled->value,
                'approval_steps' => [
                    [
                        'status' => ApprovalStepStatus::Canceled->value,
                    ],
                ],
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cancellation_reason',
                'user_id',
                'requester_id',
                'branch_id',
                'status',
                'approval_steps',
            ]);
    }

    public function test_cancellation_reason_cannot_exceed_one_thousand_characters(): void
    {
        $branch = $this->branch('LENGTH');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => str_repeat('a', 1001)],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cancellation_reason',
            ]);
    }

    public function test_canceling_a_request_updates_my_request_summary_counts(): void
    {
        $branch = $this->branch('SUMMARY');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->patchJson(
            $this->cancelUrl($requestForm),
            ['cancellation_reason' => 'The request is no longer necessary.'],
        )->assertOk();

        $this->getJson('/api/v1/request-forms')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.pending', 0)
            ->assertJsonPath('summary.canceled', 1)
            ->assertJsonPath(
                'data.0.status',
                RequestStatus::Canceled->value,
            )
            ->assertJsonPath(
                'data.0.cancellation_reason',
                'The request is no longer necessary.',
            );
    }

    private function cancelUrl(RequestForm $requestForm): string
    {
        return route(
            'request-forms.cancel',
            ['requestForm' => $requestForm],
            absolute: false,
        );
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
        ?string $role = 'User',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Cancel{$number}",
            'lastName' => 'Requester',
            'contact' => "0915000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "cancel-requester-{$number}",
            'employee_id' => "CANCEL-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        RequestStatus $status = RequestStatus::Pending,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Office use',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function item(RequestForm $requestForm): RequestItem
    {
        $item = new RequestItem([
            'quantity' => 2,
            'unit' => 'box',
            'description' => 'Bond paper',
            'unit_cost' => '125.00',
            'remarks' => 'A4 size',
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        return $item;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepType $type,
        ApprovalStepStatus $status = ApprovalStepStatus::Pending,
        mixed $actedAt = null,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = $type;
        $step->status = $status;
        $step->acted_at = $actedAt;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
