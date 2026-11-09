<?php

namespace Tests\Feature\RequestForms;

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

class ViewPrintableRequestSignatureTest extends TestCase
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

    public function test_route_requires_authentication(): void
    {
        $this->getJson(
            '/api/v1/request-forms/999999/print/signatures/999999',
        )->assertUnauthorized();
    }

    public function test_requester_can_view_their_signature_inline(): void
    {
        $branch = $this->branch('REQUESTER');
        $requester = $this->user($branch, signature: 'signatures/requester.png');
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $requester->signature,
            'requester signature contents',
        );

        Sanctum::actingAs($requester);

        $response = $this->get(
            $this->signatureUrl($requestForm, $requester),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get(
            'Cache-Control',
        );

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            'requester signature contents',
            $response->streamedContent(),
        );
    }

    public function test_requester_can_view_an_approved_approver_signature(): void
    {
        $branch = $this->branch('APPROVED');
        $requester = $this->user($branch);
        $approver = $this->user(
            $branch,
            'approver',
            'Area Manager',
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
            'approved signature contents',
        );

        Sanctum::actingAs($requester);

        $response = $this->get(
            $this->signatureUrl($requestForm, $approver),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertSame(
            'approved signature contents',
            $response->streamedContent(),
        );
    }

    public function test_assigned_approver_can_view_requester_signature(): void
    {
        $branch = $this->branch('ASSIGNED');
        $requester = $this->user(
            $branch,
            signature: 'signatures/assigned-requester.png',
        );
        $approver = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep(
            $requestForm,
            $approver,
            ApprovalStepStatus::Pending,
        );
        Storage::disk('public')->put(
            $requester->signature,
            'requester visible to assigned approver',
        );

        Sanctum::actingAs($approver);

        $this->get($this->signatureUrl($requestForm, $requester))
            ->assertOk();
    }

    public function test_unassigned_user_cannot_view_a_request_signature(): void
    {
        $branch = $this->branch('PRIVATE');
        $requester = $this->user($branch);
        $unassignedApprover = $this->user($branch, 'approver');
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($unassignedApprover);

        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )->assertForbidden();
    }

    public function test_owner_without_an_application_role_is_forbidden(): void
    {
        $branch = $this->branch('NO-ROLE');
        $requester = $this->user($branch, role: null);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )->assertForbidden();
    }

    public function test_signature_of_a_user_outside_the_request_is_not_exposed(): void
    {
        $branch = $this->branch('OUTSIDE');
        $requester = $this->user($branch);
        $outsideUser = $this->user(
            $branch,
            'approver',
            signature: 'signatures/outside.png',
        );
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $outsideUser->signature,
            'must remain private',
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->signatureUrl($requestForm, $outsideUser),
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_pending_approver_signature_is_not_available(): void
    {
        $branch = $this->branch('PENDING');
        $requester = $this->user($branch);
        $approver = $this->user(
            $branch,
            'approver',
            signature: 'signatures/pending.png',
        );
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep(
            $requestForm,
            $approver,
            ApprovalStepStatus::Pending,
        );
        Storage::disk('public')->put(
            $approver->signature,
            'pending signature must not be printed',
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->signatureUrl($requestForm, $approver),
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_canceled_request_signature_is_not_printable(): void
    {
        $branch = $this->branch('CANCELED');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            RequestStatus::Canceled,
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Canceled requests cannot be printed.',
            );
    }

    public function test_missing_signature_file_returns_not_found(): void
    {
        $branch = $this->branch('MISSING-FILE');
        $requester = $this->user(
            $branch,
            signature: 'signatures/missing.png',
        );
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_missing_request_returns_custom_not_found_response(): void
    {
        $branch = $this->branch('MISSING-REQUEST');
        $requester = $this->user($branch);

        Sanctum::actingAs($requester);

        $this->getJson(
            "/api/v1/request-forms/999999/print/signatures/{$requester->id}",
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request or signature not found.');
    }

    public function test_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('VALIDATE');
        $requester = $this->user($branch);
        $requestForm = $this->requestForm($requester, $branch);

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->signatureUrl($requestForm, $requester)
            .'?user_id=1&requester_id=1&branch_id=1',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
            ]);
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

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "SIG-{$number}",
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Strong Moto Centrum, Inc.',
            'acronym' => 'SMCT',
        ]);
    }

    private function user(
        Branch $branch,
        ?string $role = 'User',
        string $position = 'Employee',
        string $signature = 'signatures/test-user.png',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Signature{$number}",
            'lastName' => 'User',
            'contact' => "0916000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "signature-user-{$number}",
            'employee_id' => "SIGNATURE-EMP-{$number}",
            'signature' => $signature,
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        RequestStatus $status = RequestStatus::Pending,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Office use',
            ],
        ]);

        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '0.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
