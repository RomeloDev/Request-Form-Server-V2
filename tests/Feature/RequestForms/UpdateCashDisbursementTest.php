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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateCashDisbursementTest extends TestCase
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

    public function test_owner_can_update_an_untouched_pending_request(): void
    {
        $branch = $this->branch('EDIT');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $notedBy = $this->user($branch, 'approver');
        $approvedBy = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $oldItem = $this->item($requestForm, 'Old item');
        $this->approvalStep($requestForm, $oldApprover);
        $retainedAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/retained.pdf",
            'original_name' => 'retained.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        $originalCode = $requestForm->request_code;

        Sanctum::actingAs($owner);

        $response = $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload(
                requestForm: $requestForm,
                approvedBy: $approvedBy,
                notedBy: $notedBy,
            ),
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Cash Disbursement updated successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.request_code', $originalCode)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.kind_of_request', 'Professional Fee')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.grand_total', '400.00')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.description', 'Updated service')
            ->assertJsonPath('data.items.0.line_total', '300.00')
            ->assertJsonPath('data.items.1.line_number', 2)
            ->assertJsonPath('data.items.1.description', 'Printer ink')
            ->assertJsonPath('data.items.1.line_total', '100.00')
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::NotedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $notedBy->id,
            )
            ->assertJsonPath('data.approval_steps.1.level', 2)
            ->assertJsonPath(
                'data.approval_steps.1.step_type',
                ApprovalStepType::ApprovedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $approvedBy->id,
            )
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath(
                'data.attachments.0.id',
                $retainedAttachment->id,
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'request_code' => $originalCode,
            'requester_id' => $owner->id,
            'branch_id' => $branch->id,
            'form_type' => RequestFormType::CashDisbursement->value,
            'form_data' => null,
            'kind_of_request' => 'Professional Fee',
            'currency' => 'USD',
            'status' => RequestStatus::Pending->value,
            'grand_total' => '400.00',
        ]);

        $this->assertDatabaseMissing('request_items', [
            'id' => $oldItem->id,
        ]);
        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $oldApprover->id,
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $retainedAttachment->id,
            'request_form_id' => $requestForm->id,
        ]);
    }

    #[DataProvider('editableCurrencyProvider')]
    public function test_owner_can_change_the_currency(string $currency): void
    {
        $branch = $this->branch('CURRENCY');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload(
                requestForm: $requestForm,
                approvedBy: $approver,
                overrides: ['currency' => $currency],
            ),
        )
            ->assertOk()
            ->assertJsonPath('data.currency', $currency);

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'currency' => $currency,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function editableCurrencyProvider(): array
    {
        return [
            'eur' => ['EUR'],
            'usd' => ['USD'],
        ];
    }

    public function test_update_currency_is_required_and_allowlisted(): void
    {
        $branch = $this->branch('BAD-CURRENCY');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        unset($payload['currency']);

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $payload = $this->updatePayload(
            requestForm: $requestForm,
            approvedBy: $approver,
            overrides: ['currency' => 'GBP'],
        );

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'currency' => 'PHP',
            'kind_of_request' => 'Office Supplies',
            'grand_total' => '250.00',
        ]);
    }

    public function test_owner_can_edit_and_resubmit_a_disapproved_request(): void
    {
        $branch = $this->branch('RESUBMIT');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $newApprover = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement(
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
        $this->item($requestForm, 'Rejected item');

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm, $newApprover),
        )
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.form_data', null)
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Pending->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $newApprover->id,
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Pending->value,
        ]);
        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $oldApprover->id,
        ]);
    }

    #[DataProvider('nonEditableStatusProvider')]
    public function test_only_pending_and_disapproved_requests_can_be_edited(
        RequestStatus $status,
    ): void {
        $branch = $this->branch('STATUS');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement(
            $owner,
            $branch,
            $status,
        );

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm, $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only pending or disapproved requests can be edited.',
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => $status->value,
            'kind_of_request' => 'Office Supplies',
        ]);
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
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
        $requestForm = $this->cashDisbursement($owner, $branch);
        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            status: ApprovalStepStatus::Approved,
            actedAt: now(),
        );

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm, $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'A pending request cannot be edited after approval activity has started.',
            );

        $this->assertDatabaseHas('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $approver->id,
            'status' => ApprovalStepStatus::Approved->value,
        ]);
    }

    public function test_stale_request_version_is_rejected_without_changes(): void
    {
        $branch = $this->branch('STALE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $oldItem = $this->item($requestForm, 'Unchanged item');
        $payload = $this->updatePayload($requestForm, $approver);
        $payload['updated_at'] = $requestForm->fresh()->updated_at
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

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'grand_total' => '250.00',
            'status' => RequestStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('request_items', [
            'id' => $oldItem->id,
            'description' => 'Unchanged item',
        ]);
    }

    public function test_non_cash_request_is_rejected_by_the_cash_update_endpoint(): void
    {
        $branch = $this->branch('WRONG-TYPE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->request_code = str_replace(
            '-CD-',
            '-SRL-',
            $requestForm->request_code,
        );
        $requestForm->save();

        Sanctum::actingAs($owner);

        $this->patchJson(
            $this->updateUrl($requestForm),
            $this->updatePayload($requestForm->fresh(), $approver),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only Cash Disbursement requests can be edited.',
            );

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'form_type' => RequestFormType::StockRequisition->value,
        ]);
    }

    public function test_server_owned_and_calculated_fields_are_rejected_on_update(): void
    {
        $branch = $this->branch('PROHIBITED');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        $payload['form_data'] = ['supplier' => 'Injected'];
        $payload['id'] = 999;
        $payload['user_id'] = 999;
        $payload['requester_id'] = 999;
        $payload['branch_id'] = 999;
        $payload['request_code'] = 'FAKE-CD-0000001';
        $payload['form_type'] = RequestFormType::StockRequisition->value;
        $payload['status'] = RequestStatus::Completed->value;
        $payload['grand_total'] = '0.01';
        $payload['date'] = '2026-01-01';
        $payload['supplier_id'] = 999;
        $payload['approval_steps'] = [
            [
                'level' => 1,
                'approver_id' => $approver->id,
            ],
        ];
        $payload['approver_id'] = 999;
        $payload['level'] = 99;
        $payload['acted_at'] = now()->toISOString();
        $payload['items'][0]['line_number'] = 99;
        $payload['items'][0]['line_total'] = '999.99';
        $payload['items'][0]['total_amount'] = '999.99';
        $payload['items'][0]['totalAmount'] = '999.99';

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'form_data',
                'id',
                'user_id',
                'requester_id',
                'branch_id',
                'request_code',
                'form_type',
                'status',
                'grand_total',
                'date',
                'supplier_id',
                'approval_steps',
                'approver_id',
                'level',
                'acted_at',
                'items.0.line_number',
                'items.0.line_total',
                'items.0.total_amount',
                'items.0.totalAmount',
            ]);

        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'kind_of_request' => 'Office Supplies',
            'grand_total' => '250.00',
            'status' => RequestStatus::Pending->value,
        ]);
    }

    public function test_new_attachments_are_stored_and_unselected_attachments_are_retained(): void
    {
        Storage::fake('local');

        $branch = $this->branch('FILES');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $retained = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/retained.pdf",
            'original_name' => 'retained.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($retained->path, 'retained');

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        $payload['_method'] = 'PATCH';
        $payload['attachments'] = [
            UploadedFile::fake()->create('new-one.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('new-two.png', 100, 'image/png'),
        ];

        $response = $this->post(
            $this->updateUrl($requestForm),
            $payload,
            ['Accept' => 'application/json'],
        );

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data.attachments');

        $this->assertContains(
            $retained->id,
            collect($response->json('data.attachments'))
                ->pluck('id')
                ->all(),
        );

        $this->assertDatabaseCount('request_attachments', 3);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $retained->id,
            'request_form_id' => $requestForm->id,
        ]);

        foreach (
            $requestForm->fresh()->attachments as $attachment
        ) {
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_removed_attachment_is_deleted_from_database_and_disk(): void
    {
        Storage::fake('local');

        $branch = $this->branch('REMOVE');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $removed = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/remove-me.pdf",
            'original_name' => 'remove-me.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($removed->path, 'remove me');

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        $payload['removed_attachment_ids'] = [$removed->id];

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments');

        $this->assertDatabaseMissing('request_attachments', [
            'id' => $removed->id,
        ]);
        Storage::disk('local')->assertMissing($removed->path);
    }

    public function test_attachment_from_another_request_cannot_be_removed(): void
    {
        Storage::fake('local');

        $branch = $this->branch('FOREIGN');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $otherRequest = $this->cashDisbursement($owner, $branch);
        $foreign = $otherRequest->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$otherRequest->id}/private.pdf",
            'original_name' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($foreign->path, 'private');

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        $payload['removed_attachment_ids'] = [$foreign->id];

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['removed_attachment_ids.0']);

        $this->assertDatabaseHas('request_attachments', [
            'id' => $foreign->id,
            'request_form_id' => $otherRequest->id,
        ]);
        Storage::disk('local')->assertExists($foreign->path);
    }

    public function test_duplicate_removal_ids_are_rejected(): void
    {
        $branch = $this->branch('DUPES');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $attachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/once.pdf",
            'original_name' => 'once.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        $payload['removed_attachment_ids'] = [
            $attachment->id,
            $attachment->id,
        ];

        $this->patchJson($this->updateUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['removed_attachment_ids.1']);

        $this->assertDatabaseHas('request_attachments', [
            'id' => $attachment->id,
        ]);
    }

    public function test_retained_and_new_attachments_cannot_exceed_ten(): void
    {
        Storage::fake('local');

        $branch = $this->branch('LIMIT');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);

        foreach (range(1, 10) as $number) {
            $requestForm->attachments()->create([
                'disk' => 'local',
                'path' => "request-forms/{$requestForm->id}/existing-{$number}.pdf",
                'original_name' => "existing-{$number}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 128,
            ]);
        }

        Sanctum::actingAs($owner);

        $payload = $this->updatePayload($requestForm, $approver);
        $payload['_method'] = 'PATCH';
        $payload['attachments'] = [
            UploadedFile::fake()->create('one-too-many.pdf', 100, 'application/pdf'),
        ];

        $this->post(
            $this->updateUrl($requestForm),
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $this->assertDatabaseCount('request_attachments', 10);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function updateUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/cash-disbursement";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(
        RequestForm $requestForm,
        User $approvedBy,
        ?User $notedBy = null,
        array $overrides = [],
    ): array {
        $payload = [
            'updated_at' => $requestForm->fresh()->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'currency' => 'USD',
            'noted_by_ids' => $notedBy ? [$notedBy->id] : [],
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

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
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
            'firstName' => "CashEdit{$number}",
            'lastName' => 'User',
            'contact' => "0908000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "cash-edit-{$number}",
            'employee_id' => "CASH-EDIT-{$number}",
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
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function item(
        RequestForm $requestForm,
        string $description,
    ): RequestItem {
        $item = new RequestItem([
            'quantity' => 2,
            'description' => $description,
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
