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

class CheckIssuanceEndpointSecurityTest extends TestCase
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

    public function test_guest_cannot_access_check_issuance_write_or_bank_endpoints(): void
    {
        $this->getJson('/api/v1/banks')->assertUnauthorized();

        $this->postJson(self::STORE_ROUTE, [])->assertUnauthorized();

        $this->patchJson(
            '/api/v1/request-forms/999999/check-issuance',
            [],
        )->assertUnauthorized();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_another_user_cannot_update_the_request(): void
    {
        $branch = $this->branch('PRIVATE');
        $owner = $this->user($branch);
        $otherUser = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->checkIssuance($owner, $branch);
        $this->pendingApprovalStep($requestForm, $approver);

        Sanctum::actingAs($otherUser);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm, $approver),
        )->assertForbidden();

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'requester_id' => $owner->id,
            'kind_of_request' => 'Office Supplies',
            'status' => RequestStatus::Pending->value,
        ]);
    }

    public function test_creation_rejects_wrong_header_shape_and_server_owned_fields(): void
    {
        $branch = $this->branch('INVALID');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->createPayload([$approver->id]);
        $payload['currency'] = 'USD';
        $payload['requester_id'] = 999999;
        $payload['branch_id'] = 999999;
        $payload['status'] = RequestStatus::Completed->value;
        $payload['grand_total'] = '0.01';
        $payload['request_code'] = 'FAKE-CIRS-001';
        $payload['form_type'] = RequestFormType::CashDisbursement->value;
        $payload['form_data']['supplier'] = 'Injected supplier';
        $payload['items'][0]['line_total'] = '0.01';
        $payload['approval_steps'] = [
            [
                'approver_id' => $approver->id,
                'level' => 99,
                'status' => 'Approved',
            ],
        ];

        $this->postJson(self::STORE_ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'currency',
                'requester_id',
                'branch_id',
                'status',
                'grand_total',
                'request_code',
                'form_type',
                'form_data',
                'items.0.line_total',
                'approval_steps',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('banks', 0);
    }

    public function test_user_can_create_a_server_controlled_check_issuance(): void
    {
        Storage::fake('local');

        $branch = $this->branch('CREATE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->createPayload([$approver->id]);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'check-support.pdf',
                100,
                'application/pdf',
            ),
        ];

        $response = $this->post(
            self::STORE_ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Check Issuance created successfully.',
            )
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
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_total', '60.45')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path')
            ->assertJsonMissingPath('data.requester_id')
            ->assertJsonMissingPath('data.branch_id');

        $requestForm = RequestForm::query()->sole();

        $this->assertMatchesRegularExpression(
            "/^{$branch->branch_code}-CIRS-\d{7}$/",
            $requestForm->request_code,
        );
        $this->assertSame($requester->id, $requestForm->requester_id);
        $this->assertSame($branch->id, $requestForm->branch_id);
        $this->assertDatabaseHas('banks', ['name' => 'Example Bank']);
        $this->assertDatabaseCount('banks', 1);

        $attachment = $requestForm->attachments()->sole();
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_owner_can_update_fields_bank_items_routing_and_attachments(): void
    {
        Storage::fake('local');

        $branch = $this->branch('UPDATE');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $newApprover = $this->user($branch, 'approver');
        $requestForm = $this->checkIssuance($owner, $branch);
        $this->pendingApprovalStep($requestForm, $oldApprover);
        $oldAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/old.pdf",
            'original_name' => 'old.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($oldAttachment->path, 'old file');
        $originalCode = $requestForm->request_code;

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm->fresh(), $newApprover);
        unset($payload['form_data']['swift_code']);
        $payload['_method'] = 'PATCH';
        $payload['removed_attachment_ids'] = [$oldAttachment->id];
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'replacement.pdf',
                100,
                'application/pdf',
            ),
        ];

        $response = $this->post(
            $this->updateUrl($requestForm),
            $payload,
            ['Accept' => 'application/json'],
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Check Issuance updated successfully.',
            )
            ->assertJsonPath('data.request_code', $originalCode)
            ->assertJsonPath('data.kind_of_request', 'Professional Fee')
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.form_data.payee', 'Updated Payee')
            ->assertJsonPath('data.form_data.bank', 'Updated Bank')
            ->assertJsonPath('data.form_data.account_no', '00009999')
            ->assertJsonPath('data.form_data.swift_code', null)
            ->assertJsonPath('data.grand_total', '400.00')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'replacement.pdf',
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $newApprover->id,
            );

        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $oldApprover->id,
        ]);
        $this->assertDatabaseHas('banks', ['name' => 'Updated Bank']);
        Storage::disk('local')->assertMissing($oldAttachment->path);

        $newAttachment = $requestForm->fresh()->attachments()->sole();
        Storage::disk('local')->assertExists($newAttachment->path);
    }

    public function test_stale_wrong_type_and_started_approval_updates_are_rejected(): void
    {
        $branch = $this->branch('GUARDS');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->checkIssuance($owner, $branch);
        $step = $this->pendingApprovalStep($requestForm, $approver);

        Sanctum::actingAs($owner);

        $stalePayload = $this->updatePayload($requestForm, $approver);
        $stalePayload['updated_at'] = now()->subDay()->toISOString();

        $this->patchJson($this->updateUrl($requestForm), $stalePayload)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This request has changed since you opened it. Refresh and try again.',
            );

        $step->status = ApprovalStepStatus::Approved;
        $step->acted_at = now();
        $step->save();

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm->fresh(), $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'A pending request cannot be edited after approval activity has started.',
            );

        $cashRequest = $this->checkIssuance($owner, $branch);
        $cashRequest->form_type = RequestFormType::CashDisbursement;
        $cashRequest->save();

        $this->patchJson(
            $this->updateUrl($cashRequest),
            $this->updatePayload($cashRequest, $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only Check Issuance requests can be edited.',
            );
    }

    public function test_missing_request_returns_safe_not_found_response(): void
    {
        Sanctum::actingAs($this->user($this->branch('MISSING')));

        $this->patchJson(
            '/api/v1/request-forms/999999/check-issuance',
            [],
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    private function updateUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/check-issuance";
    }

    /**
     * @param  list<int>  $approvedByIds
     * @return array<string, mixed>
     */
    private function createPayload(array $approvedByIds): array
    {
        return [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'payee' => 'Example Payee',
                'bank' => 'Example Bank',
                'account_no' => '0012-3456-7890',
                'swift_code' => 'EXAMPHMM',
            ],
            'noted_by_ids' => [],
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
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(
        RequestForm $requestForm,
        User $approvedBy,
    ): array {
        return [
            'updated_at' => $requestForm->fresh()->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'form_data' => [
                'payee' => 'Updated Payee',
                'bank' => 'Updated Bank',
                'account_no' => '00009999',
                'swift_code' => 'UPDTPHMM',
            ],
            'noted_by_ids' => [],
            'approved_by_ids' => [$approvedBy->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Updated service',
                    'unit_cost' => '150.00',
                    'remarks' => null,
                ],
                [
                    'quantity' => 1,
                    'description' => 'Processing fee',
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

    private function user(Branch $branch, ?string $role = 'User'): User
    {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'firstName' => "CheckEndpoint{$number}",
            'lastName' => 'User',
            'contact' => "0913000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "check-endpoint-{$number}",
            'employee_id' => "CHECK-ENDPOINT-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function checkIssuance(
        User $requester,
        Branch $branch,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => [
                'payee' => 'Original Payee',
                'bank' => 'Original Bank',
                'account_no' => '00112233',
                'swift_code' => 'ORIGPHMM',
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

    private function pendingApprovalStep(
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
