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
use App\Services\CashDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

class CashDisbursementServiceTest extends TestCase
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

    public function test_service_creates_a_cash_disbursement_with_server_derived_identity(): void
    {
        Storage::fake('local');

        $branch = $this->branch('CALI');
        $requester = $this->user($branch);
        $notedBy = $this->user($branch, 'approver');
        $approvedBy = $this->user($branch, 'approver');

        $payload = $this->payload(
            [$approvedBy->id],
            [$notedBy->id],
            ['currency' => 'EUR'],
        );
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'voucher.pdf',
                100,
                'application/pdf',
            ),
        ];

        $request = $this->service()->create($requester, $payload);

        $this->assertInstanceOf(RequestForm::class, $request);
        $this->assertSame(
            RequestFormType::CashDisbursement,
            $request->form_type,
        );
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame('EUR', $request->currency);
        $this->assertNull($request->form_data);
        $this->assertSame($requester->id, $request->requester_id);
        $this->assertSame($branch->id, $request->branch_id);
        $this->assertSame('Office Supplies', $request->kind_of_request);
        $this->assertSame('260.85', $request->grand_total);

        $this->assertMatchesRegularExpression(
            "/^{$branch->branch_code}-CD-\d{7}$/",
            $request->request_code,
        );

        $this->assertTrue($request->relationLoaded('requester'));
        $this->assertTrue($request->relationLoaded('branch'));
        $this->assertTrue($request->relationLoaded('items'));
        $this->assertTrue($request->relationLoaded('attachments'));
        $this->assertTrue($request->relationLoaded('approvalSteps'));
        $this->assertTrue(
            $request->approvalSteps->first()->relationLoaded('approver'),
        );

        $this->assertSame(
            ['200.40', '60.45'],
            $request->items->pluck('line_total')->all(),
        );

        $this->assertSame(
            [
                ApprovalStepType::NotedBy,
                ApprovalStepType::ApprovedBy,
            ],
            $request->approvalSteps->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$notedBy->id, $approvedBy->id],
            $request->approvalSteps->pluck('approver_id')->all(),
        );
        $this->assertSame(
            [1, 2],
            $request->approvalSteps->pluck('level')->all(),
        );
        $this->assertSame(
            [
                ApprovalStepStatus::Pending,
                ApprovalStepStatus::Pending,
            ],
            $request->approvalSteps->pluck('status')->all(),
        );

        $attachment = $request->attachments->sole();

        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_creation_rolls_back_and_removes_files_when_later_work_fails(): void
    {
        Storage::fake('local');

        $branch = $this->branch('ROLLBACK');
        $requester = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

        /*
         * UploadedFile caches its generated hash name, so the same instance
         * stored twice targets the same unique path. The first metadata row
         * succeeds and the second violates the unique constraint.
         */
        $sameFile = UploadedFile::fake()->create(
            'duplicate.pdf',
            100,
            'application/pdf',
        );

        $payload = $this->payload([$approvedBy->id]);
        $payload['attachments'] = [$sameFile, $sameFile];

        $thrown = null;

        try {
            $this->service()->create($requester, $payload);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull(
            $thrown,
            'Expected the cash disbursement transaction to fail.',
        );

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
        $this->assertDatabaseCount('request_approval_steps', 0);
        $this->assertDatabaseCount('request_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_writer_defense_in_depth_rejects_an_unsupported_currency(): void
    {
        $branch = $this->branch('CURRENCY');
        $requester = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

        $payload = $this->payload(
            [$approvedBy->id],
            overrides: ['currency' => 'GBP'],
        );

        try {
            $this->service()->create($requester, $payload);
            $this->fail('Expected an invalid currency to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Unsupported request currency',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
        $this->assertDatabaseCount('request_approval_steps', 0);
    }

    public function test_update_failure_removes_new_files_but_preserves_existing_files(): void
    {
        Storage::fake('local');

        $branch = $this->branch('UPDATE-ROLLBACK');
        $owner = $this->user($branch);
        $oldApprover = $this->user($branch, 'approver');
        $newApprover = $this->user($branch, 'approver');
        $requestForm = $this->cashDisbursement($owner, $branch);
        $oldItem = $this->item($requestForm, 'Original item');
        $oldStep = $this->approvalStep($requestForm, $oldApprover);
        $oldAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/old.pdf",
            'original_name' => 'old.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);
        Storage::disk('local')->put($oldAttachment->path, 'old file');

        $sameFile = UploadedFile::fake()->create(
            'duplicate.pdf',
            100,
            'application/pdf',
        );

        $payload = $this->updatePayload($requestForm, $newApprover);
        $payload['removed_attachment_ids'] = [$oldAttachment->id];
        $payload['attachments'] = [$sameFile, $sameFile];

        $thrown = null;

        try {
            $this->service()->update($requestForm, $owner, $payload);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull(
            $thrown,
            'Expected the cash disbursement update to fail.',
        );

        /*
         * The database transaction restored the previous graph.
         */
        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'grand_total' => '250.00',
            'status' => RequestStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('request_items', [
            'id' => $oldItem->id,
            'description' => 'Original item',
        ]);
        $this->assertDatabaseHas('request_approval_steps', [
            'id' => $oldStep->id,
            'approver_id' => $oldApprover->id,
        ]);
        $this->assertDatabaseHas('request_attachments', [
            'id' => $oldAttachment->id,
        ]);
        $this->assertDatabaseMissing('request_approval_steps', [
            'request_form_id' => $requestForm->id,
            'approver_id' => $newApprover->id,
        ]);

        /*
         * Newly uploaded files are deleted on rollback, but the old physical
         * file is only removed after a successful commit, so it must remain.
         */
        $this->assertSame(
            [$oldAttachment->path],
            Storage::disk('local')->allFiles(),
        );
        Storage::disk('local')->assertExists($oldAttachment->path);
    }

    private function service(): CashDisbursementService
    {
        return app(CashDisbursementService::class);
    }

    /**
     * Build validated Cash Disbursement input.
     *
     * 2 × 100.20 = 200.40
     * 3 × 20.15  =  60.45
     * Grand total = 260.85
     *
     * @param  list<int>  $approvedByIds
     * @param  list<int>  $notedByIds
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(
        array $approvedByIds,
        array $notedByIds = [],
        array $overrides = [],
    ): array {
        $payload = [
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'noted_by_ids' => $notedByIds,
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
        ];

        foreach ($overrides as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(
        RequestForm $requestForm,
        User $approvedBy,
    ): array {
        return [
            'updated_at' => $requestForm->fresh()->updated_at?->toISOString(),
            'kind_of_request' => 'Professional Fee',
            'currency' => 'USD',
            'noted_by_ids' => [],
            'approved_by_ids' => [$approvedBy->id],
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Updated service',
                    'unit_cost' => '150.00',
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
            'firstName' => "CashService{$number}",
            'lastName' => 'User',
            'contact' => "0909000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "cash-service-{$number}",
            'employee_id' => "CASH-SERVICE-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function cashDisbursement(
        User $requester,
        Branch $branch,
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
        $requestForm->status = RequestStatus::Pending;
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
