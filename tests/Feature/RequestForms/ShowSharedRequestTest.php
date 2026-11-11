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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShowSharedRequestTest extends TestCase
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

    public function test_guest_receives_unauthorized(): void
    {
        $this->getJson('/api/v1/shared-requests/999999')
            ->assertUnauthorized();
    }

    public function test_shared_recipient_receives_the_correct_request(): void
    {
        $branch = $this->branch('CORRECT');
        $requester = $this->user($branch, 'User', 'CorrectRequester');
        $recipient = $this->user($branch, 'User', 'CorrectRecipient');

        $sharedRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'CORRECT-SHARED-001',
        );
        $otherRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'CORRECT-OTHER-001',
        );

        $sharedRequest->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            "/api/v1/shared-requests/{$sharedRequest->id}",
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Shared request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $sharedRequest->id)
            ->assertJsonPath(
                'data.request_code',
                'CORRECT-SHARED-001',
            )
            ->assertJsonPath('data.requester.id', $requester->id);

        $this->assertNotSame($otherRequest->id, $sharedRequest->id);
    }

    public function test_unshared_user_receives_forbidden(): void
    {
        $branch = $this->branch('UNSHARED');
        $requester = $this->user($branch, 'User', 'UnsharedRequester');
        $unsharedUser = $this->user($branch, 'User', 'UnsharedUser');
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($unsharedUser);

        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        )->assertForbidden();
    }

    public function test_roleless_shared_recipient_receives_forbidden(): void
    {
        $branch = $this->branch('ROLELESS');
        $requester = $this->user($branch, 'User', 'RolelessRequester');
        $recipient = $this->user(
            $branch,
            null,
            'RolelessRecipient',
        );
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        )->assertForbidden();
    }

    public function test_being_shared_a_different_request_does_not_grant_access(): void
    {
        $branch = $this->branch('DIFFERENT');
        $requester = $this->user($branch, 'User', 'DifferentRequester');
        $recipient = $this->user($branch, 'User', 'DifferentRecipient');

        $sharedRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'DIFFERENT-SHARED',
        );
        $notSharedRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'DIFFERENT-PRIVATE',
        );

        $sharedRequest->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            "/api/v1/shared-requests/{$notSharedRequest->id}",
        )->assertForbidden();
    }

    public function test_requester_is_not_automatically_authorized_through_this_route(): void
    {
        $branch = $this->branch('OWNER');
        $requester = $this->user($branch, 'User', 'OwnerRequester');
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        /*
         * Ownership is enough for My Requests, but not for the recipient
         * shared-request route.
         */
        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        )->assertForbidden();
    }

    public function test_request_details_include_expected_items_and_approval_history(): void
    {
        $branch = $this->branch('HISTORY');
        $requester = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'HistoryRequester',
            position: 'Accounting Staff',
        );
        $recipient = $this->user($branch, 'User', 'HistoryRecipient');
        $firstApprover = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'FirstApprover',
            position: 'Area Manager',
        );
        $secondApprover = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'SecondApprover',
            position: 'Finance Head',
        );

        $requestForm = $this->requestForm($requester, $branch);

        $this->item(
            $requestForm,
            lineNumber: 1,
            description: 'A4 bond paper',
            quantity: 2,
            unitCost: '125.00',
            lineTotal: '250.00',
            remarks: 'For accounting use',
        );
        $this->item(
            $requestForm,
            lineNumber: 2,
            description: 'Ballpoint pens',
            quantity: 10,
            unitCost: '15.00',
            lineTotal: '150.00',
            remarks: 'Black ink',
        );

        $requestAttachment = $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/quotation.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $firstStep = $this->approvalStep(
            requestForm: $requestForm,
            approver: $firstApprover,
            level: 1,
            status: ApprovalStepStatus::Approved,
            comment: 'Checked and approved.',
            actedAt: '2026-11-10 09:30:00',
        );
        $firstAttachment = $firstStep->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/approvals/note.pdf",
            'original_name' => 'approval-note.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 512,
        ]);
        $secondStep = $this->approvalStep(
            requestForm: $requestForm,
            approver: $secondApprover,
            level: 2,
            status: ApprovalStepStatus::Pending,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        )
            ->assertOk()
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.requester.id', $requester->id)
            ->assertJsonPath(
                'data.requester.firstname',
                'HistoryRequester',
            )
            ->assertJsonPath(
                'data.requester.position',
                'Accounting Staff',
            )
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath(
                'data.items.0.description',
                'A4 bond paper',
            )
            ->assertJsonPath('data.items.0.line_number', 1)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.line_total', '250.00')
            ->assertJsonPath(
                'data.items.1.description',
                'Ballpoint pens',
            )
            ->assertJsonPath('data.items.1.line_number', 2)
            ->assertJsonPath(
                'data.attachments.0.id',
                $requestAttachment->id,
            )
            ->assertJsonPath(
                'data.attachments.0.original_name',
                'quotation.pdf',
            )
            ->assertJsonCount(2, 'data.approval_steps')
            ->assertJsonPath(
                'data.approval_steps.0.id',
                $firstStep->id,
            )
            ->assertJsonPath('data.approval_steps.0.level', 1)
            ->assertJsonPath(
                'data.approval_steps.0.step_type',
                ApprovalStepType::ApprovedBy->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.status',
                ApprovalStepStatus::Approved->value,
            )
            ->assertJsonPath(
                'data.approval_steps.0.comment',
                'Checked and approved.',
            )
            ->assertJsonPath(
                'data.approval_steps.0.acted_at',
                Carbon::parse('2026-11-10 09:30:00')->toISOString(),
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.id',
                $firstApprover->id,
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.firstname',
                'FirstApprover',
            )
            ->assertJsonPath(
                'data.approval_steps.0.approver.position',
                'Area Manager',
            )
            ->assertJsonPath(
                'data.approval_steps.0.attachments.0.id',
                $firstAttachment->id,
            )
            ->assertJsonPath(
                'data.approval_steps.0.attachments.0.original_name',
                'approval-note.pdf',
            )
            ->assertJsonPath(
                'data.approval_steps.1.id',
                $secondStep->id,
            )
            ->assertJsonPath('data.approval_steps.1.level', 2)
            ->assertJsonPath(
                'data.approval_steps.1.status',
                ApprovalStepStatus::Pending->value,
            )
            ->assertJsonPath(
                'data.approval_steps.1.approver.id',
                $secondApprover->id,
            );
    }

    public function test_no_passwords_tokens_emails_contacts_roles_signatures_or_storage_paths_are_exposed(): void
    {
        $branch = $this->branch('SAFE');
        $requester = $this->user($branch, 'User', 'SafeRequester');
        $recipient = $this->user($branch, 'User', 'SafeRecipient');
        $approver = $this->user($branch, 'approver', 'SafeApprover');

        $requester->forceFill([
            'email' => 'secret-requester@example.com',
            'contact' => '09990001111',
            'signature' => 'secret-requester-signature.png',
            'userName' => 'secret-requester-login',
            'employee_id' => 'SECRET-REQUESTER-EMP',
        ])->save();
        $approver->forceFill([
            'email' => 'secret-approver@example.com',
            'contact' => '09990002222',
            'signature' => 'secret-approver-signature.png',
            'userName' => 'secret-approver-login',
            'employee_id' => 'SECRET-APPROVER-EMP',
        ])->save();

        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/secret-storage-path.pdf",
            'original_name' => 'quotation.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $step = $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Approved,
            comment: 'Approved.',
            actedAt: '2026-11-10 09:30:00',
        );
        $step->attachments()->create([
            'disk' => 'local',
            'path' => "request-forms/{$requestForm->id}/approvals/secret-approval-storage.pdf",
            'original_name' => 'approval-note.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 512,
        ]);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $response = $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        );

        $response
            ->assertOk()
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester.contact')
            ->assertJsonMissingPath('data.requester.roles')
            ->assertJsonMissingPath('data.requester.role')
            ->assertJsonMissingPath('data.requester.signature')
            ->assertJsonMissingPath('data.requester.password')
            ->assertJsonMissingPath('data.requester.userName')
            ->assertJsonMissingPath('data.requester.employee_id')
            ->assertJsonMissingPath('data.requester.tokens')
            ->assertJsonMissingPath('data.approval_steps.0.approver.email')
            ->assertJsonMissingPath('data.approval_steps.0.approver.contact')
            ->assertJsonMissingPath('data.approval_steps.0.approver.roles')
            ->assertJsonMissingPath('data.approval_steps.0.approver.role')
            ->assertJsonMissingPath('data.approval_steps.0.approver.signature')
            ->assertJsonMissingPath('data.approval_steps.0.approver.password')
            ->assertJsonMissingPath('data.approval_steps.0.approver.userName')
            ->assertJsonMissingPath('data.approval_steps.0.approver.employee_id')
            ->assertJsonMissingPath('data.attachments.0.disk')
            ->assertJsonMissingPath('data.attachments.0.path')
            ->assertJsonMissingPath('data.approval_steps.0.attachments.0.disk')
            ->assertJsonMissingPath('data.approval_steps.0.attachments.0.path');

        $this->assertSame(
            ['id', 'firstname', 'lastname', 'position'],
            array_keys($response->json('data.requester')),
        );
        $this->assertSame(
            ['id', 'firstname', 'lastname', 'position'],
            array_keys($response->json('data.approval_steps.0.approver')),
        );

        $content = $response->getContent();

        foreach ([
            'secret-requester@example.com',
            'secret-approver@example.com',
            '09990001111',
            '09990002222',
            'secret-requester-signature.png',
            'secret-approver-signature.png',
            'secret-requester-login',
            'secret-approver-login',
            'SECRET-REQUESTER-EMP',
            'SECRET-APPROVER-EMP',
            'secret-storage-path.pdf',
            'secret-approval-storage.pdf',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString(
                $sensitiveValue,
                $content,
            );
        }
    }

    public function test_missing_and_soft_deleted_requests_return_custom_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch, 'User', 'MissingRequester');
        $recipient = $this->user($branch, 'User', 'MissingRecipient');

        Sanctum::actingAs($recipient);

        $this->getJson('/api/v1/shared-requests/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Shared request not found.');

        $requestForm = $this->requestForm($requester, $branch);
        $requestForm->sharedRecipients()->attach($recipient->id);
        $requestForm->delete();

        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Shared request not found.');
    }

    public function test_identity_and_state_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('PROHIBITED');
        $requester = $this->user($branch, 'User', 'ProhibitedRequester');
        $recipient = $this->user($branch, 'User', 'ProhibitedRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}"
            .'?user_id=1&recipient_id=1&requester_id=1'
            .'&branch_id=1&role=Admin&status=Completed',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'recipient_id',
                'requester_id',
                'branch_id',
                'role',
                'status',
            ]);
    }

    public function test_endpoint_performs_no_database_mutations(): void
    {
        $branch = $this->branch('READONLY');
        $requester = $this->user($branch, 'User', 'ReadOnlyRequester');
        $recipient = $this->user($branch, 'User', 'ReadOnlyRecipient');
        $approver = $this->user($branch, 'approver', 'ReadOnlyApprover');
        $requestForm = $this->requestForm($requester, $branch);

        $this->item(
            $requestForm,
            lineNumber: 1,
            description: 'Read-only item',
            quantity: 1,
            unitCost: '100.00',
            lineTotal: '100.00',
            remarks: null,
        );
        $step = $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            level: 1,
            status: ApprovalStepStatus::Pending,
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        $beforeRequest = DB::table('request_forms')
            ->where('id', $requestForm->id)
            ->first();
        $beforeItemCount = DB::table('request_items')
            ->where('request_form_id', $requestForm->id)
            ->count();
        $beforeStep = DB::table('request_approval_steps')
            ->where('id', $step->id)
            ->first();
        $beforeShares = DB::table('request_shares')
            ->where('request_form_id', $requestForm->id)
            ->orderBy('id')
            ->get();

        $writeQueries = [];

        DB::listen(function ($query) use (&$writeQueries): void {
            if (preg_match(
                '/^\s*(insert|update|delete|replace|truncate|alter|drop|create)\b/i',
                $query->sql,
            ) === 1) {
                $writeQueries[] = $query->sql;
            }
        });

        Sanctum::actingAs($recipient);

        $this->getJson(
            "/api/v1/shared-requests/{$requestForm->id}",
        )->assertOk();

        $this->assertSame([], $writeQueries);

        $this->assertEquals(
            $beforeRequest,
            DB::table('request_forms')
                ->where('id', $requestForm->id)
                ->first(),
        );
        $this->assertSame(
            $beforeItemCount,
            DB::table('request_items')
                ->where('request_form_id', $requestForm->id)
                ->count(),
        );
        $this->assertEquals(
            $beforeStep,
            DB::table('request_approval_steps')
                ->where('id', $step->id)
                ->first(),
        );
        $this->assertEquals(
            $beforeShares,
            DB::table('request_shares')
                ->where('request_form_id', $requestForm->id)
                ->orderBy('id')
                ->get(),
        );
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
        Branch $branch,
        ?string $role,
        string $firstName,
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0915000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "show-shared-request-{$number}",
            'employee_id' => "SHOW-SHARED-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        ?string $requestCode = null,
    ): RequestForm {
        $number = ++$this->sequence;

        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing shared request details.',
            ],
        ]);

        $requestForm->request_code = $requestCode
            ?? "SHOW-SHARED-SRL-{$number}";
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function item(
        RequestForm $requestForm,
        int $lineNumber,
        string $description,
        int $quantity,
        string $unitCost,
        string $lineTotal,
        ?string $remarks,
    ): RequestItem {
        $item = new RequestItem([
            'quantity' => $quantity,
            'description' => $description,
            'unit_cost' => $unitCost,
            'remarks' => $remarks,
        ]);
        $item->line_number = $lineNumber;
        $item->line_total = $lineTotal;
        $requestForm->items()->save($item);

        return $item;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepStatus $status,
        ?string $comment = null,
        ?string $actedAt = null,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = $status;
        $step->comment = $comment;
        $step->acted_at = $actedAt !== null
            ? Carbon::parse($actedAt)
            : null;

        $requestForm->approvalSteps()->save($step);

        return $step;
    }
}
