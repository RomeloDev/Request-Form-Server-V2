<?php

namespace Tests\Feature\Admin;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminReportPrintAccessTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_print_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/request-forms/999999/print')
            ->assertUnauthorized();
    }

    public function test_admin_can_view_another_users_printable_request(): void
    {
        $ownerBranch = $this->branch('OWNER');
        $adminBranch = $this->branch('ADMIN');
        $owner = $this->user($ownerBranch);
        $requestForm = $this->requestForm($owner, $ownerBranch);

        Sanctum::actingAs($this->user($adminBranch, 'Admin'));

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Printable request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath('data.requester.id', $owner->id)
            ->assertJsonPath(
                'data.branch.branch_code',
                $ownerBranch->branch_code,
            )
            ->assertJsonMissingPath('data.requester.signature')
            ->assertJsonMissingPath('data.form_data');
    }

    public function test_unrelated_ordinary_user_remains_forbidden(): void
    {
        $branch = $this->branch('PRIVATE');
        $requestForm = $this->requestForm($this->user($branch), $branch);

        Sanctum::actingAs($this->user($branch));

        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
    }

    public function test_admin_can_view_requester_signature_with_private_headers(): void
    {
        $branch = $this->branch('REQUESTER-SIGNATURE');
        $requester = $this->user(
            $branch,
            signature: 'signatures/requester.png',
        );
        $requestForm = $this->requestForm($requester, $branch);
        Storage::disk('public')->put(
            $requester->signature,
            'requester signature',
        );

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $response = $this->get(
            $this->signatureUrl($requestForm, $requester),
        );
        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'requester signature',
            $response->streamedContent(),
        );
    }

    public function test_admin_can_view_an_approved_approver_signature(): void
    {
        $branch = $this->branch('APPROVED');
        $requester = $this->user($branch);
        $approver = $this->user(
            $branch,
            'approver',
            'signatures/approved.png',
        );
        $requestForm = $this->requestForm($requester, $branch);
        $this->approvalStep(
            $requestForm,
            $approver,
            ApprovalStepStatus::Approved,
        );
        Storage::disk('public')->put(
            $approver->signature,
            'approved signature',
        );

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $response = $this->get(
            $this->signatureUrl($requestForm, $approver),
        );
        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(
            'approved signature',
            $response->streamedContent(),
        );
    }

    public function test_admin_cannot_view_unrelated_or_pending_approver_signatures(): void
    {
        $branch = $this->branch('PRIVATE-SIGNATURE');
        $requester = $this->user($branch);
        $pendingApprover = $this->user(
            $branch,
            'approver',
            'signatures/pending.png',
        );
        $outsideUser = $this->user(
            $branch,
            'approver',
            'signatures/outside.png',
        );
        $requestForm = $this->requestForm($requester, $branch);
        $this->approvalStep(
            $requestForm,
            $pendingApprover,
            ApprovalStepStatus::Pending,
        );
        Storage::disk('public')->put(
            $pendingApprover->signature,
            'pending signature',
        );
        Storage::disk('public')->put(
            $outsideUser->signature,
            'outside signature',
        );

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->signatureUrl(
            $requestForm,
            $pendingApprover,
        ))
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
        $this->getJson($this->signatureUrl($requestForm, $outsideUser))
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_canceled_request_and_signature_remain_unprintable_for_admin(): void
    {
        $branch = $this->branch('CANCELED');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            RequestStatus::Canceled,
        );

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->printUrl($requestForm))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Canceled requests cannot be printed.',
            );
        $this->getJson($this->signatureUrl($requestForm, $requester))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Canceled requests cannot be printed.',
            );
    }

    public function test_missing_and_soft_deleted_requests_are_not_printable(): void
    {
        $branch = $this->branch('MISSING');
        $admin = $this->user($branch, 'Admin');
        $requester = $this->user($branch);
        $deleted = $this->requestForm($requester, $branch);
        $deleted->delete();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/request-forms/999999/print')
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
        $this->getJson($this->printUrl($deleted))
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    public function test_missing_signature_file_returns_not_found(): void
    {
        $branch = $this->branch('MISSING-FILE');
        $requester = $this->user(
            $branch,
            signature: 'signatures/missing.png',
        );
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($this->user($branch, 'Admin'));

        $this->getJson($this->signatureUrl($requestForm, $requester))
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);
        Sanctum::actingAs($this->user($branch, 'Admin'));

        $query = http_build_query([
            'user_id' => 1,
            'recipient_id' => 1,
            'requester_id' => 1,
            'branch_id' => 1,
        ]);

        $this->getJson($this->printUrl($requestForm)."?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'recipient_id',
                'requester_id',
                'branch_id',
            ]);
        $this->getJson(
            $this->signatureUrl($requestForm, $requester)."?{$query}",
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'recipient_id',
                'requester_id',
                'branch_id',
            ]);
    }

    private function printUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/print";
    }

    private function signatureUrl(
        RequestForm $requestForm,
        User $signatureOwner,
    ): string {
        return "/api/v1/request-forms/{$requestForm->id}/print/signatures/{$signatureOwner->id}";
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        ApprovalStepStatus $status,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = 1;
        $step->approver_id = $approver->id;
        $step->step_type = ApprovalStepType::ApprovedBy;
        $step->status = $status;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }

    private function branch(string $label): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "PRINT-{$number}",
            'branch_name' => "{$label} Branch",
            'branch' => 'Test Company',
            'acronym' => 'PRINT',
        ]);
    }

    private function user(
        Branch $branch,
        string $role = 'User',
        ?string $signature = null,
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Print{$number}",
            'lastName' => 'User',
            'contact' => "0922000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "admin-print-user-{$number}",
            'employee_id' => "ADMIN-PRINT-{$number}",
            'signature' => $signature ?? "signatures/user-{$number}.png",
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        RequestStatus $status = RequestStatus::Completed,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office request',
            'currency' => 'PHP',
            'form_data' => ['purpose' => 'Testing Admin print access.'],
        ]);
        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
