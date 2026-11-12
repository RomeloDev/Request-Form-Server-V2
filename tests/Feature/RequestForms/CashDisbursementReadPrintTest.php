<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use App\Services\CashDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashDisbursementReadPrintTest extends TestCase
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

    public function test_my_request_details_return_the_safe_cash_contract(): void
    {
        Storage::fake('local');

        $branch = $this->branch('DETAIL');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->createCash(
            requester: $owner,
            approvedByIds: [$approver->id],
            currency: 'USD',
            withAttachment: true,
        );

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/request-forms/{$requestForm->id}")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.request_code',
                $requestForm->request_code,
            )
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.kind_of_request', 'Office Supplies')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.description', 'A4 bond paper')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_number', 2)
            ->assertJsonPath('data.items.1.line_total', '60.45')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'cash-voucher.pdf',
            )
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            )
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path');
    }

    public function test_assigned_request_details_return_the_selected_currency_and_totals(): void
    {
        $branch = $this->branch('ASSIGNED');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->createCash(
            requester: $owner,
            approvedByIds: [$approver->id],
            currency: 'EUR',
        );

        Sanctum::actingAs($approver);

        $this->getJson(
            "/api/v1/approver/request-forms/{$requestForm->id}",
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Assigned request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.form_data', null)
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_total', '60.45')
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonMissingPath('data.requester.email');
    }

    public function test_print_returns_cash_items_and_totals_without_stock_or_purchase_order_fields(): void
    {
        $branch = $this->branch('PRINT');
        $owner = $this->user($branch);
        $approver = $this->user(
            $branch,
            'approver',
            'Area Manager',
        );
        $requestForm = $this->createCash(
            requester: $owner,
            approvedByIds: [$approver->id],
            currency: 'USD',
        );

        Sanctum::actingAs($owner);

        $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/print",
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Printable request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CashDisbursement->value,
            )
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonPath('data.purpose', '')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.description', 'A4 bond paper')
            ->assertJsonPath('data.items.0.unit_cost', '100.20')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_total', '60.45')
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            )
            ->assertJsonMissingPath('data.form_data')
            ->assertJsonMissingPath('data.attachments')
            ->assertJsonMissingPath('data.cancellation_reason')
            ->assertJsonMissingPath('data.requester.signature');
    }

    public function test_print_authorization_remains_owner_approver_and_shared_recipient_only(): void
    {
        $branch = $this->branch('PRINT-AUTH');
        $owner = $this->user($branch);
        $assignedApprover = $this->user($branch, 'approver');
        $unassignedApprover = $this->user($branch, 'approver');
        $recipient = $this->user($branch);
        $requestForm = $this->createCash(
            requester: $owner,
            approvedByIds: [$assignedApprover->id],
            currency: 'EUR',
        );

        Sanctum::actingAs($unassignedApprover);

        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR');

        Sanctum::actingAs($owner);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR');

        Sanctum::actingAs($assignedApprover);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR');
    }

    private function printUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/print";
    }

    /**
     * @param  list<int>  $approvedByIds
     */
    private function createCash(
        User $requester,
        array $approvedByIds,
        string $currency,
        bool $withAttachment = false,
    ): RequestForm {
        $payload = [
            'kind_of_request' => 'Office Supplies',
            'currency' => $currency,
            'noted_by_ids' => [],
            'approved_by_ids' => $approvedByIds,
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'A4 bond paper',
                    'unit_cost' => '100.20',
                    'remarks' => null,
                ],
                [
                    'quantity' => 3,
                    'description' => 'Black ballpens',
                    'unit_cost' => '20.15',
                    'remarks' => null,
                ],
            ],
        ];

        if ($withAttachment) {
            $payload['attachments'] = [
                UploadedFile::fake()->create(
                    'cash-voucher.pdf',
                    100,
                    'application/pdf',
                ),
            ];
        }

        return app(CashDisbursementService::class)->create(
            requester: $requester,
            data: $payload,
        );
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
            'firstName' => "CashRead{$number}",
            'lastName' => 'User',
            'contact' => "0911000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "cash-read-{$number}",
            'employee_id' => "CASH-READ-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
