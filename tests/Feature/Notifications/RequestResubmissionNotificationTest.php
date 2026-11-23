<?php

namespace Tests\Feature\Notifications;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use App\Notifications\RequestActionRequiredNotification;
use App\Services\CashDisbursementService;
use App\Services\CheckIssuanceService;
use App\Services\PurchaseOrderService;
use App\Services\StockRequisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\NotificationContract;
use Tests\Support\ProvidesUsableSignatures;
use Tests\TestCase;
use Throwable;

class RequestResubmissionNotificationTest extends TestCase
{
    use ProvidesUsableSignatures;
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

    public function test_stock_pending_edit_notifies_the_new_first_approver_only(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('STOCK-EDIT');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        app(StockRequisitionService::class)->update(
            $request,
            $owner,
            $this->payloadFor($request, $newFirst, $future),
        );

        $this->assertNewChain(
            $request,
            [$newFirst, $future],
            [ApprovalStepType::NotedBy, ApprovalStepType::ApprovedBy],
        );

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $newFirst,
            expectedFormType: 'Stock Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );

        $this->assertNoNotifications($oldFirst, $future, $owner);
    }

    public function test_purchase_disapproved_resubmission_notifies_the_new_first_approver_only(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('PURCHASE-RESUBMIT');

        $request = $this->requestForm(
            RequestFormType::PurchaseOrder,
            $owner,
            RequestStatus::Disapproved,
        );
        $this->approvalStep(
            $request,
            $oldFirst,
            ApprovalStepStatus::Disapproved,
            now(),
        );

        app(PurchaseOrderService::class)->update(
            $request,
            $owner,
            $this->payloadFor($request, $newFirst, $future),
        );

        $this->assertNewChain(
            $request,
            [$newFirst, $future],
            [ApprovalStepType::NotedBy, ApprovalStepType::ApprovedBy],
        );

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $newFirst,
            expectedFormType: 'Purchase Order Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );

        $this->assertNoNotifications($oldFirst, $future, $owner);
    }

    public function test_cash_pending_edit_notifies_the_new_first_approver_only(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('CASH-EDIT');

        $request = $this->requestForm(
            RequestFormType::CashDisbursement,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        app(CashDisbursementService::class)->update(
            $request,
            $owner,
            $this->payloadFor($request, $newFirst, $future),
        );

        $this->assertNewChain(
            $request,
            [$newFirst, $future],
            [ApprovalStepType::NotedBy, ApprovalStepType::ApprovedBy],
        );

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $newFirst,
            expectedFormType: 'Cash Disbursement Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );

        $this->assertNoNotifications($oldFirst, $future, $owner);
    }

    public function test_check_disapproved_resubmission_notifies_the_new_first_approver_only(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('CHECK-RESUBMIT');

        $request = $this->requestForm(
            RequestFormType::CheckIssuance,
            $owner,
            RequestStatus::Disapproved,
        );
        $this->approvalStep(
            $request,
            $oldFirst,
            ApprovalStepStatus::Disapproved,
            now(),
        );

        app(CheckIssuanceService::class)->update(
            $request,
            $owner,
            $this->payloadFor($request, $newFirst, $future),
        );

        $this->assertNewChain(
            $request,
            [$newFirst, $future],
            [ApprovalStepType::NotedBy, ApprovalStepType::ApprovedBy],
        );

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $newFirst,
            expectedFormType: 'Check Issuance Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );

        $this->assertNoNotifications($oldFirst, $future, $owner);
    }

    public function test_unchanged_first_approver_receives_one_new_notification(): void
    {
        [$owner, $first, $future] = $this->workflowUsers('SAME-FIRST');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $first);

        app(StockRequisitionService::class)->update(
            $request,
            $owner,
            $this->payloadFor($request, $first, $future),
        );

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $first,
            expectedFormType: 'Stock Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );
        $this->assertNoNotifications($future, $owner);
        $this->assertSame(1, $first->notifications()->count());
    }

    public function test_resubmission_preserves_historical_notifications_unchanged(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('HISTORY');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        $oldFirst->notifyNow(
            new RequestActionRequiredNotification(
                requestId: (int) $request->getKey(),
                requestCode: (string) $request->request_code,
                formType: $request->form_type->value,
                status: 'Pending',
                approvalLevel: 1,
                approvalStepType: 'approved_by',
                actorName: 'Historical Actor',
            ),
            ['database'],
        );

        $historical = $oldFirst->notifications()->sole();
        $historicalData = $historical->data;

        app(StockRequisitionService::class)->update(
            $request,
            $owner,
            $this->payloadFor($request, $newFirst, $future),
        );

        $preserved = $oldFirst->notifications()->find($historical->id);

        $this->assertNotNull($preserved);
        $this->assertSame($historicalData, $preserved->data);
        $this->assertSame(1, $oldFirst->notifications()->count());

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $newFirst,
            expectedFormType: 'Stock Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_stale_update_creates_no_notification(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('STALE-NOTIFY');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        $payload = $this->payloadFor($request, $newFirst, $future);
        $payload['updated_at'] = $request->fresh()->updated_at
            ?->copy()
            ->subMinute()
            ->toISOString();

        $thrown = null;

        try {
            app(StockRequisitionService::class)->update(
                $request,
                $owner,
                $payload,
            );
        } catch (ConflictHttpException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(
            [$oldFirst->id],
            $request->fresh()->approvalSteps()->pluck('approver_id')->all(),
        );
    }

    public function test_unauthorized_update_creates_no_notification(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('UNAUTHORIZED');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        $otherUser = $this->user(
            $this->branch('UNAUTHORIZED-OUTSIDER'),
            'User',
            'Unauthorized Outsider',
        );
        $this->giveUsableSignature($otherUser);
        $this->giveUsableSignature($owner);

        Sanctum::actingAs($otherUser);

        $this->patchJson(
            "/api/v1/request-forms/{$request->id}/stock-requisition",
            $this->payloadFor($request, $newFirst, $future),
        )->assertForbidden();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(
            [$oldFirst->id],
            $request->fresh()->approvalSteps()->pluck('approver_id')->all(),
        );
    }

    public function test_invalid_approval_chain_creates_no_notification(): void
    {
        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('INVALID-CHAIN');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        $payload = $this->payloadFor($request, $newFirst, $future);
        $payload['approved_by_ids'] = [];

        $thrown = null;

        try {
            app(StockRequisitionService::class)->update(
                $request,
                $owner,
                $payload,
            );
        } catch (ValidationException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(
            [$oldFirst->id],
            $request->fresh()->approvalSteps()->pluck('approver_id')->all(),
        );
    }

    public function test_notification_failure_rolls_back_chain_items_and_files(): void
    {
        Storage::fake('local');

        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('NOTIFY-ROLLBACK');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);
        $oldItem = $this->item($request, 'Original item');
        $oldAttachment = $request->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$request->id}/old-quotation.pdf",
            'original_name' => 'old-quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($oldAttachment->path, 'old quotation');

        $oldFirst->notifyNow(
            new RequestActionRequiredNotification(
                requestId: (int) $request->getKey(),
                requestCode: (string) $request->request_code,
                formType: $request->form_type->value,
                status: 'Pending',
                approvalLevel: 1,
                approvalStepType: 'approved_by',
                actorName: 'Historical Actor',
            ),
            ['database'],
        );

        $historical = $oldFirst->notifications()->sole();
        $historicalData = $historical->data;

        $payload = $this->payloadFor($request, $newFirst, $future);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'new-quotation.pdf',
                100,
                'application/pdf',
            ),
        ];
        $payload['removed_attachment_ids'] = [$oldAttachment->id];

        DB::statement(
            'CREATE TRIGGER fail_notification_insert '
            .'BEFORE INSERT ON notifications '
            ."BEGIN SELECT RAISE(ABORT, 'Simulated notification failure'); END"
        );

        $thrown = null;

        try {
            app(StockRequisitionService::class)->update(
                $request,
                $owner,
                $payload,
            );
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_notification_insert');
        }

        $this->assertNotNull(
            $thrown,
            'Expected the notification insert to fail.',
        );

        $rolledBack = $request->fresh();

        $this->assertSame(RequestStatus::Pending, $rolledBack->status);
        $this->assertSame('Office Supplies', $rolledBack->kind_of_request);
        $this->assertSame('250.00', $rolledBack->grand_total);
        $this->assertSame(
            [$oldFirst->id],
            $rolledBack->approvalSteps()->pluck('approver_id')->all(),
        );
        $this->assertDatabaseHas('request_items', [
            'id' => $oldItem->id,
            'description' => 'Original item',
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $oldAttachment->id,
        ]);
        Storage::disk('local')->assertExists($oldAttachment->path);
        $this->assertSame(
            [$oldAttachment->path],
            Storage::disk('local')->allFiles(),
        );

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $oldFirst->notifications()->count());
        $this->assertSame(0, $newFirst->notifications()->count());
        $this->assertSame(0, $future->notifications()->count());
        $this->assertSame(
            $historicalData,
            $oldFirst->notifications()->find($historical->id)?->data,
        );
    }

    public function test_successful_update_replaces_files_only_after_commit(): void
    {
        Storage::fake('local');

        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('FILE-CLEANUP');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);
        $oldAttachment = $request->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$request->id}/old-quotation.pdf",
            'original_name' => 'old-quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($oldAttachment->path, 'old quotation');

        $payload = $this->payloadFor($request, $newFirst, $future);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'new-quotation.pdf',
                100,
                'application/pdf',
            ),
        ];
        $payload['removed_attachment_ids'] = [$oldAttachment->id];

        app(StockRequisitionService::class)->update(
            $request,
            $owner,
            $payload,
        );

        $this->assertDatabaseMissing('request_attachments', [
            'id' => $oldAttachment->id,
        ]);
        Storage::disk('local')->assertMissing($oldAttachment->path);

        $replacement = $request->fresh()
            ->attachments()
            ->where('original_name', 'new-quotation.pdf')
            ->sole();

        Storage::disk('local')->assertExists($replacement->path);

        $this->assertActionRequiredNotification(
            request: $request->fresh(),
            recipient: $newFirst,
            expectedFormType: 'Stock Requisition Slip',
            expectedStepType: 'noted_by',
            expectedActorName: $owner->name,
        );
    }

    public function test_notification_payload_excludes_request_contents_and_paths(): void
    {
        Storage::fake('local');

        [$owner, $oldFirst, $newFirst, $future] = $this->workflowUsers('SENSITIVE');

        $request = $this->requestForm(
            RequestFormType::StockRequisition,
            $owner,
            RequestStatus::Pending,
        );
        $this->approvalStep($request, $oldFirst);

        $payload = $this->payloadFor($request, $newFirst, $future);
        $payload['form_data']['purpose'] = 'SECRET-PURPOSE-TEXT';
        $payload['items'][0]['description'] = 'SECRET-ITEM-DESCRIPTION';
        $payload['items'][0]['remarks'] = 'SECRET-REMARK';
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'secret-attachment.pdf',
                100,
                'application/pdf',
            ),
        ];

        app(StockRequisitionService::class)->update(
            $request,
            $owner,
            $payload,
        );

        $notification = $newFirst->notifications()->sole();
        $serialized = json_encode($notification->data);

        $this->assertSame(
            NotificationContract::KEYS,
            array_keys($notification->data),
        );
        $this->assertStringNotContainsString('SECRET-PURPOSE-TEXT', $serialized);
        $this->assertStringNotContainsString(
            'SECRET-ITEM-DESCRIPTION',
            $serialized,
        );
        $this->assertStringNotContainsString('SECRET-REMARK', $serialized);
        $this->assertStringNotContainsString(
            'secret-attachment.pdf',
            $serialized,
        );
        $this->assertStringNotContainsString('request-forms/', $serialized);
    }

    /**
     * @param  list<User>  $approvers
     * @param  list<ApprovalStepType>  $stepTypes
     */
    private function assertNewChain(
        RequestForm $request,
        array $approvers,
        array $stepTypes,
    ): void {
        $steps = $request->fresh()
            ->approvalSteps()
            ->orderBy('level')
            ->get();

        $this->assertSame(
            range(1, count($approvers)),
            $steps->pluck('level')->all(),
        );
        $this->assertSame(
            array_map(fn (User $user): int => $user->id, $approvers),
            $steps->pluck('approver_id')->all(),
        );
        $this->assertSame(
            array_map(fn (ApprovalStepType $type): string => $type->value, $stepTypes),
            $steps->pluck('step_type')->map->value->all(),
        );
        $this->assertSame(
            array_fill(0, count($approvers), ApprovalStepStatus::Pending->value),
            $steps->pluck('status')->map->value->all(),
        );
    }

    private function assertActionRequiredNotification(
        RequestForm $request,
        User $recipient,
        string $expectedFormType,
        string $expectedStepType,
        ?string $expectedActorName,
    ): void {
        $notification = $recipient->notifications()->sole();

        $this->assertSame(
            RequestActionRequiredNotification::class,
            $notification->type,
        );
        $this->assertNull($notification->read_at);
        $this->assertSame(
            $recipient->id,
            (int) $notification->notifiable_id,
        );

        $data = $notification->data;

        $this->assertSame(NotificationContract::KEYS, array_keys($data));
        $this->assertSame('request_action_required', $data['kind']);
        $this->assertSame('assigned_request', $data['target']);
        $this->assertSame($request->id, $data['request_id']);
        $this->assertSame($request->request_code, $data['request_code']);
        $this->assertSame($expectedFormType, $data['form_type']);
        $this->assertSame('Pending', $data['status']);
        $this->assertSame(1, $data['approval_level']);
        $this->assertSame($expectedStepType, $data['approval_step_type']);
        $this->assertSame($expectedActorName, $data['actor_name']);
    }

    private function assertNoNotifications(User ...$users): void
    {
        foreach ($users as $user) {
            $this->assertSame(
                0,
                $user->notifications()->count(),
                "User {$user->id} should not have received a notification.",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(
        RequestForm $request,
        User $first,
        User $future,
    ): array {
        $payload = [
            'updated_at' => $request->fresh()->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'noted_by_ids' => [$first->id],
            'approved_by_ids' => [$future->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Updated item',
                    'unit_cost' => '150.00',
                    'remarks' => 'Required',
                ],
            ],
        ];

        $payload['form_data'] = match ($request->form_type) {
            RequestFormType::StockRequisition => [
                'purpose' => 'Updated office use',
            ],
            RequestFormType::PurchaseOrder => [
                'supplier' => 'Updated Supplier',
                'address' => 'Updated Address',
            ],
            RequestFormType::CheckIssuance => [
                'payee' => 'Updated Payee',
                'bank' => 'Updated Bank',
                'account_no' => '00009999',
                'swift_code' => 'EXAMPHMM',
            ],
            RequestFormType::CashDisbursement => null,
        };

        if ($request->form_type === RequestFormType::CashDisbursement) {
            $payload['currency'] = 'PHP';
        }

        return $payload;
    }

    private function requestForm(
        RequestFormType $type,
        User $requester,
        RequestStatus $status,
    ): RequestForm {
        $branch = Branch::query()
            ->where('branch_code', $requester->branch_code)
            ->firstOrFail();

        $formData = match ($type) {
            RequestFormType::StockRequisition => ['purpose' => 'Office use'],
            RequestFormType::PurchaseOrder => [
                'supplier' => 'Original Supplier',
                'address' => 'Original Address',
            ],
            RequestFormType::CheckIssuance => [
                'payee' => 'Original Payee',
                'bank' => 'Original Bank',
                'account_no' => '00001111',
                'swift_code' => null,
            ],
            RequestFormType::CashDisbursement => null,
        };

        $request = new RequestForm([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => $formData,
        ]);
        $request->request_code = sprintf(
            '%s-%s-%07d',
            $branch->branch_code,
            $this->codePrefix($type),
            ++$this->sequence,
        );
        $request->form_type = $type;
        $request->status = $status;
        $request->grand_total = '250.00';
        $request->requester()->associate($requester);
        $request->branch()->associate($branch);
        $request->save();

        return $request;
    }

    private function codePrefix(RequestFormType $type): string
    {
        return match ($type) {
            RequestFormType::StockRequisition => 'SRL',
            RequestFormType::PurchaseOrder => 'POR',
            RequestFormType::CashDisbursement => 'CD',
            RequestFormType::CheckIssuance => 'CI',
        };
    }

    private function item(RequestForm $request, string $description): RequestItem
    {
        $item = new RequestItem([
            'quantity' => 2,
            'description' => $description,
            'unit_cost' => '125.00',
            'remarks' => null,
        ]);
        $item->line_number = 1;
        $item->line_total = '250.00';
        $request->items()->save($item);

        return $item;
    }

    private function approvalStep(
        RequestForm $request,
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
        $request->approvalSteps()->save($step);

        return $step;
    }

    /**
     * Create a requester and a three-approver pool for one workflow.
     *
     * The old first approver is replaced by a new first approver. The third
     * user is the future level that must not be notified.
     *
     * @return array{User, User, User, User}
     */
    private function workflowUsers(string $prefix): array
    {
        $branch = $this->branch($prefix);

        return [
            $this->user($branch, 'User', "{$prefix} Requester"),
            $this->user($branch, 'approver', "{$prefix} Old First"),
            $this->user($branch, 'approver', "{$prefix} New First"),
            $this->user($branch, 'approver', "{$prefix} Future"),
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
        string $role,
        string $name,
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'name' => $name,
            'firstName' => "Notifier{$number}",
            'lastName' => 'Example',
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "resubmit-notification-{$number}",
            'employee_id' => "RESUB-NOTIF-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }
}
