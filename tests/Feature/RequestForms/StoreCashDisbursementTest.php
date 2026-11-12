<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StoreCashDisbursementTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/v1/request-forms/cash-disbursements';

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

    public function test_user_admin_and_approver_roles_can_create_cash_disbursements(): void
    {
        $branch = $this->branch('ROLE');

        foreach (['User', 'Admin', 'approver'] as $role) {
            $requester = $this->user($branch, $role);
            $approver = $this->user($branch, 'approver');

            Sanctum::actingAs($requester);

            $this->postJson(
                self::ROUTE,
                $this->payload([$approver->id]),
            )
                ->assertCreated()
                ->assertJsonPath(
                    'message',
                    'Cash Disbursement created successfully.',
                )
                ->assertJsonPath(
                    'data.form_type',
                    RequestFormType::CashDisbursement->value,
                )
                ->assertJsonPath('data.requester.id', $requester->id)
                ->assertJsonPath('data.branch.id', $branch->id);
        }

        $this->assertDatabaseCount('request_forms', 3);
    }

    public function test_account_without_an_allowed_role_is_forbidden(): void
    {
        $branch = $this->branch('NO-ROLE');
        $requester = $this->user($branch, null);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $this->postJson(
            self::ROUTE,
            $this->payload([$approver->id]),
        )->assertForbidden();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_currency_is_required_and_allowlisted(): void
    {
        $branch = $this->branch('CURRENCY');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload([$approver->id]);
        unset($payload['currency']);

        /*
         * The database column defaults to PHP, but the endpoint must not let
         * that default silently fill a missing requester choice.
         */
        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $payload = $this->payload([$approver->id]);
        $payload['currency'] = 'GBP';

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_every_supported_currency_is_stored_exactly(): void
    {
        $branch = $this->branch('DENOMINATION');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        foreach (['PHP', 'EUR', 'USD'] as $currency) {
            $this->postJson(
                self::ROUTE,
                $this->payload(
                    [$approver->id],
                    overrides: ['currency' => $currency],
                ),
            )
                ->assertCreated()
                ->assertJsonPath('data.currency', $currency);
        }

        $this->assertSame(
            ['EUR', 'PHP', 'USD'],
            RequestForm::query()
                ->orderBy('currency')
                ->pluck('currency')
                ->all(),
        );
    }

    public function test_kind_of_request_is_required_and_allowlisted(): void
    {
        $branch = $this->branch('KIND');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload([$approver->id]);
        unset($payload['kind_of_request']);

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind_of_request']);

        $payload = $this->payload([$approver->id]);
        $payload['kind_of_request'] = 'Not A Real Kind';

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind_of_request']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_approved_by_is_required_and_not_empty_but_noted_by_is_optional(): void
    {
        $branch = $this->branch('APPROVERS');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload([$approver->id]);
        unset($payload['approved_by_ids']);

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->postJson(
            self::ROUTE,
            $this->payload([]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        /*
         * Omitting Noted By is valid and produces only the Approved By step.
         */
        $payload = $this->payload([$approver->id]);
        unset($payload['noted_by_ids']);

        $this->postJson(self::ROUTE, $payload)
            ->assertCreated()
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::ApprovedBy->value,
            );
    }

    public function test_duplicate_approver_ids_are_rejected(): void
    {
        $branch = $this->branch('DUPLICATE');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $this->postJson(
            self::ROUTE,
            $this->payload([$approver->id, $approver->id]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids.1']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_user_cannot_be_both_noted_by_and_approved_by(): void
    {
        $branch = $this->branch('OVERLAP');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $this->postJson(
            self::ROUTE,
            $this->payload(
                [$approver->id],
                [$approver->id],
            ),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_ineligible_and_self_approvers_are_rejected(): void
    {
        $branch = $this->branch('ELIGIBLE');
        $otherBranch = $this->branch('OTHER');
        $requester = $this->user($branch, 'approver');
        $ineligible = $this->user($otherBranch, 'approver');

        Sanctum::actingAs($requester);

        /*
         * The requester cannot approve their own request. The resolver
         * excludes the authenticated user from the eligible query.
         */
        $this->postJson(
            self::ROUTE,
            $this->payload([$requester->id]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        /*
         * An approver from an unrelated branch passes the exists rule but is
         * rejected when the service re-resolves routing at submission time.
         */
        $this->postJson(
            self::ROUTE,
            $this->payload([$ineligible->id]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_requester_without_a_resolved_branch_receives_validation_error(): void
    {
        $requester = $this->userWithoutBranch();
        $approver = $this->user($this->branch('TEMP'), 'approver');

        Sanctum::actingAs($requester);

        $this->postJson(
            self::ROUTE,
            $this->payload([$approver->id]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_creation_contract_is_server_controlled(): void
    {
        $branch = $this->branch('CONTRACT');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $response = $this->postJson(
            self::ROUTE,
            $this->payload([$approver->id]),
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.form_type',
                'Cash Disbursement Requisition Slip',
            )
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath('data.requester.id', $requester->id)
            ->assertJsonPath('data.branch.id', $branch->id);

        $requestForm = RequestForm::query()->sole();

        $this->assertSame(
            $requester->id,
            $requestForm->requester_id,
        );
        $this->assertSame($branch->id, $requestForm->branch_id);
        $this->assertSame(
            RequestFormType::CashDisbursement,
            $requestForm->form_type,
        );
        $this->assertSame(RequestStatus::Pending, $requestForm->status);
        $this->assertNull($requestForm->form_data);

        $this->assertMatchesRegularExpression(
            "/^{$branch->branch_code}-CD-\d{7}$/",
            $requestForm->request_code,
        );
    }

    public function test_items_follow_submitted_order_and_laravel_calculates_totals(): void
    {
        $branch = $this->branch('TOTALS');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload(
            [$approver->id],
            overrides: [
                'items' => [
                    [
                        'quantity' => 2,
                        'description' => 'First item',
                        'unit_cost' => '19.99',
                        'remarks' => null,
                    ],
                    [
                        'quantity' => 3,
                        'description' => 'Second item',
                        'unit_cost' => '0.01',
                        'remarks' => 'Remainder',
                    ],
                    [
                        'quantity' => 1,
                        'description' => 'Third item',
                        'unit_cost' => '0.02',
                        'remarks' => null,
                    ],
                ],
            ],
        );

        $this->postJson(self::ROUTE, $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.line_total', '39.98')
            ->assertJsonPath('data.items.1.line_number', 2)
            ->assertJsonPath('data.items.1.line_total', '0.03')
            ->assertJsonPath('data.items.2.line_number', 3)
            ->assertJsonPath('data.items.2.line_total', '0.02')
            ->assertJsonPath('data.grand_total', '40.03');

        $requestForm = RequestForm::query()->sole();

        $this->assertSame(
            ['39.98', '0.03', '0.02'],
            $requestForm->items()
                ->orderBy('line_number')
                ->pluck('line_total')
                ->all(),
        );
        $this->assertSame('40.03', $requestForm->grand_total);
    }

    public function test_client_supplied_item_totals_and_server_owned_fields_are_rejected(): void
    {
        $branch = $this->branch('PROHIBITED');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload([$approver->id]);
        $payload['items'][0]['line_number'] = 99;
        $payload['items'][0]['line_total'] = '999.99';
        $payload['items'][0]['total_amount'] = '999.99';
        $payload['items'][0]['totalAmount'] = '999.99';
        $payload['date'] = '2026-01-01';
        $payload['supplier_id'] = 999;
        $payload['removed_attachment_ids'] = [1];
        $payload['user_id'] = 999;

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.0.line_number',
                'items.0.line_total',
                'items.0.total_amount',
                'items.0.totalAmount',
                'date',
                'supplier_id',
                'removed_attachment_ids',
                'user_id',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_invalid_item_fields_are_rejected(): void
    {
        $branch = $this->branch('ITEMS');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload(
            [$approver->id],
            overrides: [
                'items' => [
                    [
                        'quantity' => 0,
                        'description' => '',
                        'unit_cost' => '12.345',
                        'remarks' => str_repeat('x', 2001),
                        'unit' => 'box',
                    ],
                ],
            ],
        );

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.0',
                'items.0.quantity',
                'items.0.description',
                'items.0.unit_cost',
                'items.0.remarks',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_attachment_contract_enforces_type_size_count_and_private_storage(): void
    {
        Storage::fake('local');

        $branch = $this->branch('FILES');
        $requester = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $payload = $this->payload([$approver->id]);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'script.php',
                10,
                'application/x-httpd-php',
            ),
        ];

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);

        $payload = $this->payload([$approver->id]);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'too-large.pdf',
                5121,
                'application/pdf',
            ),
        ];

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);

        $payload = $this->payload([$approver->id]);
        $payload['attachments'] = collect(range(1, 11))
            ->map(
                fn (int $number): UploadedFile => UploadedFile::fake()
                    ->create("file-{$number}.pdf", 10, 'application/pdf'),
            )
            ->all();

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $payload = $this->payload([$approver->id]);
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'voucher.pdf',
                100,
                'application/pdf',
            ),
        ];

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertCreated()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'voucher.pdf',
            )
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path');

        $attachment = RequestForm::query()
            ->sole()
            ->attachments()
            ->sole();

        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_noted_by_steps_precede_approved_by_steps_in_submitted_order(): void
    {
        $branch = $this->branch('ORDER');
        $requester = $this->user($branch);
        $notedFirst = $this->user($branch, 'approver');
        $notedSecond = $this->user($branch, 'approver');
        $approvedFirst = $this->user($branch, 'approver');
        $approvedSecond = $this->user($branch, 'approver');

        Sanctum::actingAs($requester);

        $this->postJson(
            self::ROUTE,
            $this->payload(
                [$approvedFirst->id, $approvedSecond->id],
                [$notedFirst->id, $notedSecond->id],
            ),
        )
            ->assertCreated()
            ->assertJsonCount(4, 'data.approval_steps')
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::NotedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $notedFirst->id,
            )
            ->assertJsonPath('data.approval_steps.1.level', 2)
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $notedSecond->id,
            )
            ->assertJsonPath('data.approval_steps.2.level', 3)
            ->assertJsonPath(
                'data.approval_steps.2.step_type',
                ApprovalStepType::ApprovedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.2.approver.id',
                $approvedFirst->id,
            )
            ->assertJsonPath('data.approval_steps.3.level', 4)
            ->assertJsonPath(
                'data.approval_steps.3.approver.id',
                $approvedSecond->id,
            );

        $steps = RequestForm::query()
            ->sole()
            ->approvalSteps()
            ->get();

        $this->assertSame(
            [
                ApprovalStepStatus::Pending,
                ApprovalStepStatus::Pending,
                ApprovalStepStatus::Pending,
                ApprovalStepStatus::Pending,
            ],
            $steps->pluck('status')->all(),
        );
    }

    public function test_avp_delegated_staff_step_is_inserted_for_cash_disbursements(): void
    {
        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO');
        $requester = $this->user($requesterBranch);
        $avp = $this->user($headOffice, 'approver', 'AVP - Finance');
        $staff = $this->user($requesterBranch, 'approver');

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        Sanctum::actingAs($requester);

        $this->postJson(
            self::ROUTE,
            $this->payload([$avp->id]),
        )
            ->assertCreated()
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::DelegatedApproval->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $staff->id,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Pending->value,
            )
            ->assertJsonPath('data.approval_steps.1.level', 2)
            ->assertJsonPath(
                'data.approval_steps.1.step_type',
                ApprovalStepType::ApprovedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $avp->id,
            );
    }

    /**
     * Build valid Cash Disbursement input.
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
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "CashStore{$number}",
            'lastName' => 'User',
            'contact' => "0906000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "cash-store-{$number}",
            'employee_id' => "CASH-STORE-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function userWithoutBranch(): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "CashNoBranch{$number}",
            'lastName' => 'User',
            'contact' => "0907000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "cash-no-branch-{$number}",
            'employee_id' => "CASH-NOBRANCH-{$number}",
        ]);

        $user->assignRole('User');

        return $user;
    }
}
