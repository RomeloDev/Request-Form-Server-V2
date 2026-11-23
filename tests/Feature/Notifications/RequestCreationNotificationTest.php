<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationKind;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use App\Notifications\RequestActionRequiredNotification;
use App\Notifications\RequestWorkflowMailNotification;
use App\Services\CashDisbursementService;
use App\Services\CheckIssuanceService;
use App\Services\PurchaseOrderService;
use App\Services\RequestWorkflowNotificationService;
use App\Services\StockRequisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\NotificationContract;
use Tests\TestCase;
use Throwable;

class RequestCreationNotificationTest extends TestCase
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

    public function test_stock_requisition_notifies_only_the_earliest_pending_approver(): void
    {
        [$requester, $first, $second, $third] = $this->workflowUsers('STOCK');
        Queue::fake();

        $request = app(StockRequisitionService::class)->create(
            $requester,
            [
                'kind_of_request' => 'Office Supplies',
                'form_data' => [
                    'purpose' => 'Replace depleted supplies.',
                ],
                'noted_by_ids' => [$first->id],
                'approved_by_ids' => [$second->id, $third->id],
                'items' => $this->items(),
            ],
        );

        $this->assertRequestNotification(
            request: $request,
            requester: $requester,
            first: $first,
            future: [$second, $third],
            expectedFormType: 'Stock Requisition Slip',
            expectedStepType: 'noted_by',
        );

        Queue::assertPushedTimes(SendQueuedNotifications::class, 1);
        Queue::assertPushedOn(
            'notifications',
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->channels === ['mail']
                && $job->afterCommit === true
                && $job->shouldBeEncrypted
                && $job->notification instanceof RequestWorkflowMailNotification
                && $job->notification->payload()->kind === NotificationKind::RequestActionRequired
                && $job->notifiables->first()?->is($first),
        );
    }

    public function test_purchase_order_notifies_only_the_earliest_pending_approver(): void
    {
        [$requester, $first, $second, $third] = $this->workflowUsers('PURCHASE');

        $request = app(PurchaseOrderService::class)->create(
            $requester,
            [
                'kind_of_request' => 'Office Supplies',
                'form_data' => [
                    'supplier' => 'Tri-Star Motors',
                    'address' => '123 Main Street',
                ],
                'noted_by_ids' => [$first->id],
                'approved_by_ids' => [$second->id, $third->id],
                'items' => $this->items(),
            ],
        );

        $this->assertRequestNotification(
            request: $request,
            requester: $requester,
            first: $first,
            future: [$second, $third],
            expectedFormType: 'Purchase Order Requisition Slip',
            expectedStepType: 'noted_by',
        );
    }

    public function test_cash_disbursement_notifies_only_the_earliest_pending_approver(): void
    {
        [$requester, $first, $second, $third] = $this->workflowUsers('CASH');

        $request = app(CashDisbursementService::class)->create(
            $requester,
            [
                'kind_of_request' => 'Office Supplies',
                'currency' => 'PHP',
                'noted_by_ids' => [$first->id],
                'approved_by_ids' => [$second->id, $third->id],
                'items' => $this->items(),
            ],
        );

        $this->assertRequestNotification(
            request: $request,
            requester: $requester,
            first: $first,
            future: [$second, $third],
            expectedFormType: 'Cash Disbursement Requisition Slip',
            expectedStepType: 'noted_by',
        );
    }

    public function test_check_issuance_notifies_only_the_earliest_pending_approver(): void
    {
        [$requester, $first, $second, $third] = $this->workflowUsers('CHECK');

        $request = app(CheckIssuanceService::class)->create(
            $requester,
            [
                'kind_of_request' => 'Office Supplies',
                'form_data' => [
                    'payee' => 'Example Payee',
                    'bank' => 'Example Bank',
                    'account_no' => '00123456789',
                    'swift_code' => 'EXAMPHMM',
                ],
                'noted_by_ids' => [$first->id],
                'approved_by_ids' => [$second->id, $third->id],
                'items' => $this->items(),
            ],
        );

        $this->assertRequestNotification(
            request: $request,
            requester: $requester,
            first: $first,
            future: [$second, $third],
            expectedFormType: 'Check Issuance Requisition Slip',
            expectedStepType: 'noted_by',
        );
    }

    public function test_actor_name_prefers_the_user_name_field(): void
    {
        [$requester, $first, $second, $third] = $this->workflowUsers('ACTOR-NAME');

        $requester->forceFill([
            'name' => 'Preferred Server Name',
            'firstName' => 'Ignored',
            'lastName' => 'Fallback',
        ])->save();

        $this->createStockRequest($requester, $first, $second, $third);

        $this->assertSame(
            'Preferred Server Name',
            $first->notifications()->sole()->data['actor_name'],
        );
    }

    public function test_actor_name_falls_back_to_first_and_last_name(): void
    {
        [$requester, $first, $second, $third] = $this->workflowUsers('ACTOR-FALLBACK');

        $requester->forceFill([
            'name' => '   ',
            'firstName' => 'Fallback',
            'lastName' => 'Person',
        ])->save();

        $this->createStockRequest($requester, $first, $second, $third);

        $this->assertSame(
            'Fallback Person',
            $first->notifications()->sole()->data['actor_name'],
        );
    }

    public function test_notification_payload_excludes_sensitive_request_details(): void
    {
        Storage::fake('local');

        [$requester, $first, $second, $third] = $this->workflowUsers('SENSITIVE');

        $payload = $this->checkPayload($first, $second, $third);
        $payload['form_data']['account_no'] = 'SECRET-ACCOUNT-999';
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'secret.pdf',
                100,
                'application/pdf',
            ),
        ];

        app(CheckIssuanceService::class)->create($requester, $payload);

        $notification = $first->notifications()->sole();
        $serialized = json_encode($notification->data);

        $this->assertSame(
            NotificationContract::KEYS,
            array_keys($notification->data),
        );

        $this->assertStringNotContainsString(
            'SECRET-ACCOUNT-999',
            $serialized,
        );
        $this->assertStringNotContainsString('Example Payee', $serialized);
        $this->assertStringNotContainsString('secret.pdf', $serialized);
        $this->assertStringNotContainsString('request-forms/', $serialized);
    }

    public function test_missing_pending_approval_step_fails_safely(): void
    {
        [$requester] = $this->workflowUsers('NO-STEP');

        $branch = Branch::query()
            ->where('branch_code', $requester->branch_code)
            ->firstOrFail();

        $request = new RequestForm([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => null,
        ]);
        $request->request_code = 'NO-STEP-0000001';
        $request->form_type = RequestFormType::StockRequisition;
        $request->status = RequestStatus::Pending;
        $request->grand_total = '0.00';
        $request->requester()->associate($requester);
        $request->branch()->associate($branch);
        $request->save();

        $thrown = null;

        try {
            app(RequestWorkflowNotificationService::class)
                ->notifyFirstApprover($request);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull(
            $thrown,
            'Expected the missing approval step to fail safely.',
        );
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_notification_insert_rolls_back_request_and_files(): void
    {
        Storage::fake('local');

        [$requester, $first, $second, $third] = $this->workflowUsers('ROLLBACK');

        $payload = $this->stockPayload($first, $second, $third);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'quote.pdf',
                100,
                'application/pdf',
            ),
        ];

        /*
         * Simulate a real database failure after the request graph has been
         * written but while the notification row is being inserted.
         */
        DB::statement(
            'CREATE TRIGGER fail_notification_insert '
            .'BEFORE INSERT ON notifications '
            ."BEGIN SELECT RAISE(ABORT, 'Simulated notification failure'); END"
        );

        $thrown = null;

        try {
            app(StockRequisitionService::class)->create(
                $requester,
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

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
        $this->assertDatabaseCount('request_approval_steps', 0);
        $this->assertDatabaseCount('request_attachments', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_outer_rollback_discards_after_commit_mail_and_database_rows(): void
    {
        config(['queue.default' => 'database']);

        [$requester, $first, $second, $third] =
            $this->workflowUsers('MAIL-ROLLBACK');
        $thrown = null;

        try {
            DB::transaction(function () use (
                $requester,
                $first,
                $second,
                $third,
            ): void {
                $this->createStockRequest(
                    $requester,
                    $first,
                    $second,
                    $third,
                );

                $this->assertDatabaseCount('notifications', 1);
                $this->assertDatabaseCount('jobs', 0);

                throw new RuntimeException('Force outer transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(
            'Force outer transaction rollback.',
            $thrown->getMessage(),
        );
        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    /**
     * Assert the full recipient and payload contract for one request type.
     *
     * @param  list<User>  $future
     */
    private function assertRequestNotification(
        RequestForm $request,
        User $requester,
        User $first,
        array $future,
        string $expectedFormType,
        string $expectedStepType,
    ): void {
        $this->assertSame(
            [1, 2, 3],
            $request->approvalSteps()->pluck('level')->all(),
        );

        $notification = $first->notifications()->sole();

        $this->assertSame(
            RequestActionRequiredNotification::class,
            $notification->type,
        );
        $this->assertNull($notification->read_at);
        $this->assertSame(
            $first->id,
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
        $this->assertSame($requester->name, $data['actor_name']);

        foreach ($future as $user) {
            $this->assertSame(0, $user->notifications()->count());
        }

        $this->assertSame(0, $requester->notifications()->count());
        $this->assertDatabaseCount('notifications', 1);
    }

    private function createStockRequest(
        User $requester,
        User $first,
        User $second,
        User $third,
    ): RequestForm {
        return app(StockRequisitionService::class)->create(
            $requester,
            $this->stockPayload($first, $second, $third),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stockPayload(
        User $first,
        User $second,
        User $third,
    ): array {
        return [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'purpose' => 'Replace depleted supplies.',
            ],
            'noted_by_ids' => [$first->id],
            'approved_by_ids' => [$second->id, $third->id],
            'items' => $this->items(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPayload(
        User $first,
        User $second,
        User $third,
    ): array {
        return [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'payee' => 'Example Payee',
                'bank' => 'Example Bank',
                'account_no' => '00123456789',
                'swift_code' => 'EXAMPHMM',
            ],
            'noted_by_ids' => [$first->id],
            'approved_by_ids' => [$second->id, $third->id],
            'items' => $this->items(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        return [
            [
                'quantity' => '2',
                'description' => 'A4 bond paper',
                'unit_cost' => '100.20',
                'remarks' => 'For Accounting',
            ],
        ];
    }

    /**
     * Create a requester and a three-level approval chain.
     *
     * The first user is the level-one Noted By approver. The other two are
     * later Approved By levels that must not be notified yet.
     *
     * @return array{User, User, User, User}
     */
    private function workflowUsers(string $prefix): array
    {
        $branch = $this->branch($prefix);

        return [
            $this->user($branch, 'User', "{$prefix} Requester"),
            $this->user($branch, 'approver', "{$prefix} First"),
            $this->user($branch, 'approver', "{$prefix} Second"),
            $this->user($branch, 'approver', "{$prefix} Third"),
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
            'userName' => "request-notification-{$number}",
            'employee_id' => "REQ-NOTIF-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }
}
