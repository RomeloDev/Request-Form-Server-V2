<?php

namespace Tests\Feature\RequestForms;

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
            $this->validPayload(),
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
            $this->validPayload(),
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

        Sanctum::actingAs($user);

        $payload = $this->validPayload();

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
            ->assertJsonCount(1, 'data.attachments');

        /*
         * The Resource must not expose private storage information.
         */
        $response
            ->assertJsonMissingPath(
                'data.attachments.0.disk'
            )
            ->assertJsonMissingPath(
                'data.attachments.0.path'
            );

        $requestForm = RequestForm::query()
            ->with([
                'items',
                'attachments',
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

        Sanctum::actingAs($user);

        $payload = [
            ...$this->validPayload(),

            /*
             * An attacker may try to submit a request as another user,
             * force a completed status, or choose their own total.
             */
            'requester_id' => 999999,
            'branch_id' => 999999,
            'status' => 'Completed',
            'grand_total' => '0.01',
            'request_code' => 'FAKE-001',
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
            ]);

        $this->assertDatabaseCount('request_forms', 0);
        $this->assertDatabaseCount('request_items', 0);
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
    private function validPayload(): array
    {
        return [
            'kind_of_request' => 'Office supplies',
            'form_data' => [
                'purpose' => 'Replace depleted office supplies.',
            ],
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
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "User{$number}",
            'lastName' => 'Example',
            'contact' => "0917000{$number}",
            'branch_code' => $branch?->branch_code,
            'branch' => $branch?->branch,
            'position' => 'Employee',
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
            $this->validPayload(),
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
            $this->validPayload(),
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

        Sanctum::actingAs($user);

        $payload = $this->validPayload();

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

        Sanctum::actingAs($user);

        $payload = $this->validPayload();

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

        Sanctum::actingAs($user);

        $payload = $this->validPayload();

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

        $payload = $this->validPayload();

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
