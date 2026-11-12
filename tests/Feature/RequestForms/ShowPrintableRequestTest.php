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

class ShowPrintableRequestTest extends TestCase
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
        $this->getJson('/api/v1/request-forms/999999/print')
            ->assertUnauthorized();
    }

    public function test_owner_without_an_application_role_is_forbidden(): void
    {
        $branch = $this->branch('NO-ROLE');
        $owner = $this->user($branch, role: null);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($owner);

        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
    }

    public function test_unassigned_user_cannot_view_printable_request(): void
    {
        $branch = $this->branch('PRIVATE');
        $owner = $this->user($branch);
        $unassignedApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($unassignedApprover);

        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
    }

    public function test_assigned_approver_can_view_printable_request(): void
    {
        $branch = $this->branch('APPROVER');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);

        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            level: 1,
            type: ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($approver);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.id', $requestForm->id);
    }

    public function test_owner_receives_only_the_print_template_contract(): void
    {
        $branch = $this->branch('PRINT');
        $owner = $this->user($branch, signature: 'signatures/requester.png');
        $notedBy = $this->user(
            $branch,
            'approver',
            'Branch Manager',
            'signatures/noted-by.png',
        );
        $approvedBy = $this->user(
            $branch,
            'approver',
            'Area Manager',
            'signatures/approved-by.png',
        );
        $requestForm = $this->requestForm($owner, $branch);

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

        $this->approvalStep(
            requestForm: $requestForm,
            approver: $notedBy,
            level: 1,
            type: ApprovalStepType::NotedBy,
            status: ApprovalStepStatus::Approved,
        );
        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approvedBy,
            level: 2,
            type: ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($owner);

        $response = $this->getJson($this->printUrl($requestForm));

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Printable request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.request_code',
                $requestForm->request_code,
            )
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::StockRequisition->value,
            )
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.purpose', 'Office use')
            ->assertJsonPath('data.grand_total', '250.00')
            ->assertJsonPath('data.branch.branch_code', $branch->branch_code)
            ->assertJsonPath('data.branch.branch_name', $branch->branch_name)
            ->assertJsonPath('data.branch.branch', $branch->branch)
            ->assertJsonPath('data.branch.acronym', $branch->acronym)
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonPath('data.requester.firstname', $owner->firstName)
            ->assertJsonPath('data.requester.lastname', $owner->lastName)
            ->assertJsonPath('data.requester.position', $owner->position)
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.description', 'Bond paper')
            ->assertJsonPath('data.items.0.unit_cost', '125.00')
            ->assertJsonPath('data.items.0.line_total', '250.00')
            ->assertJsonPath('data.items.0.remarks', 'A4 size')
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::NotedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $notedBy->id,
            )
            ->assertJsonPath('data.approval_steps.1.level', 2)
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $approvedBy->id,
            )
            ->assertJsonPath('data.created_at', $requestForm->created_at?->toISOString())
            ->assertJsonMissingPath('data.form_data')
            ->assertJsonMissingPath('data.attachments')
            ->assertJsonMissingPath('data.cancellation_reason')
            ->assertJsonMissingPath('data.requester.signature')
            ->assertJsonMissingPath('data.approval_steps.0.approver.signature');
    }

    public function test_purchase_order_print_contract_includes_only_supplier_fields(): void
    {
        $branch = $this->branch('PURCHASE');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm(
            requester: $owner,
            branch: $branch,
            formType: RequestFormType::PurchaseOrder,
            formData: [
                'supplier' => '  Metro Office Supplies  ',
                'address' => '  123 Rizal Avenue, Davao City  ',
                'internal_note' => 'Must not be exposed.',
            ],
        );

        Sanctum::actingAs($owner);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::PurchaseOrder->value,
            )
            ->assertJsonPath(
                'data.form_data.supplier',
                'Metro Office Supplies',
            )
            ->assertJsonPath(
                'data.form_data.address',
                '123 Rizal Avenue, Davao City',
            )
            ->assertJsonMissingPath('data.form_data.internal_note');
    }

    #[DataProvider('printableStatusProvider')]
    public function test_every_non_canceled_status_is_printable(
        RequestStatus $status,
    ): void {
        $branch = $this->branch('STATUS');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch, $status);

        Sanctum::actingAs($owner);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.status', $status->value);
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
    public static function printableStatusProvider(): array
    {
        return [
            'pending' => [RequestStatus::Pending],
            'ongoing' => [RequestStatus::Ongoing],
            'completed' => [RequestStatus::Completed],
            'disapproved' => [RequestStatus::Disapproved],
        ];
    }

    public function test_canceled_request_is_not_printable(): void
    {
        $branch = $this->branch('CANCELED');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm(
            $owner,
            $branch,
            RequestStatus::Canceled,
        );

        Sanctum::actingAs($owner);

        $this->getJson($this->printUrl($requestForm))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Canceled requests cannot be printed.',
            );
    }

    public function test_missing_request_returns_the_custom_not_found_response(): void
    {
        $user = $this->user($this->branch('MISSING'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/request-forms/999999/print')
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($owner);

        $this->getJson(
            $this->printUrl($requestForm)
            .'?user_id=1&requester_id=1&branch_id=1',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
            ]);
    }

    private function printUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/print";
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepType $type,
        ApprovalStepStatus $status = ApprovalStepStatus::Pending,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = $type;
        $step->status = $status;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$code}-{$number}",
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Strong Moto Centrum, Inc.',
            'acronym' => 'SMCT',
        ]);
    }

    private function user(
        Branch $branch,
        ?string $role = 'User',
        string $position = 'Employee',
        string $signature = 'signatures/test-user.png',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Print{$number}",
            'lastName' => 'User',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "print-user-{$number}",
            'employee_id' => "PRINT-EMP-{$number}",
            'signature' => $signature,
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
        RequestFormType $formType = RequestFormType::StockRequisition,
        ?array $formData = null,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => $formData ?? [
                'purpose' => 'Office use',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-%s-%07d',
            $branch->branch_code,
            $formType === RequestFormType::PurchaseOrder ? 'PO' : 'SRL',
            ++$this->sequence,
        );
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
