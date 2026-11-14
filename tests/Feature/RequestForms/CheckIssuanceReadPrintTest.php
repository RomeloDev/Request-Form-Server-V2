<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use App\Services\CheckIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CheckIssuanceReadPrintTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_owner_details_return_the_safe_check_issuance_contract(): void
    {
        Storage::fake('local');

        $branch = $this->branch('DETAIL');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->createCheck(
            requester: $owner,
            approvedByIds: [$approver->id],
            withAttachment: true,
        );

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/request-forms/{$requestForm->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Request retrieved successfully.')
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CheckIssuance->value,
            )
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.form_data.payee', 'Example Payee')
            ->assertJsonPath('data.form_data.bank', 'Example Bank')
            ->assertJsonPath('data.form_data.account_no', '00123456789')
            ->assertJsonPath('data.form_data.swift_code', 'EXAMPHMM')
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'check-support.pdf',
            )
            ->assertJsonCount(1, 'data.approval_steps')
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path');
    }

    public function test_assigned_approver_details_preserve_check_fields_and_totals(): void
    {
        $branch = $this->branch('ASSIGNED');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->createCheck(
            requester: $owner,
            approvedByIds: [$approver->id],
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
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CheckIssuance->value,
            )
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.form_data.payee', 'Example Payee')
            ->assertJsonPath('data.form_data.bank', 'Example Bank')
            ->assertJsonPath('data.form_data.account_no', '00123456789')
            ->assertJsonPath('data.form_data.swift_code', 'EXAMPHMM')
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonMissingPath('data.requester.email');
    }

    public function test_print_returns_the_safe_check_header_items_and_totals(): void
    {
        $branch = $this->branch('PRINT');
        $owner = $this->user($branch);
        $approver = $this->user($branch, 'approver', 'Area Manager');
        $requestForm = $this->createCheck(
            requester: $owner,
            approvedByIds: [$approver->id],
        );

        Sanctum::actingAs($owner);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Printable request retrieved successfully.',
            )
            ->assertJsonPath(
                'data.form_type',
                RequestFormType::CheckIssuance->value,
            )
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.form_data.payee', 'Example Payee')
            ->assertJsonPath('data.form_data.bank', 'Example Bank')
            ->assertJsonPath('data.form_data.account_no', '00123456789')
            ->assertJsonPath('data.form_data.swift_code', 'EXAMPHMM')
            ->assertJsonPath('data.grand_total', '260.85')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '200.40')
            ->assertJsonPath('data.items.1.line_total', '60.45')
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $approver->id,
            )
            ->assertJsonMissingPath('data.attachments')
            ->assertJsonMissingPath('data.cancellation_reason')
            ->assertJsonMissingPath('data.requester.signature');
    }

    public function test_print_authorization_is_limited_to_owner_assigned_approver_and_shared_recipient(): void
    {
        $branch = $this->branch('PRINT-AUTH');
        $owner = $this->user($branch);
        $assignedApprover = $this->user($branch, 'approver');
        $unassignedApprover = $this->user($branch, 'approver');
        $recipient = $this->user($branch);
        $requestForm = $this->createCheck(
            requester: $owner,
            approvedByIds: [$assignedApprover->id],
        );

        Sanctum::actingAs($unassignedApprover);
        $this->getJson($this->printUrl($requestForm))->assertForbidden();

        $requestForm->sharedRecipients()->attach($recipient->id);

        foreach ([$recipient, $owner, $assignedApprover] as $allowedUser) {
            Sanctum::actingAs($allowedUser);

            $this->getJson($this->printUrl($requestForm))
                ->assertOk()
                ->assertJsonPath(
                    'data.form_type',
                    RequestFormType::CheckIssuance->value,
                )
                ->assertJsonPath('data.form_data.bank', 'Example Bank');
        }
    }

    private function printUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/print";
    }

    /** @param  list<int>  $approvedByIds */
    private function createCheck(
        User $requester,
        array $approvedByIds,
        bool $withAttachment = false,
    ): RequestForm {
        $payload = [
            'kind_of_request' => 'Office Supplies',
            'form_data' => [
                'payee' => 'Example Payee',
                'bank' => 'Example Bank',
                'account_no' => '00123456789',
                'swift_code' => 'EXAMPHMM',
            ],
            'noted_by_ids' => [],
            'approved_by_ids' => $approvedByIds,
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'First payment item',
                    'unit_cost' => '100.20',
                    'remarks' => null,
                ],
                [
                    'quantity' => 3,
                    'description' => 'Second payment item',
                    'unit_cost' => '20.15',
                    'remarks' => null,
                ],
            ],
        ];

        if ($withAttachment) {
            $payload['attachments'] = [
                UploadedFile::fake()->create(
                    'check-support.pdf',
                    100,
                    'application/pdf',
                ),
            ];
        }

        return app(CheckIssuanceService::class)->create(
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
            'firstName' => "CheckRead{$number}",
            'lastName' => 'User',
            'contact' => "0912000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "check-read-{$number}",
            'employee_id' => "CHECK-READ-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
