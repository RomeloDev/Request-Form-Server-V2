<?php

namespace Tests\Feature\RequestForms;

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

class StoreStockRequisitionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Forget cached permissions so every test starts with a clean
         * Spatie permission state.
         */
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $this->validPayload([999999]),
        )->assertUnauthorized();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_account_without_an_application_role_is_forbidden(): void
    {
        $branch = $this->branch('TEST');

        /*
         * Passing null means the user receives no Spatie role.
         */
        $user = $this->user(
            branch: $branch,
            role: null,
        );

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $this->validPayload([$user->id]),
        )->assertForbidden();

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_user_can_create_a_stock_requisition_with_an_attachment(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');

        $user = $this->user(
            branch: $branch,
            role: 'User',
        );
        $notedBy = $this->user($branch, 'approver');
        $approvedBy = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $payload = $this->validPayload(
            approvedByIds: [$approvedBy->id],
            notedByIds: [$notedBy->id],
        );

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'quotation.pdf',
                100,
                'application/pdf',
            ),
        ];

        /*
         * post() is used rather than postJson() because this request
         * contains an uploaded file and must use multipart form data.
         */
        $response = $this->post(
            '/api/v1/request-forms/stock-requisitions',
            $payload,
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Stock Requisition created successfully.',
            )
            ->assertJsonPath(
                'data.form_type',
                'Stock Requisition Slip',
            )
            ->assertJsonPath(
                'data.status',
                'Pending',
            )
            ->assertJsonPath(
                'data.currency',
                'PHP',
            )
            ->assertJsonPath(
                'data.grand_total',
                '310.95',
            )
            ->assertJsonPath(
                'data.requester.id',
                $user->id,
            )
            ->assertJsonPath(
                'data.branch.id',
                $branch->id,
            )
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.level',
                1,
            )
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                'noted_by',
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $notedBy->id,
            )
            ->assertJsonPath(
                'data.approval_steps.1.level',
                2,
            )
            ->assertJsonPath(
                'data.approval_steps.1.step_type',
                'approved_by',
            )
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $approvedBy->id,
            );

        /*
         * The Resource must not expose private storage information.
         */
        $response
            ->assertJsonMissingPath(
                'data.attachments.0.disk'
            )
            ->assertJsonMissingPath(
                'data.attachments.0.path'
            )
            ->assertJsonMissingPath(
                'data.approval_steps.0.approver.email'
            )
            ->assertJsonMissingPath(
                'data.approval_steps.0.approver.roles'
            );

        $requestForm = RequestForm::query()
            ->with([
                'items',
                'attachments',
                'approvalSteps',
            ])
            ->sole();

        /*
         * The requester and branch must come from the authenticated
         * user—not from client-supplied identifiers.
         */
        $this->assertSame(
            $user->id,
            $requestForm->requester_id,
        );

        $this->assertSame(
            $branch->id,
            $requestForm->branch_id,
        );

        $this->assertSame(
            '310.95',
            $requestForm->grand_total,
        );

        $this->assertStringStartsWith(
            'TEST-SRL-',
            $requestForm->request_code,
        );

        $this->assertCount(2, $requestForm->items);
        $this->assertCount(2, $requestForm->approvalSteps);
        $this->assertSame(
            [1, 2],
            $requestForm->approvalSteps
                ->pluck('level')
                ->all(),
        );
        $this->assertSame(
            [$notedBy->id, $approvedBy->id],
            $requestForm->approvalSteps
                ->pluck('approver_id')
                ->all(),
        );
        $this->assertSame(
            '250.50',
            $requestForm->items[0]->line_total,
        );
        $this->assertSame(
            '60.45',
            $requestForm->items[1]->line_total,
        );

        $attachment = $requestForm->attachments->sole();

        $this->assertSame(
            'quotation.pdf',
            $attachment->original_name,
        );

        Storage::disk('local')->assertExists(
            $attachment->path
        );
    }

    public function test_server_controlled_fields_are_rejected(): void
    {
        $branch = $this->branch('TEST');
        $user = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $payload = [
            ...$this->validPayload([$approvedBy->id]),

            /*
             * An attacker may try to submit a request as another user,
             * force a completed status, or choose their own total.
             */
            'requester_id' => 999999,
            'branch_id' => 999999,
            'status' => 'Completed',
            'grand_total' => '0.01',
            'request_code' => 'FAKE-001',
            'approval_steps' => [
                [
                    'approver_id' => $approvedBy->id,
                    'level' => 99,
                    'status' => 'Approved',
                ],
            ],
            'approver_id' => $approvedBy->id,
            'level' => 99,
            'step_type' => 'approved_by',
            'approval_status' => 'Approved',
            'acted_at' => now()->toISOString(),
        ];

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $payload,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'requester_id',
                'branch_id',
                'status',
                'grand_total',
                'request_code',
                'approval_steps',
                'approver_id',
                'level',
                'step_type',
                'approval_status',
                'acted_at',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
    }

    public function test_approved_by_is_required_and_cannot_overlap_noted_by(): void
    {
        $branch = $this->branch('TEST');
        $user = $this->user($branch);
        $approver = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $missingApprovedBy = $this->validPayload([$approver->id]);
        unset($missingApprovedBy['approved_by_ids']);

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $missingApprovedBy,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $this->validPayload(
                approvedByIds: [$approver->id],
                notedByIds: [$approver->id],
            ),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approved_by_ids']);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_approval_steps', 0);
    }

    public function test_avp_delegated_staff_is_saved_before_the_avp(): void
    {
        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO');
        $requester = $this->user($requesterBranch);
        $avp = $this->user(
            branch: $headOffice,
            role: 'approver',
            position: 'AVP - Finance',
        );
        $staff = $this->user($requesterBranch, 'approver');

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        Sanctum::actingAs($requester);

        $response = $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $this->validPayload([$avp->id]),
        );

        $response
            ->assertCreated()
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                'delegated_approval',
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $staff->id,
            )
            ->assertJsonPath(
                'data.approval_steps.1.step_type',
                'approved_by',
            )
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $avp->id,
            );

        $requestForm = RequestForm::query()
            ->with('approvalSteps')
            ->sole();

        $this->assertSame(
            [$staff->id, $avp->id],
            $requestForm->approvalSteps
                ->pluck('approver_id')
                ->all(),
        );
    }

    /**
     * Build valid client-editable input.
     *
     * The expected calculations are:
     *
     * 2.5 × 100.20 = 250.50
     * 3   × 20.15  =  60.45
     * Grand total  = 310.95
     *
     * @return array<string, mixed>
     */
    private function validPayload(
        array $approvedByIds,
        array $notedByIds = [],
    ): array {
        return [
            'kind_of_request' => 'Office supplies',
            'form_data' => [
                'purpose' => 'Replace depleted office supplies.',
            ],
            'noted_by_ids' => $notedByIds,
            'approved_by_ids' => $approvedByIds,
            'items' => [
                [
                    'quantity' => '2.5',
                    'unit' => 'ream',
                    'description' => 'A4 bond paper',
                    'unit_cost' => '100.20',
                    'remarks' => 'For Accounting',
                ],
                [
                    'quantity' => '3',
                    'unit' => 'box',
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
            'userName' => "request-user-{$number}",
            'employee_id' => "REQUEST-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_user_without_an_assigned_branch_cannot_create_a_request(): void
    {
        $user = $this->user(
            branch: null,
            role: 'User',
        );

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $this->validPayload([$user->id]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch']);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
    }

    public function test_user_with_an_unknown_branch_code_cannot_create_a_request(): void
    {
        $branch = $this->branch('REAL');
        $user = $this->user($branch);

        /*
        * Simulate stale or invalid account data where the user's branch_code
        * does not match any branches table record.
        */
        $user->forceFill([
            'branch_code' => 'MISSING',
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/v1/request-forms/stock-requisitions',
            $this->validPayload([$user->id]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch']);

        $this->assertDatabaseCount('request_forms', 0);
    }

    public function test_disallowed_attachment_type_is_rejected(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $user = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $payload = $this->validPayload([$approvedBy->id]);

        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'malicious.php',
                10,
                'application/x-httpd-php',
            ),
        ];

        $this->post(
            '/api/v1/request-forms/stock-requisitions',
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'attachments.0',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    public function test_oversized_attachment_is_rejected(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $user = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $payload = $this->validPayload([$approvedBy->id]);

        /*
        * UploadedFile::fake()->create() receives its size in kilobytes.
        *
        * The validator allows 5,120 KB, so 5,121 KB must fail.
        */
        $payload['attachments'] = [
            UploadedFile::fake()->create(
                'oversized.pdf',
                5121,
                'application/pdf',
            ),
        ];

        $this->post(
            '/api/v1/request-forms/stock-requisitions',
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'attachments.0',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    public function test_more_than_ten_attachments_are_rejected(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $user = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

        Sanctum::actingAs($user);

        $payload = $this->validPayload([$approvedBy->id]);

        $payload['attachments'] = collect(range(1, 11))
            ->map(
                fn (int $number): UploadedFile => UploadedFile::fake()
                    ->create(
                        "attachment-{$number}.pdf",
                        10,
                        'application/pdf',
                    )
            )
            ->all();

        $this->post(
            '/api/v1/request-forms/stock-requisitions',
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'attachments',
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    public function test_stored_files_are_removed_when_database_transaction_fails(): void
    {
        Storage::fake('local');

        $branch = $this->branch('TEST');
        $user = $this->user($branch);
        $approvedBy = $this->user($branch, 'approver');

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

        $payload = $this->validPayload([$approvedBy->id]);

        $payload['attachments'] = [
            $sameFile,
            $sameFile,
        ];

        /*
        * Laravel handles the database exception and converts it into a 500
        * response. The service must clean up before rethrowing it.
        */
        $this->post(
            '/api/v1/request-forms/stock-requisitions',
            $payload,
            ['Accept' => 'application/json'],
        )->assertServerError();

        /*
        * Every database operation happened inside one transaction, so no
        * partial request data should remain.
        */
        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
        $this->assertDatabaseCount('request_attachments', 0);

        /*
        * The transaction cannot remove physical files, so this assertion
        * specifically proves that the service catch block removed them.
        */
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }
}
