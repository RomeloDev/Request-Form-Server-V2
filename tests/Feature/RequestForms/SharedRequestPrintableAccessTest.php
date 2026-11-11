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

class SharedRequestPrintableAccessTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_shared_recipient_can_retrieve_the_printable_request(): void
    {
        $branch = $this->branch('RECIPIENT');
        $requester = $this->user(
            $branch,
            firstName: 'PrintableRequester',
        );
        $recipient = $this->user($branch, firstName: 'PrintableRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Printable request retrieved successfully.',
            )
            ->assertJsonPath('data.id', $requestForm->id)
            ->assertJsonPath(
                'data.request_code',
                $requestForm->request_code,
            )
            ->assertJsonPath('data.requester.id', $requester->id)
            ->assertJsonPath(
                'data.purpose',
                'Office use',
            )
            ->assertJsonMissingPath('data.form_data')
            ->assertJsonMissingPath('data.attachments')
            ->assertJsonMissingPath('data.cancellation_reason')
            ->assertJsonMissingPath('data.requester.signature');
    }

    public function test_shared_recipient_can_view_the_requester_signature(): void
    {
        $branch = $this->branch('REQ-SIG');
        $requester = $this->user(
            $branch,
            firstName: 'SignatureRequester',
            signature: 'signatures/shared-requester.png',
        );
        $recipient = $this->user($branch, firstName: 'SignatureRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $requester->signature,
            'shared recipient can see requester signature',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

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
            'shared recipient can see requester signature',
            $response->streamedContent(),
        );
        $this->assertStringNotContainsString(
            $requester->signature,
            $response->headers->get('Content-Disposition') ?? '',
        );
    }

    public function test_shared_recipient_can_view_an_approved_approver_signature(): void
    {
        $branch = $this->branch('APPROVED-SIG');
        $requester = $this->user($branch, firstName: 'ApprovedRequester');
        $approver = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'ApprovedApprover',
            signature: 'signatures/approved-approver.png',
        );
        $recipient = $this->user(
            $branch,
            firstName: 'ApprovedRecipient',
        );
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            status: ApprovalStepStatus::Approved,
        );
        Storage::disk('public')->put(
            $approver->signature,
            'approved approver signature contents',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $response = $this->get(
            $this->signatureUrl($requestForm, $approver),
        );

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(
            'approved approver signature contents',
            $response->streamedContent(),
        );
    }

    public function test_pending_approver_signature_remains_unavailable(): void
    {
        $branch = $this->branch('PENDING-SIG');
        $requester = $this->user($branch, firstName: 'PendingRequester');
        $approver = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'PendingApprover',
            signature: 'signatures/pending-approver.png',
        );
        $recipient = $this->user($branch, firstName: 'PendingRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            status: ApprovalStepStatus::Pending,
        );
        Storage::disk('public')->put(
            $approver->signature,
            'pending signature must remain private',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            $this->signatureUrl($requestForm, $approver),
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_unrelated_user_signature_remains_unavailable(): void
    {
        $branch = $this->branch('UNRELATED-SIG');
        $requester = $this->user($branch, firstName: 'UnrelatedRequester');
        $recipient = $this->user($branch, firstName: 'UnrelatedRecipient');
        $outsideUser = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'OutsideUser',
            signature: 'signatures/outside-user.png',
        );
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $outsideUser->signature,
            'outside signature must remain private',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            $this->signatureUrl($requestForm, $outsideUser),
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Signature not available.');
    }

    public function test_unshared_user_receives_forbidden(): void
    {
        $branch = $this->branch('UNSHARED');
        $requester = $this->user($branch, firstName: 'UnsharedRequester');
        $unsharedUser = $this->user($branch, firstName: 'UnsharedUser');
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $requester->signature,
            'requester signature',
        );

        Sanctum::actingAs($unsharedUser);

        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )->assertForbidden();
    }

    public function test_roleless_shared_recipient_receives_forbidden(): void
    {
        $branch = $this->branch('NO-ROLE');
        $requester = $this->user($branch, firstName: 'RolelessRequester');
        $rolelessRecipient = $this->user(
            branch: $branch,
            role: null,
            firstName: 'RolelessRecipient',
        );
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $requester->signature,
            'requester signature',
        );
        $requestForm->sharedRecipients()->attach($rolelessRecipient->id);

        Sanctum::actingAs($rolelessRecipient);

        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )->assertForbidden();
    }

    public function test_revoked_recipient_loses_printable_and_signature_access(): void
    {
        $branch = $this->branch('REVOKED');
        $requester = $this->user(
            $branch,
            firstName: 'RevokedRequester',
            signature: 'signatures/revoked-requester.png',
        );
        $recipient = $this->user($branch, firstName: 'RevokedRecipient');
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $requester->signature,
            'requester signature before revocation',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk();
        $this->get($this->signatureUrl($requestForm, $requester))
            ->assertOk();

        $requestForm->sharedRecipients()->detach($recipient->id);

        /*
         * The policy re-reads the share table on each request, so access
         * must disappear immediately after revocation.
         */
        $this->getJson($this->printUrl($requestForm))
            ->assertForbidden();
        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )->assertForbidden();
    }

    public function test_a_share_for_another_request_grants_no_access(): void
    {
        $branch = $this->branch('OTHER-SHARE');
        $requester = $this->user($branch, firstName: 'OtherShareRequester');
        $recipient = $this->user($branch, firstName: 'OtherShareRecipient');

        $sharedRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'OTHER-SHARE-VISIBLE',
        );
        $privateRequest = $this->requestForm(
            $requester,
            $branch,
            requestCode: 'OTHER-SHARE-PRIVATE',
        );

        Storage::disk('public')->put(
            $requester->signature,
            'requester signature',
        );
        $sharedRequest->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson($this->printUrl($sharedRequest))
            ->assertOk();
        $this->getJson($this->printUrl($privateRequest))
            ->assertForbidden();
        $this->getJson(
            $this->signatureUrl($privateRequest, $requester),
        )->assertForbidden();
    }

    public function test_canceled_request_behavior_remains_unchanged(): void
    {
        $branch = $this->branch('CANCELED');
        $requester = $this->user($branch, firstName: 'CanceledRequester');
        $recipient = $this->user($branch, firstName: 'CanceledRecipient');
        $requestForm = $this->requestForm(
            $requester,
            $branch,
            RequestStatus::Canceled,
        );

        Storage::disk('public')->put(
            $requester->signature,
            'requester signature',
        );
        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson($this->printUrl($requestForm))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Canceled requests cannot be printed.',
            );
        $this->getJson(
            $this->signatureUrl($requestForm, $requester),
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Canceled requests cannot be printed.',
            );
    }

    public function test_existing_requester_and_assigned_approver_access_still_passes(): void
    {
        $branch = $this->branch('EXISTING');
        $requester = $this->user(
            $branch,
            firstName: 'ExistingRequester',
            signature: 'signatures/existing-requester.png',
        );
        $approver = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'ExistingApprover',
        );
        $requestForm = $this->requestForm($requester, $branch);

        Storage::disk('public')->put(
            $requester->signature,
            'existing requester signature',
        );
        $this->approvalStep(
            requestForm: $requestForm,
            approver: $approver,
            status: ApprovalStepStatus::Pending,
        );

        Sanctum::actingAs($requester);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.id', $requestForm->id);
        $this->get($this->signatureUrl($requestForm, $requester))
            ->assertOk();

        Sanctum::actingAs($approver);

        $this->getJson($this->printUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('data.id', $requestForm->id);
        $this->get($this->signatureUrl($requestForm, $requester))
            ->assertOk();
    }

    public function test_recipient_identity_override_parameters_are_rejected(): void
    {
        $branch = $this->branch('OVERRIDE');
        $requester = $this->user($branch, firstName: 'OverrideRequester');
        $recipient = $this->user($branch, firstName: 'OverrideRecipient');
        $otherUser = $this->user($branch, firstName: 'OverrideOther');
        $requestForm = $this->requestForm($requester, $branch);

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($recipient);

        $this->getJson(
            $this->printUrl($requestForm)
            .'?recipient_id='.$otherUser->id,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_id']);

        $this->getJson(
            $this->signatureUrl($requestForm, $requester)
            .'?recipient_id='.$otherUser->id,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_id']);
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
        int $level = 1,
        ApprovalStepType $type = ApprovalStepType::ApprovedBy,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = $type;
        $step->status = $status;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$code}-{$number}",
            'branch_name' => "{$code} Test Branch",
            'branch' => 'Strong Moto Centrum, Inc.',
            'acronym' => 'SMCT',
        ]);
    }

    private function user(
        Branch $branch,
        string $firstName,
        ?string $role = 'User',
        string $position = 'Employee',
        string $signature = 'signatures/test-user.png',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0917000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "shared-print-{$number}",
            'employee_id' => "SHARED-PRINT-EMP-{$number}",
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
        ?string $requestCode = null,
    ): RequestForm {
        $number = ++$this->sequence;

        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Office use',
            ],
        ]);

        $requestForm->request_code = $requestCode
            ?? sprintf(
                '%s-SRL-%07d',
                $branch->branch_code,
                $number,
            );
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
