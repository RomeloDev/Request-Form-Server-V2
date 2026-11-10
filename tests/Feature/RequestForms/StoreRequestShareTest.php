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

class StoreRequestShareTest extends TestCase
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
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $this->postJson(
            $this->shareUrl($requestForm),
            ['user_ids' => [$requester->id]],
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

        Sanctum::actingAs($otherRequester);

        $this->postJson(
            $this->shareUrl($requestForm),
            ['user_ids' => [$recipient->id]],
        )->assertForbidden();
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

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            ['user_ids' => [$recipient->id]],
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only completed requests can be shared.',
            );

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_invalid_user_ids_are_rejected(): void
    {
        $branch = $this->branch('INVALID');
        $requester = $this->user($branch, 'User', 'InvalidOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            [
                'user_ids' => [
                    'not-an-integer',
                    999999,
                ],
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_ids.0',
                'user_ids.1',
            ]);

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_duplicate_user_ids_are_rejected(): void
    {
        $branch = $this->branch('DUPLICATE');
        $requester = $this->user($branch, 'User', 'DuplicateOwner');
        $recipient = $this->user($branch, 'User', 'DuplicateRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            [
                'user_ids' => [
                    $recipient->id,
                    $recipient->id,
                ],
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids.1']);

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_empty_user_ids_are_rejected(): void
    {
        $branch = $this->branch('EMPTY');
        $requester = $this->user($branch, 'User', 'EmptyOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            ['user_ids' => []],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_excessive_user_ids_are_rejected(): void
    {
        $branch = $this->branch('EXCESSIVE');
        $requester = $this->user($branch, 'User', 'ExcessiveOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            ['user_ids' => range(1, 51)],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_prohibited_fields_are_rejected(): void
    {
        $branch = $this->branch('PROHIBITED');
        $requester = $this->user($branch, 'User', 'ProhibitedOwner');
        $recipient = $this->user($branch, 'User', 'ProhibitedRecipient');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            [
                'user_ids' => [$recipient->id],
                'requester_id' => $requester->id,
                'user_id' => $recipient->id,
                'recipient_id' => $recipient->id,
                'role' => 'Admin',
                'status' => RequestStatus::Completed->value,
                'request_id' => $requestForm->id,
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'requester_id',
                'user_id',
                'recipient_id',
                'role',
                'status',
                'request_id',
            ]);

        $this->assertDatabaseCount('request_shares', 0);
    }

    public function test_requester_admin_unverified_roleless_and_existing_recipients_are_rejected(): void
    {
        $branch = $this->branch('INELIGIBLE');
        $requester = $this->user($branch, 'User', 'IneligibleOwner');
        $admin = $this->user($branch, 'Admin', 'AdminRecipient');
        $unverified = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'UnverifiedRecipient',
            verified: false,
        );
        $roleless = $this->user(
            branch: $branch,
            role: null,
            firstName: 'RolelessRecipient',
        );
        $existingRecipient = $this->user(
            $branch,
            'User',
            'ExistingRecipient',
        );

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach(
            $existingRecipient->id
        );

        Sanctum::actingAs($requester);

        $ineligibleIds = [
            'requester' => $requester->id,
            'admin' => $admin->id,
            'unverified' => $unverified->id,
            'roleless' => $roleless->id,
            'existing recipient' => $existingRecipient->id,
        ];

        foreach ($ineligibleIds as $label => $userId) {
            $this->postJson(
                $this->shareUrl($requestForm),
                ['user_ids' => [$userId]],
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['user_ids']);

            $this->assertDatabaseCount(
                'request_shares',
                1,
            );
        }
    }

    public function test_one_invalid_recipient_prevents_all_shares(): void
    {
        $branch = $this->branch('ATOMIC');
        $requester = $this->user($branch, 'User', 'AtomicOwner');
        $validRecipient = $this->user(
            $branch,
            'User',
            'ValidRecipient',
        );
        $admin = $this->user($branch, 'Admin', 'InvalidRecipient');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->postJson(
            $this->shareUrl($requestForm),
            [
                'user_ids' => [
                    $validRecipient->id,
                    $admin->id,
                ],
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);

        $this->assertDatabaseCount('request_shares', 0);
        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $validRecipient->id,
        ]);
    }

    public function test_successful_sharing_creates_every_share_and_returns_the_count(): void
    {
        $branch = $this->branch('SUCCESS');
        $requester = $this->user($branch, 'User', 'SuccessOwner');

        $recipients = [
            $this->user($branch, 'User', 'FirstRecipient'),
            $this->user($branch, 'approver', 'SecondRecipient'),
            $this->user($branch, 'User', 'ThirdRecipient'),
        ];

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $response = $this->postJson(
            $this->shareUrl($requestForm),
            [
                'user_ids' => array_map(
                    fn (User $user): int => $user->id,
                    $recipients,
                ),
            ],
        );

        $response
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Request shared successfully.',
                'data' => [
                    'request_id' => $requestForm->id,
                    'shared_count' => 3,
                ],
            ]);

        foreach ($recipients as $recipient) {
            $this->assertDatabaseHas('request_shares', [
                'request_form_id' => $requestForm->id,
                'recipient_id' => $recipient->id,
            ]);
        }

        $this->assertDatabaseCount('request_shares', 3);
    }

    public function test_re_sharing_an_existing_recipient_is_rejected_without_duplication(): void
    {
        $branch = $this->branch('RESHARE');
        $requester = $this->user($branch, 'User', 'ReshareOwner');
        $recipient = $this->user($branch, 'User', 'ReshareRecipient');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $payload = ['user_ids' => [$recipient->id]];

        $this->postJson($this->shareUrl($requestForm), $payload)
            ->assertCreated()
            ->assertJsonPath('data.shared_count', 1);

        $this->assertDatabaseCount('request_shares', 1);

        $this->postJson($this->shareUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);

        $this->assertDatabaseCount('request_shares', 1);
        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
    }

    public function test_missing_and_soft_deleted_requests_return_the_project_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch, 'User', 'MissingOwner');
        $recipient = $this->user($branch, 'User', 'MissingRecipient');

        Sanctum::actingAs($requester);

        $this->postJson('/api/v1/request-forms/999999/shares', [
            'user_ids' => [$recipient->id],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->delete();

        $this->postJson(
            $this->shareUrl($requestForm),
            ['user_ids' => [$recipient->id]],
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');

        $this->assertDatabaseCount('request_shares', 0);
    }

    private function shareUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/shares";
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
        bool $verified = true,
    ): User {
        $number = ++$this->sequence;

        $factory = User::factory();

        if (! $verified) {
            $factory = $factory->unverified();
        }

        $user = $factory->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0918000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "store-share-{$number}",
            'employee_id' => "STORE-SHARE-EMP-{$number}",
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
                'purpose' => 'Testing request sharing.',
            ],
        ]);

        $requestForm->request_code = "STORE-SHARE-SRL-{$number}";
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
