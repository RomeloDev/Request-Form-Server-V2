<?php

namespace Tests\Feature\RequestForms;

use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StorePurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/v1/request-forms/purchase-orders';

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

    public function test_route_requires_authentication(): void
    {
        $this->postJson(self::ROUTE, $this->payload([999999]))
            ->assertUnauthorized();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_account_without_an_application_role_is_forbidden(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: null,
        );

        Sanctum::actingAs($user);

        $this->postJson(self::ROUTE, $this->payload([$user->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_user_admin_and_approver_roles_can_reach_the_contract(): void
    {
        $branch = $this->branch('TEST');

        foreach (['User', 'Admin', 'approver'] as $role) {
            $requester = $this->user(
                branch: $branch,
                role: $role,
            );

            $approvedBy = $this->user(
                branch: $branch,
                role: 'approver',
            );

            Sanctum::actingAs($requester);

            $this->postJson(self::ROUTE, $this->payload([$approvedBy->id]))
                ->assertCreated()
                ->assertJsonPath(
                    'message',
                    'Purchase Order created successfully.',
                );
        }

        $this->assertDatabaseCount('request_forms', 3);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        Sanctum::actingAs($user);

        $this->postJson(self::ROUTE, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'kind_of_request',
                'form_data',
                'approved_by_ids',
                'items',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_invalid_kind_of_request_is_rejected(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['kind_of_request'] = 'Not A Real Kind';

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind_of_request']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_supplier_and_address_length_limits_are_enforced(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['form_data']['supplier'] = str_repeat('a', 256);
        $payload['form_data']['address'] = str_repeat('b', 256);

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'form_data.supplier',
                'form_data.address',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_supplier_id_is_prohibited(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['supplier_id'] = 1;

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_requester_user_and_branch_identity_fields_are_prohibited(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['requester_id'] = 999999;
        $payload['user_id'] = 999999;
        $payload['branch_id'] = 999999;

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'requester_id',
                'user_id',
                'branch_id',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_form_type_status_currency_request_code_and_grand_total_are_prohibited(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['form_type'] = 'Stock Requisition Slip';
        $payload['status'] = 'Completed';
        $payload['currency'] = 'USD';
        $payload['request_code'] = 'FAKE-CODE';
        $payload['grand_total'] = '0.01';

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'form_type',
                'status',
                'currency',
                'request_code',
                'grand_total',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_browser_line_totals_and_grand_total_are_rejected(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['items'][0]['line_number'] = 99;
        $payload['items'][0]['line_total'] = '999.99';
        $payload['items'][0]['total_amount'] = '999.99';
        $payload['items'][0]['totalAmount'] = '999.99';
        $payload['items'][1]['line_total'] = '888.88';
        $payload['form_data']['grand_total'] = '0.01';

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.0.line_number',
                'items.0.line_total',
                'items.0.total_amount',
                'items.0.totalAmount',
                'items.1.line_total',
                'form_data.grand_total',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_duplicate_ids_inside_each_approver_list_are_rejected(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        $notedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id, $approvedBy->id]);

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids.1']);

        $payload = $this->payload([$approvedBy->id]);

        $payload['noted_by_ids'] = [$notedBy->id, $notedBy->id];

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['noted_by_ids.1']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_user_in_both_noted_by_and_approved_by_is_rejected(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approver = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approver->id]);

        $payload['noted_by_ids'] = [$approver->id];

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_at_least_one_approved_by_user_is_required(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        unset($payload['approved_by_ids']);

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $payload = $this->payload([$approvedBy->id]);

        $payload['approved_by_ids'] = [];

        $this->postJson(self::ROUTE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_invalid_and_ineligible_approvers_are_rejected(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        Sanctum::actingAs($user);

        /*
         * A non-existent user fails the exists rule during validation.
         */
        $this->postJson(self::ROUTE, $this->payload([999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids.0']);

        /*
         * An existing approver from another branch passes validation but is
         * rejected by the approval chain resolver at submission time.
         */
        $otherBranch = $this->branch('OTHER');

        $ineligible = $this->user(
            branch: $otherBranch,
            role: 'approver',
        );

        $this->postJson(self::ROUTE, $this->payload([$ineligible->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_successful_creation_returns_created_and_expected_message(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $this->postJson(self::ROUTE, $this->payload([$approvedBy->id]))
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Purchase Order created successfully.',
            );
    }

    public function test_requester_and_branch_come_from_the_authenticated_account(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $response = $this->postJson(
            self::ROUTE,
            $this->payload([$approvedBy->id]),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.requester.id', $user->id)
            ->assertJsonPath('data.branch.id', $branch->id);

        $requestForm = RequestForm::query()->sole();

        $this->assertSame($user->id, $requestForm->requester_id);
        $this->assertSame($branch->id, $requestForm->branch_id);
        $this->assertStringStartsWith(
            'TEST-PO-',
            $requestForm->request_code,
        );
    }

    public function test_form_type_is_purchase_order_requisition_slip(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $this->postJson(self::ROUTE, $this->payload([$approvedBy->id]))
            ->assertCreated()
            ->assertJsonPath(
                'data.form_type',
                'Purchase Order Requisition Slip',
            );
    }

    public function test_request_code_contains_branch_and_po_discriminator(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $response = $this->postJson(
            self::ROUTE,
            $this->payload([$approvedBy->id]),
        );

        $requestCode = $response->json('data.request_code');

        $this->assertMatchesRegularExpression(
            '/^TEST-PO-\d{7}$/',
            $requestCode,
        );

        $this->assertStringContainsString('TEST', $requestCode);
        $this->assertStringContainsString('-PO-', $requestCode);
    }

    public function test_items_preserve_order_and_receive_server_calculated_line_totals(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $response = $this->postJson(
            self::ROUTE,
            $this->payload([$approvedBy->id]),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.description', 'A4 bond paper')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_number', 2)
            ->assertJsonPath('data.items.1.description', 'Black ballpens')
            ->assertJsonPath('data.items.1.line_total', '60.45');

        $requestForm = RequestForm::query()->with('items')->sole();

        $this->assertSame(
            ['200.40', '60.45'],
            $requestForm->items->pluck('line_total')->all(),
        );
    }

    public function test_grand_total_is_calculated_by_laravel(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $this->postJson(self::ROUTE, $this->payload([$approvedBy->id]))
            ->assertCreated()
            ->assertJsonPath('data.grand_total', '260.85');

        $this->assertDatabaseHas('request_forms', [
            'grand_total' => '260.85',
        ]);
    }

    public function test_noted_by_steps_appear_before_approved_by_steps(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $notedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $response = $this->postJson(
            self::ROUTE,
            $this->payload([$approvedBy->id], [$notedBy->id]),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath('data.approval_steps.0.step_type', 'noted_by')
            ->assertJsonPath('data.approval_steps.0.approver.id', $notedBy->id)
            ->assertJsonPath('data.approval_steps.1.level', 2)
            ->assertJsonPath('data.approval_steps.1.step_type', 'approved_by')
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $approvedBy->id,
            );
    }

    public function test_avp_delegated_staff_is_inserted_before_the_avp(): void
    {
        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO');

        $requester = $this->user(
            branch: $requesterBranch,
            role: 'User',
        );

        $avp = $this->user(
            branch: $headOffice,
            role: 'approver',
            position: 'AVP - Finance',
        );

        $staff = $this->user(
            branch: $requesterBranch,
            role: 'approver',
        );

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);

        $assignment->branches()->attach($requesterBranch->id);

        Sanctum::actingAs($requester);

        $response = $this->postJson(
            self::ROUTE,
            $this->payload([$avp->id]),
        );

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                'delegated_approval',
            )
            ->assertJsonPath('data.approval_steps.0.approver.id', $staff->id)
            ->assertJsonPath('data.approval_steps.1.step_type', 'approved_by')
            ->assertJsonPath('data.approval_steps.1.approver.id', $avp->id);
    }

    public function test_new_supplier_is_inserted(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['form_data']['supplier'] = '  Tri-Star Motors  ';
        $payload['form_data']['address'] = '  123 Main Street  ';

        $this->postJson(self::ROUTE, $payload)->assertCreated();

        $this->assertDatabaseCount('suppliers', 1);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Tri-Star Motors',
            'address' => '123 Main Street',
        ]);
    }

    public function test_existing_supplier_address_is_updated_without_duplicate(): void
    {
        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Supplier::create([
            'name' => 'Tri-Star Motors',
            'address' => 'Old Address',
        ]);

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['form_data']['supplier'] = '  Tri-Star Motors  ';
        $payload['form_data']['address'] = '  456 New Street  ';

        $this->postJson(self::ROUTE, $payload)->assertCreated();

        $this->assertDatabaseCount('suppliers', 1);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Tri-Star Motors',
            'address' => '456 New Street',
        ]);
    }

    public function test_valid_attachment_is_stored_on_the_private_local_disk(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        $payload = $this->payload([$approvedBy->id]);

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'quotation.pdf',
                100,
                'application/pdf',
            ),
        ];

        /*
         * post() rather than postJson() because this request contains an
         * uploaded file and must use multipart form data.
         */
        $response = $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'quotation.pdf',
            )
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path');

        $attachment = RequestForm::query()->sole()->attachments()->sole();

        $this->assertSame('local', $attachment->disk);

        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_invalid_and_singular_attachment_fields_are_rejected(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        /*
         * A singular "attachment" field is prohibited.
         */
        $payload = $this->payload([$approvedBy->id]);

        $payload['attachment'] = UploadedFile::fake()->create(
            'quote.pdf',
            10,
            'application/pdf',
        );

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachment']);

        /*
         * "attachments" must be an array, not a single file.
         */
        $payload = $this->payload([$approvedBy->id]);

        $payload['attachments'] = UploadedFile::fake()->create(
            'quote.pdf',
            10,
            'application/pdf',
        );

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        /*
         * A disallowed file type is rejected.
         */
        $payload = $this->payload([$approvedBy->id]);

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'malicious.php',
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

        $this->assertDatabaseCount('request_forms', 0);

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    public function test_failed_transaction_leaves_no_partial_data_or_files(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );

        $approvedBy = $this->user(
            branch: $branch,
            role: 'approver',
        );

        Sanctum::actingAs($user);

        /*
         * UploadedFile caches its generated hash name.
         *
         * Supplying the same file object twice causes both storage operations
         * to use the same path. The first attachment metadata row succeeds,
         * while the second violates the database's unique path constraint.
         */
        $sameFile = UploadedFile::fake()->create(
            'duplicate.pdf',
            100,
            'application/pdf',
        );

        $payload = $this->payload([$approvedBy->id]);

        $payload['attachments'] = [$sameFile, $sameFile];

        $this->post(
            self::ROUTE,
            $payload,
            ['Accept' => 'application/json'],
        )->assertServerError();

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

    /**
     * Build valid Purchase Order input.
     *
     * The expected calculations are:
     *
     * 2 × 100.20 = 200.40
     * 3 × 20.15  =  60.45
     * Grand total = 260.85
     *
     * @param  list<int>  $approvedByIds
     * @param  list<int>  $notedByIds
     * @return array<string, mixed>
     */
    private function payload(
        array $approvedByIds,
        array $notedByIds = [],
    ): array {
        return [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'supplier' => 'Tri-Star Motors',
                'address' => '123 Main Street',
            ],
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
