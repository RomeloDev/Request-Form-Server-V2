<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

class PurchaseOrderServiceTest extends TestCase
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

    public function test_purchase_order_is_created_with_server_derived_identity(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $requester = $this->user($branch, 'User');
        $notedBy = $this->user($branch, 'approver');
        $approvedBy = $this->user($branch, 'approver');

        $payload = $this->payload(
            [$approvedBy->id],
            [
                'noted_by_ids' => [$notedBy->id],
                'form_data' => [
                    'supplier' => '  Tri-Star Motors  ',
                    'address' => '  123 Main Street  ',
                ],
            ],
        );

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'quotation.pdf',
                100,
                'application/pdf',
            ),
        ];

        $request = $this->service()->create($requester, $payload);

        $this->assertInstanceOf(RequestForm::class, $request);

        $this->assertSame(
            RequestFormType::PurchaseOrder,
            $request->form_type,
        );

        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame('PHP', $request->currency);
        $this->assertSame($requester->id, $request->requester_id);
        $this->assertSame($branch->id, $request->branch_id);
        $this->assertSame('Office Supplies', $request->kind_of_request);

        $this->assertSame(
            [
                'supplier' => 'Tri-Star Motors',
                'address' => '123 Main Street',
            ],
            $request->form_data,
        );

        $this->assertSame('260.85', $request->grand_total);

        $this->assertTrue($request->relationLoaded('requester'));
        $this->assertTrue($request->relationLoaded('branch'));
        $this->assertTrue($request->relationLoaded('items'));
        $this->assertTrue($request->relationLoaded('attachments'));
        $this->assertTrue($request->relationLoaded('approvalSteps'));
        $this->assertTrue(
            $request->approvalSteps->first()->relationLoaded('approver')
        );

        $this->assertCount(2, $request->items);
        $this->assertSame('200.40', $request->items[0]->line_total);
        $this->assertSame('60.45', $request->items[1]->line_total);

        $this->assertCount(2, $request->approvalSteps);
        $this->assertSame(
            [1, 2],
            $request->approvalSteps->pluck('level')->all(),
        );
        $this->assertSame(
            [ApprovalStepType::NotedBy, ApprovalStepType::ApprovedBy],
            $request->approvalSteps->pluck('step_type')->all(),
        );
        $this->assertSame(
            [$notedBy->id, $approvedBy->id],
            $request->approvalSteps->pluck('approver_id')->all(),
        );

        $attachment = $request->attachments->sole();

        $this->assertSame('quotation.pdf', $attachment->original_name);
        $this->assertSame('local', $attachment->disk);

        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_request_code_uses_the_po_discriminator(): void
    {
        $branch = $this->branch('CALI');
        $requester = $this->user($branch, 'User');
        $approvedBy = $this->user($branch, 'approver');

        $request = $this->service()->create(
            $requester,
            $this->payload([$approvedBy->id]),
        );

        $this->assertMatchesRegularExpression(
            '/^CALI-PO-\d{7}$/',
            $request->request_code,
        );

        $this->assertSame(
            sprintf('CALI-PO-%07d', $request->id),
            $request->request_code,
        );

        $this->assertStringNotContainsString(
            'SRL',
            $request->request_code,
        );
    }

    public function test_server_calculates_totals_and_ignores_client_values(): void
    {
        $branch = $this->branch('TEST');
        $requester = $this->user($branch, 'User');
        $approvedBy = $this->user($branch, 'approver');

        $payload = $this->payload(
            [$approvedBy->id],
            [
                'requester_id' => 999999,
                'branch_id' => 999999,
                'request_code' => 'FAKE-CODE',
                'form_type' => 'Stock Requisition Slip',
                'status' => 'Completed',
                'currency' => 'USD',
                'grand_total' => '0.01',
                'items' => [
                    [
                        'quantity' => '2',
                        'description' => 'A4 bond paper',
                        'unit_cost' => '100.20',
                        'line_number' => 99,
                        'line_total' => '999.99',
                        'total_amount' => '999.99',
                        'totalAmount' => '999.99',
                    ],
                    [
                        'quantity' => '3',
                        'description' => 'Black ballpens',
                        'unit_cost' => '20.15',
                        'line_number' => 99,
                        'line_total' => '999.99',
                        'total_amount' => '999.99',
                        'totalAmount' => '999.99',
                    ],
                ],
            ],
        );

        $request = $this->service()->create($requester, $payload);

        $this->assertSame(
            RequestFormType::PurchaseOrder,
            $request->form_type,
        );
        $this->assertSame(RequestStatus::Pending, $request->status);
        $this->assertSame('PHP', $request->currency);
        $this->assertSame($requester->id, $request->requester_id);
        $this->assertSame($branch->id, $request->branch_id);

        $this->assertStringStartsWith('TEST-PO-', $request->request_code);

        $this->assertSame('200.40', $request->items[0]->line_total);
        $this->assertSame('60.45', $request->items[1]->line_total);
        $this->assertSame('260.85', $request->grand_total);
    }

    public function test_noted_by_steps_precede_approved_by_steps(): void
    {
        $branch = $this->branch('CALI');
        $requester = $this->user($branch, 'User');
        $notedFirst = $this->user($branch, 'approver');
        $notedSecond = $this->user($branch, 'approver');
        $approvedFirst = $this->user($branch, 'approver');
        $approvedSecond = $this->user($branch, 'approver');

        $payload = $this->payload(
            [$approvedFirst->id, $approvedSecond->id],
            [
                'noted_by_ids' => [$notedFirst->id, $notedSecond->id],
            ],
        );

        $request = $this->service()->create($requester, $payload);

        $this->assertSame(
            [
                ApprovalStepType::NotedBy,
                ApprovalStepType::NotedBy,
                ApprovalStepType::ApprovedBy,
                ApprovalStepType::ApprovedBy,
            ],
            $request->approvalSteps->pluck('step_type')->all(),
        );

        $this->assertSame(
            [1, 2, 3, 4],
            $request->approvalSteps->pluck('level')->all(),
        );

        $this->assertSame(
            [
                $notedFirst->id,
                $notedSecond->id,
                $approvedFirst->id,
                $approvedSecond->id,
            ],
            $request->approvalSteps->pluck('approver_id')->all(),
        );
    }

    public function test_new_supplier_is_inserted_with_trimmed_values(): void
    {
        $branch = $this->branch('TEST');
        $requester = $this->user($branch, 'User');
        $approvedBy = $this->user($branch, 'approver');

        $payload = $this->payload(
            [$approvedBy->id],
            [
                'form_data' => [
                    'supplier' => '  Tri-Star Motors  ',
                    'address' => '  123 Main Street  ',
                ],
            ],
        );

        $request = $this->service()->create($requester, $payload);

        $this->assertDatabaseCount('suppliers', 1);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Tri-Star Motors',
            'address' => '123 Main Street',
        ]);

        $this->assertSame(
            '123 Main Street',
            $request->form_data['address'],
        );
    }

    public function test_existing_supplier_address_is_updated(): void
    {
        $branch = $this->branch('TEST');
        $requester = $this->user($branch, 'User');
        $approvedBy = $this->user($branch, 'approver');

        Supplier::create([
            'name' => 'Tri-Star Motors',
            'address' => 'Old Address',
        ]);

        $payload = $this->payload(
            [$approvedBy->id],
            [
                'form_data' => [
                    'supplier' => '  Tri-Star Motors  ',
                    'address' => '  456 New Street  ',
                ],
            ],
        );

        $request = $this->service()->create($requester, $payload);

        $this->assertDatabaseCount('suppliers', 1);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Tri-Star Motors',
            'address' => '456 New Street',
        ]);

        $this->assertSame(
            '456 New Street',
            $request->form_data['address'],
        );
    }

    public function test_transaction_rolls_back_and_removes_stored_files(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $requester = $this->user($branch, 'User');
        $approvedBy = $this->user($branch, 'approver');

        /*
         * UploadedFile caches its generated hash name.
         *
         * Supplying the same file object twice causes both storage operations
         * to use the same path. The first attachment metadata row succeeds,
         * while the second violates the unique path constraint.
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
            'Expected the purchase order transaction to fail.',
        );

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
        $this->assertDatabaseCount('request_approval_steps', 0);
        $this->assertDatabaseCount('request_attachments', 0);
        $this->assertDatabaseCount('suppliers', 0);

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    public function test_supplier_persistence_is_atomic_with_the_request(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $requester = $this->user($branch, 'User');
        $approvedBy = $this->user($branch, 'approver');

        $payload = $this->payload([$approvedBy->id]);

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'quotation.pdf',
                100,
                'application/pdf',
            ),
        ];

        /*
         * Fail immediately after the supplier insert, inside the transaction.
         */
        Supplier::created(function (): never {
            throw new RuntimeException('Forced supplier failure.');
        });

        $thrown = null;

        try {
            $this->service()->create($requester, $payload);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('request_attachments', 0);

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    private function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    /**
     * Build validated Purchase Order input.
     *
     * The expected calculations are:
     *
     * 2 × 100.20 = 200.40
     * 3 × 20.15  =  60.45
     * Grand total = 260.85
     *
     * @param  list<int>  $approvedByIds
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(
        array $approvedByIds,
        array $overrides = [],
    ): array {
        return array_merge([
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'supplier' => 'Tri-Star Motors',
                'address' => '123 Main Street',
            ],
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
        ?Branch $branch,
        ?string $role = 'User',
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "User{$number}",
            'lastName' => 'Example',
            'contact' => "0917000{$number}",
            'branch_code' => $branch?->branch_code,
            'branch' => $branch?->branch,
            'position' => $position,
            'userName' => "purchase-order-user-{$number}",
            'employee_id' => "PO-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
