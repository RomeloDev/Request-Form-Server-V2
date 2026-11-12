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
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdatePurchaseOrderTest extends TestCase
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
        $this->patchJson(
            '/api/v1/request-forms/999999/purchase-order',
            [],
        )->assertUnauthorized();
    }

    public function test_another_user_cannot_edit_the_request(): void
    {
        $branch = $this->branch('PRIVATE');
        $owner = $this->user($branch);
        $otherUser = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($otherUser);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->validPayload($requestForm, $approver),
        )->assertForbidden();
    }

    public function test_owner_can_edit_an_untouched_pending_purchase_order(): void
    {
        $branch = $this->branch('OWNER');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $notedBy = $this->user($branch, 'approver');
        $approvedBy = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $oldItem = $this->item($requestForm);
        $this->approvalStep($requestForm, $oldApprover);
        $attachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/quotation.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
        $originalCode = $requestForm->request_code;
        $originalRequesterId = $requestForm->requester_id;
        $originalBranchId = $requestForm->branch_id;

        Sanctum::actingAs($owner);

        $response = $this->patchJson(
            $this->updateUrl($requestForm),
            $this->validPayload(
                requestForm: $requestForm,
                approvedBy: $approvedBy,
                notedBy: $notedBy,
            ),
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Purchase Order updated successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.request_code', $originalCode)
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.kind_of_request', 'Professional Fee')
            ->assertJsonPath('data.form_data.supplier', 'Updated Supplier')
            ->assertJsonPath('data.form_data.address', 'Updated Address')
            ->assertJsonPath('data.grand_total', '400.00')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '300.00')
            ->assertJsonPath('data.items.1.line_total', '100.00')
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::NotedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $notedBy->id,
            )
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $approvedBy->id,
            );

        $this->assertDatabaseMissing('request_items', [
            'request_form_id' => $requestForm->id,
            'description' => $oldItem->description,
        ]);
        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $oldApprover->id,
        ]);
        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'request_code' => $originalCode,
            'requester_id' => $originalRequesterId,
            'branch_id' => $originalBranchId,
            'status' => RequestStatus::Pending->value,
            'grand_total' => '400.00',
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $attachment->id,
            'request_form_id' => $requestForm->id,
        ]);
        $this->assertDatabaseHas('suppliers', [
            'name' => 'Updated Supplier',
            'address' => 'Updated Address',
        ]);
    }

    public function test_owner_can_edit_and_resubmit_a_disapproved_purchase_order(): void
    {
        $branch = $this->branch('RESUBMIT');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $newApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm(
            $owner,
            $branch,
            RequestStatus::Disapproved,
        );
        $this->approvalStep(
            requestForm: $requestForm,
            approver: $oldApprover,
            status: ApprovalStepStatus::Disapproved,
            actedAt: now(),
        );

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->validPayload($requestForm, $newApprover),
        )
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Pending->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $newApprover->id,
            );
    }

    public function test_editing_an_existing_supplier_updates_its_address_without_a_duplicate(): void
    {
        $branch = $this->branch('SUPPLIER');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $payload = $this->validPayload($requestForm, $approver);
        $payload['form_data'] = [
            'supplier' => '  Old Supplier  ',
            'address' => '  New Supplier Address  ',
        ];

        Sanctum::actingAs($owner);

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertOk()
            ->assertJsonPath('data.form_data.supplier', 'Old Supplier')
            ->assertJsonPath(
                'data.form_data.address',
                'New Supplier Address',
            );

        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseHas('suppliers', [
            'name' => 'Old Supplier',
            'address' => 'New Supplier Address',
        ]);
    }

    #[DataProvider('nonEditableStatusProvider')]
    public function test_non_editable_status_is_rejected(RequestStatus $status): void
    {
        $branch = $this->branch('STATUS');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch, $status);

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->validPayload($requestForm, $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only pending or disapproved requests can be edited.',
            );
    }

    /** @return array<string, array{RequestStatus}> */
    public static function nonEditableStatusProvider(): array
    {
        return [
            'ongoing' => [RequestStatus::Ongoing],
            'completed' => [RequestStatus::Completed],
            'canceled' => [RequestStatus::Canceled],
        ];
    }

    public function test_pending_request_with_approval_activity_cannot_be_edited(): void
    {
        $branch = $this->branch('ACTED');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            status: ApprovalStepStatus::Approved,
            actedAt: now(),
        );

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->validPayload($requestForm, $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'A pending request cannot be edited after approval activity has started.',
            );
    }

    public function test_stale_request_version_is_rejected(): void
    {
        $branch = $this->branch('STALE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $payload = $this->validPayload($requestForm, $approver);
        $payload['updated_at'] = $requestForm->updated_at
            ?->copy()
            ->subMinute()
            ->toISOString();

        Sanctum::actingAs($owner);

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This request has changed since you opened it. Refresh and try again.',
            );
    }

    public function test_stock_requisition_cannot_be_updated_through_purchase_order_route(): void
    {
        $branch = $this->branch('WRONG-TYPE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->save();

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->validPayload($requestForm->fresh(), $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only Purchase Order requests can be edited.',
            );
    }

    public function test_required_and_server_owned_fields_are_validated(): void
    {
        $branch = $this->branch('VALIDATE');
        $owner = $this->user($branch);
        $requestForm = $this->requestForm($owner, $branch);

        Sanctum::actingAs($owner);

        $this->patchJson($this->updateUrl($requestForm), [
            'updated_at' => 'not-a-date',
            'kind_of_request' => 'Not approved',
            'form_data' => ['supplier' => '', 'address' => ''],
            'approved_by_ids' => [],
            'items' => [],
            'id' => 999,
            'user_id' => 999,
            'requester_id' => 999,
            'branch_id' => 999,
            'supplier_id' => 999,
            'request_code' => 'ATTACK-0000001',
            'form_type' => 'Changed',
            'currency' => 'USD',
            'status' => RequestStatus::Completed->value,
            'grand_total' => '1.00',
            'approval_steps' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'updated_at',
                'kind_of_request',
                'form_data.supplier',
                'form_data.address',
                'approved_by_ids',
                'items',
                'id',
                'user_id',
                'requester_id',
                'branch_id',
                'supplier_id',
                'request_code',
                'form_type',
                'currency',
                'status',
                'grand_total',
                'approval_steps',
            ]);
    }

    public function test_owner_can_replace_an_existing_attachment(): void
    {
        Storage::fake('local');

        $branch = $this->branch('FILE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $oldAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/old.pdf",
            'original_name' => 'old.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($oldAttachment->path, 'old');

        Sanctum::actingAs($owner);

        $payload = $this->validPayload($requestForm, $approver);
        $payload['_method'] = 'PATCH';
        $payload['removed_attachment_ids'] = [$oldAttachment->id];
        $payload['attachments'] = [
            UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf'),
        ];

        $this->post(
            $this->updateUrl($requestForm),
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'replacement.pdf',
            );

        $this->assertDatabaseMissing('request_attachments', [
            'id' => $oldAttachment->id,
        ]);
        Storage::disk('local')->assertMissing($oldAttachment->path);
    }

    public function test_attachment_from_another_request_cannot_be_removed(): void
    {
        $branch = $this->branch('FOREIGN-FILE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($owner, $branch);
        $otherRequest = $this->requestForm($owner, $branch);
        $foreignAttachment = $otherRequest->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$otherRequest->id}/private.pdf",
            'original_name' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);

        Sanctum::actingAs($owner);

        $payload = $this->validPayload($requestForm, $approver);
        $payload['removed_attachment_ids'] = [$foreignAttachment->id];

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['removed_attachment_ids.0']);

        $this->assertDatabaseHas('request_attachments', [
            'id' => $foreignAttachment->id,
            'request_form_id' => $otherRequest->id,
        ]);
    }

    public function test_missing_request_returns_custom_not_found_response(): void
    {
        $owner = $this->user($this->branch('MISSING'));
        Sanctum::actingAs($owner);

        $this->patchJson(
            '/api/v1/request-forms/999999/purchase-order',
            [],
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    private function updateUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/purchase-order";
    }

    /** @return array<string, mixed> */
    private function validPayload(
        RequestForm $requestForm,
        User $approvedBy,
        ?User $notedBy = null,
    ): array {
        return [
            'updated_at' => $requestForm->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'form_data' => [
                'supplier' => '  Updated Supplier  ',
                'address' => '  Updated Address  ',
            ],
            'noted_by_ids' => $notedBy ? [$notedBy->id] : [],
            'approved_by_ids' => [$approvedBy->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Updated item',
                    'unit_cost' => '150.00',
                    'remarks' => 'Required',
                ],
                [
                    'quantity' => 1,
                    'description' => 'Second item',
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
            'firstName' => "EditPo{$number}",
            'lastName' => 'User',
            'contact' => "0917000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "edit-po-{$number}",
            'employee_id' => "EDIT-PO-{$number}",
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
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => [
                'supplier' => 'Old Supplier',
                'address' => 'Old Address',
            ],
        ]);
        $requestForm->request_code = sprintf(
            '%s-PO-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::PurchaseOrder;
        $requestForm->status = $status;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        Supplier::firstOrCreate(
            ['name' => 'Old Supplier'],
            ['address' => 'Old Address'],
        );

        return $requestForm;
    }

    private function item(RequestForm $requestForm): RequestItem
    {
        $item = new RequestItem([
            'quantity' => 2,
            'description' => 'Old item',
            'unit_cost' => '125.00',
            'remarks' => null,
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $requestForm->items()->save($item);

        return $item;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        ApprovalStepStatus $status = ApprovalStepStatus::Pending,
        mixed $actedAt = null,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = $status;
        $step->acted_at = $actedAt;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
