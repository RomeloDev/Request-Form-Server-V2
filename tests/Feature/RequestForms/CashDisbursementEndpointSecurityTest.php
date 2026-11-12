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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashDisbursementEndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_guest_cannot_create_a_cash_disbursement(): void
    {
        $branch = $this->branch('GUEST');
        $approver = $this->user($branch, 'approver');

        $this->postJson(
            '/api/v1/request-forms/cash-disbursements',
            $this->createPayload([$approver->id]),
        )->assertUnauthorized();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_guest_cannot_update_a_cash_disbursement(): void
    {
        $this->patchJson(
            '/api/v1/request-forms/999999/cash-disbursement',
            [],
        )->assertUnauthorized();
    }

    public function test_another_user_cannot_update_a_cash_disbursement(): void
    {
        $branch = $this->branch('PRIVATE');
        $owner = $this->user($branch);
        $otherUser = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);

        Sanctum::actingAs($otherUser);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm, $approver),
        )->assertForbidden();

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'requester_id' => $owner->id,
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'status' => RequestStatus::Pending->value,
        ]);
    }

    public function test_creation_rejects_invalid_currency_and_server_owned_fields(): void
    {
        $branch = $this->branch('INVALID');
        $user = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/v1/request-forms/cash-disbursements',
            $this->createPayload(
                [$approver->id],
                ['currency' => 'GBP'],
            ),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $payload = $this->createPayload([$approver->id]);

        $payload['requester_id'] = 999999;
        $payload['branch_id'] = 999999;
        $payload['status'] = RequestStatus::Completed->value;
        $payload['grand_total'] = '0.01';
        $payload['request_code'] = 'FAKE-001';
        $payload['form_type'] = 'Stock Requisition Slip';
        $payload['form_data'] = [
            'supplier' => 'Injected supplier',
        ];
        $payload['approval_steps'] = [
            [
                'approver_id' => $approver->id,
                'level' => 99,
                'status' => 'Approved',
            ],
        ];

        $this->postJson(
            '/api/v1/request-forms/cash-disbursements',
            $payload,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'requester_id',
                'branch_id',
                'status',
                'grand_total',
                'request_code',
                'form_type',
                'form_data',
                'approval_steps',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
    }

    public function test_user_can_create_a_cash_disbursement(): void
    {
        Storage::fake('local');

        $branch = $this->branch('CREATE');
        $user = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $payload = $this->createPayload(
            [$approver->id],
            ['currency' => 'USD'],
        );

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'cash-voucher.pdf',
                100,
                'application/pdf',
            ),
        ];

        $response = $this->post(
            '/api/v1/request-forms/cash-disbursements',
            $payload,
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Cash Disbursement created successfully.',
            )
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath(
                'data.status',
                RequestStatus::Pending->value,
            )
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonPath('data.requester.id', $user->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            );

        /*
         * The Resource must not expose private storage details, user
         * contact data, or server-owned foreign keys.
         */
        $response
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path')
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester_id')
            ->assertJsonMissingPath('data.branch_id');

        $requestForm = RequestForm::query()->sole();

        $this->assertSame(
            RequestFormType::CashDisbursement,
            $requestForm->form_type,
        );
        $this->assertSame('USD', $requestForm->currency);
        $this->assertNull($requestForm->form_data);
        $this->assertSame('260.85', $requestForm->grand_total);
        $this->assertSame($user->id, $requestForm->requester_id);
        $this->assertSame($branch->id, $requestForm->branch_id);

        $this->assertStringStartsWith(
            "{$branch->branch_code}-CD-",
            $requestForm->request_code,
        );

        $attachment = $requestForm->attachments()->sole();

        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_owner_can_update_a_cash_disbursement(): void
    {
        $branch = $this->branch('UPDATE');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $newApprover = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $this->pendingApprovalStep($requestForm, $oldApprover);
        $originalCode = $requestForm->request_code;

        Sanctum::actingAs($owner);

        $response = $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm, $newApprover),
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Cash Disbursement updated successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.request_code', $originalCode)
            ->assertJsonPath('data.kind_of_request', 'Professional Fee')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath(
                'data.status',
                RequestStatus::Pending->value,
            )
            ->assertJsonPath('data.grand_total', '400.00')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '300.00')
            ->assertJsonPath('data.items.1.line_total', '100.00')
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $newApprover->id,
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'request_code' => $originalCode,
            'requester_id' => $owner->id,
            'branch_id' => $branch->id,
            'form_type' => RequestFormType::CashDisbursement->value,
            'kind_of_request' => 'Professional Fee',
            'currency' => 'USD',
            'status' => RequestStatus::Pending->value,
            'grand_total' => '400.00',
        ]);

        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $oldApprover->id,
        ]);
    }

    public function test_missing_cash_disbursement_returns_safe_not_found_response(): void
    {
        $owner = $this->user($this->branch('MISSING'));

        Sanctum::actingAs($owner);

        $this->patchJson(
            '/api/v1/request-forms/999999/cash-disbursement',
            [],
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    private function updateUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/cash-disbursement";
    }

    /**
     * Build valid Cash Disbursement creation input.
     *
     * 2 × 100.20 = 200.40
     * 3 × 20.15  =  60.45
     * Grand total = 260.85
     *
     * @param  list<int>  $approvedByIds
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createPayload(
        array $approvedByIds,
        array $overrides = [],
    ): array {
        return array_merge([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'noted_by_ids' => [],
            'approved_by_ids' => $approvedByIds,
            'items' => [
                [
                    'quantity' => '2',
                    'description' => 'A4 bond paper',
                    'unit_cost' => '100.20',
                    'remarks' => 'For Accounting',
                ],
                [
                    'quantity' => '3',
                    'description' => 'Black ballpens',
                    'unit_cost' => '20.15',
                    'remarks' => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(
        RequestForm $requestForm,
        User $approvedBy,
    ): array {
        return [
            'updated_at' => $requestForm->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'currency' => 'USD',
            'noted_by_ids' => [],
            'approved_by_ids' => [$approvedBy->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Updated service',
                    'unit_cost' => '150.00',
                    'remarks' => 'A4 size',
                ],
                [
                    'quantity' => 1,
                    'description' => 'Printer ink',
                    'unit_cost' => '100.00',
                    'remarks' => null,
                ],
            ],
        ];
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
    ): User {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "Cash{$number}",
            'lastName' => 'User',
            'contact' => "0913000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "cash-user-{$number}",
            'employee_id' => "CASH-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function cashDisbursement(
        User $requester,
        Branch $branch,
        RequestStatus $status = RequestStatus::Pending,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => null,
        ]);
        $requestForm->request_code = sprintf(
            '%s-CD-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::CashDisbursement;
        $requestForm->status = $status;
        $requestForm->grand_total = '0.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function pendingApprovalStep(
        RequestForm $requestForm,
        User $approver,
    ): void {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = ApprovalStepStatus::Pending;
        $requestForm->approvalSteps()->save($step);
    }
}
