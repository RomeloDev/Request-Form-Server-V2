<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DestroyRequestShareTest extends TestCase
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
        $branch = $this->branch('GUEST');
        $requester = $this->user($branch, 'User', 'GuestOwner');
        $recipient = $this->user($branch, 'User', 'GuestRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )->assertUnauthorized();
    }

    public function test_another_requester_receives_forbidden(): void
    {
        $branch = $this->branch('OWNER');
        $owner = $this->user($branch, 'User', 'Owner');
        $otherRequester = $this->user(
            $branch,
            'User',
            'OtherRequester',
        );
        $recipient = $this->user($branch, 'User', 'Recipient');
        $requestForm = $this->requestForm(
            requester: $owner,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($otherRequester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )->assertForbidden();

        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_non_completed_request_receives_conflict(): void
    {
        $branch = $this->branch('PENDING');
        $requester = $this->user($branch, 'User', 'PendingOwner');
        $recipient = $this->user($branch, 'User', 'PendingRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Pending,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only completed requests can be shared.',
            );

        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_prohibited_body_fields_are_rejected(): void
    {
        $branch = $this->branch('PROHIBITED');
        $requester = $this->user($branch, 'User', 'ProhibitedOwner');
        $recipient = $this->user($branch, 'User', 'ProhibitedRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient),
            [
                'request_id' => $requestForm->id,
                'requester_id' => $requester->id,
                'user_id' => $recipient->id,
                'recipient_id' => $recipient->id,
                'role' => 'Admin',
                'status' => RequestStatus::Completed->value,
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'request_id',
                'requester_id',
                'user_id',
                'recipient_id',
                'role',
                'status',
            ]);

        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_owner_can_revoke_an_existing_recipient(): void
    {
        $branch = $this->branch('SUCCESS');
        $requester = $this->user($branch, 'User', 'SuccessOwner');
        $recipient = $this->user($branch, 'User', 'SuccessRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )
            ->assertOk()
            ->assertExactJson([
                'message' => 'Shared recipient removed successfully.',
                'data' => [
                    'request_id' => $requestForm->id,
                    'recipient_id' => $recipient->id,
                ],
            ]);
    }

    public function test_matching_pivot_row_is_removed(): void
    {
        $branch = $this->branch('PIVOT');
        $requester = $this->user($branch, 'User', 'PivotOwner');
        $recipient = $this->user($branch, 'User', 'PivotRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )->assertOk();

        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_recipient_user_record_remains_in_the_database(): void
    {
        $branch = $this->branch('USER-KEPT');
        $requester = $this->user($branch, 'User', 'UserKeptOwner');
        $recipient = $this->user($branch, 'User', 'UserKeptRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $recipient->id,
        ]);
    }

    public function test_other_recipients_on_the_same_request_remain_shared(): void
    {
        $branch = $this->branch('OTHER-RECIPIENT');
        $requester = $this->user($branch, 'User', 'OtherRecipientOwner');
        $revokedRecipient = $this->user(
            $branch,
            'User',
            'RevokedRecipient',
        );
        $remainingRecipient = $this->user(
            $branch,
            'User',
            'RemainingRecipient',
        );
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach([
            $revokedRecipient->id,
            $remainingRecipient->id,
        ]);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $revokedRecipient)
        )->assertOk();

        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $revokedRecipient->id,
        ]);
        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $remainingRecipient->id,
        ]);
    }

    public function test_shares_for_another_request_remain_unchanged(): void
    {
        $branch = $this->branch('OTHER-REQUEST');
        $requester = $this->user($branch, 'User', 'OtherRequestOwner');
        $recipient = $this->user(
            $branch,
            'User',
            'SharedAcrossRequests',
        );
        $selectedRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );
        $otherRequest = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $selectedRequest->sharedRecipients()->attach($recipient->id);
        $otherRequest->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($selectedRequest, $recipient)
        )->assertOk();

        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $selectedRequest->id,
            'recipient_id' => $recipient->id,
        ]);
        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $otherRequest->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_recipient_not_shared_with_the_request_receives_not_found(): void
    {
        $branch = $this->branch('NOT-SHARED');
        $requester = $this->user($branch, 'User', 'NotSharedOwner');
        $recipient = $this->user($branch, 'User', 'NotSharedUser');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Shared recipient not found.',
            );

        $this->assertDatabaseHas('users', [
            'id' => $recipient->id,
        ]);
    }

    public function test_revoking_the_same_recipient_twice_returns_not_found_the_second_time(): void
    {
        $branch = $this->branch('TWICE');
        $requester = $this->user($branch, 'User', 'TwiceOwner');
        $recipient = $this->user($branch, 'User', 'TwiceRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )->assertOk();

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )->assertNotFound();

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_missing_and_soft_deleted_requests_return_the_project_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch, 'User', 'MissingOwner');
        $recipient = $this->user($branch, 'User', 'MissingRecipient');

        Sanctum::actingAs($requester);

        $this->deleteJson(
            "/api/v1/request-forms/999999/shares/{$recipient->id}"
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->delete();

        $this->deleteJson(
            $this->shareUrl($requestForm, $recipient)
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    public function test_non_numeric_recipient_route_values_return_not_found(): void
    {
        $branch = $this->branch('NON-NUMERIC');
        $requester = $this->user($branch, 'User', 'NonNumericOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->deleteJson(
            "/api/v1/request-forms/{$requestForm->id}/shares/not-a-number"
        )->assertNotFound();
    }

    private function shareUrl(
        RequestForm $requestForm,
        User $recipient,
    ): string {
        return "/api/v1/request-forms/{$requestForm->id}/shares/{$recipient->id}";
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
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0916000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "destroy-share-{$number}",
            'employee_id' => "DESTROY-SHARE-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function requestForm(
        User $requester,
        Branch $branch,
        RequestStatus $status,
    ): RequestForm {
        $number = ++$this->sequence;

        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'Testing share revocation.',
            ],
        ]);

        $requestForm->request_code = "DESTROY-SHARE-SRL-{$number}";
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
