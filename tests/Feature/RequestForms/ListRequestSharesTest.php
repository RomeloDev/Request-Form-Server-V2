<?php

namespace Tests\Feature\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListRequestSharesTest extends TestCase
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

        $this->getJson($this->sharesUrl($requestForm))
            ->assertUnauthorized();
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
        $requestForm = $this->requestForm(
            requester: $owner,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($otherRequester);

        $this->getJson($this->sharesUrl($requestForm))
            ->assertForbidden();
    }

    public function test_non_completed_request_receives_conflict(): void
    {
        $branch = $this->branch('PENDING');
        $requester = $this->user($branch, 'User', 'PendingOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Pending,
        );

        Sanctum::actingAs($requester);

        $this->getJson($this->sharesUrl($requestForm))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only completed requests can be shared.',
            );
    }

    public function test_invalid_search_page_and_per_page_values_are_rejected(): void
    {
        $branch = $this->branch('INVALID');
        $requester = $this->user($branch, 'User', 'InvalidOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $search = str_repeat('a', 101);

        $this->getJson(
            $this->sharesUrl($requestForm)
            ."?search={$search}&page=0&per_page=15"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'search',
                'page',
                'per_page',
            ]);
    }

    public function test_prohibited_query_fields_are_rejected(): void
    {
        $branch = $this->branch('PROHIBITED');
        $requester = $this->user($branch, 'User', 'ProhibitedOwner');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            $this->sharesUrl($requestForm)
            .'?requester_id=1&user_id=1&recipient_id=1'
            .'&request_id=1&role=Admin&status=Completed'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'requester_id',
                'user_id',
                'recipient_id',
                'request_id',
                'role',
                'status',
            ]);
    }

    public function test_owner_receives_only_recipients_shared_on_the_selected_request(): void
    {
        $branch = $this->branch('SELECTED');
        $requester = $this->user($branch, 'User', 'SelectedOwner');
        $recipient = $this->user($branch, 'User', 'SelectedRecipient');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $response = $this->getJson($this->sharesUrl($requestForm));

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Shared recipients retrieved successfully.',
            )
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recipient->id);
    }

    public function test_recipients_from_another_request_are_not_returned(): void
    {
        $branch = $this->branch('OTHER-REQUEST');
        $requester = $this->user($branch, 'User', 'OtherRequestOwner');
        $selectedRecipient = $this->user(
            $branch,
            'User',
            'SelectedRecipient',
        );
        $otherRecipient = $this->user(
            $branch,
            'User',
            'OtherRecipient',
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

        $selectedRequest->sharedRecipients()->attach(
            $selectedRecipient->id
        );
        $otherRequest->sharedRecipients()->attach(
            $otherRecipient->id
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            $this->sharesUrl($selectedRequest)
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $selectedRecipient->id);

        $this->assertNotContains(
            $otherRecipient->id,
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_unshared_users_are_not_returned(): void
    {
        $branch = $this->branch('UNSHARED');
        $requester = $this->user($branch, 'User', 'UnsharedOwner');
        $sharedRecipient = $this->user(
            $branch,
            'User',
            'SharedRecipient',
        );
        $unsharedUser = $this->user(
            $branch,
            'User',
            'UnsharedUser',
        );

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach(
            $sharedRecipient->id
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson($this->sharesUrl($requestForm));

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $this->assertContains($sharedRecipient->id, $returnedIds);
        $this->assertNotContains($unsharedUser->id, $returnedIds);
    }

    public function test_search_matches_supported_user_and_branch_fields(): void
    {
        $branch = $this->branch('SEARCH');
        $requester = $this->user($branch, 'User', 'SearchOwner');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $codeBranch = $this->branch(
            'UNIQUE-CODE',
            branchName: 'Code Branch',
        );
        $nameBranch = $this->branch(
            'BR-NAME',
            branchName: 'Unique Branch Token',
        );

        $byFirstName = $this->user(
            $branch,
            'User',
            'UniqueFirstToken',
        );

        $byLastName = $this->user(
            $branch,
            'User',
            'LastSearchPerson',
        );
        $byLastName->forceFill([
            'lastName' => 'UniqueLastToken',
        ])->save();

        $byPosition = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'PositionSearchPerson',
            position: 'UniquePositionToken',
        );

        $byBranchCode = $this->user(
            $codeBranch,
            'User',
            'CodeSearchPerson',
        );

        $byBranchName = $this->user(
            $nameBranch,
            'User',
            'NameSearchPerson',
        );

        $requestForm->sharedRecipients()->attach([
            $byFirstName->id,
            $byLastName->id,
            $byPosition->id,
            $byBranchCode->id,
            $byBranchName->id,
        ]);

        Sanctum::actingAs($requester);

        foreach ([
            'UniqueFirstToken' => $byFirstName,
            'UniqueLastToken' => $byLastName,
            'UniquePositionToken' => $byPosition,
            'UNIQUE-CODE' => $byBranchCode,
            'Unique Branch Token' => $byBranchName,
        ] as $term => $expectedRecipient) {
            $response = $this->getJson(
                $this->sharesUrl($requestForm)
                .'?search='.urlencode($term)
            );

            $response
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath(
                    'data.0.id',
                    $expectedRecipient->id,
                );
        }
    }

    public function test_pagination_returns_the_expected_data_links_and_meta(): void
    {
        $branch = $this->branch('PAGE');
        $requester = $this->user($branch, 'User', 'PageOwner');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $recipients = [];

        for ($index = 1; $index <= 12; $index++) {
            $recipients[] = $this->user(
                $branch,
                'User',
                sprintf('Candidate%02d', $index),
            );
        }

        $requestForm->sharedRecipients()->attach(
            array_map(
                fn (User $user): int => $user->id,
                $recipients,
            )
        );

        Sanctum::actingAs($requester);

        $firstPage = $this->getJson(
            $this->sharesUrl($requestForm).'?page=1&per_page=10'
        );

        $firstPage
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'firstname',
                        'lastname',
                        'position',
                        'branch_code',
                        'branch_name',
                        'shared_at',
                    ],
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'links',
                    'path',
                    'per_page',
                    'to',
                    'total',
                ],
                'message',
            ])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.firstname', 'Candidate01')
            ->assertJsonPath('data.9.firstname', 'Candidate10');

        $secondPage = $this->getJson(
            $this->sharesUrl($requestForm).'?page=2&per_page=10'
        );

        $secondPage
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.firstname', 'Candidate11')
            ->assertJsonPath('data.1.firstname', 'Candidate12');
    }

    public function test_each_recipient_includes_the_correct_shared_at_value(): void
    {
        $branch = $this->branch('SHARED-AT');
        $requester = $this->user($branch, 'User', 'SharedAtOwner');
        $recipient = $this->user($branch, 'User', 'SharedAtRecipient');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        DB::table('request_shares')
            ->where('request_form_id', $requestForm->id)
            ->where('recipient_id', $recipient->id)
            ->update([
                'created_at' => '2026-11-10 12:34:56',
            ]);

        Sanctum::actingAs($requester);

        $this->getJson($this->sharesUrl($requestForm))
            ->assertOk()
            ->assertJsonPath(
                'data.0.shared_at',
                Carbon::parse('2026-11-10 12:34:56')->toISOString(),
            );
    }

    public function test_sensitive_user_fields_are_absent(): void
    {
        $branch = $this->branch('SENSITIVE');
        $requester = $this->user($branch, 'User', 'SensitiveOwner');
        $recipient = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'SensitiveRecipient',
        );

        $recipient->forceFill([
            'signature' => 'sensitive-signature-data',
        ])->save();

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        Sanctum::actingAs($requester);

        $response = $this->getJson($this->sharesUrl($requestForm));

        $response
            ->assertOk()
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.contact')
            ->assertJsonMissingPath('data.0.employee_id')
            ->assertJsonMissingPath('data.0.userName')
            ->assertJsonMissingPath('data.0.role')
            ->assertJsonMissingPath('data.0.roles')
            ->assertJsonMissingPath('data.0.permissions')
            ->assertJsonMissingPath('data.0.email_verified_at')
            ->assertJsonMissingPath('data.0.signature')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.pivot');

        $this->assertSame(
            [
                'id',
                'firstname',
                'lastname',
                'position',
                'branch_code',
                'branch_name',
                'shared_at',
            ],
            array_keys($response->json('data.0')),
        );
    }

    public function test_recipient_remains_visible_after_eligibility_changes(): void
    {
        $branch = $this->branch('ELIGIBILITY');
        $requester = $this->user($branch, 'User', 'EligibilityOwner');
        $recipient = $this->user(
            $branch,
            'User',
            'EligibilityRecipient',
        );

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->sharedRecipients()->attach($recipient->id);

        /*
         * The candidate query excludes Admin and unverified users, but this
         * list must keep showing an existing recipient whose account later
         * changes.
         */
        $recipient->syncRoles(['Admin']);
        $recipient->forceFill([
            'email_verified_at' => null,
        ])->save();

        Sanctum::actingAs($requester);

        $this->getJson($this->sharesUrl($requestForm))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $recipient->id);
    }

    public function test_missing_and_soft_deleted_requests_return_the_project_not_found_response(): void
    {
        $branch = $this->branch('MISSING');
        $requester = $this->user($branch, 'User', 'MissingOwner');

        Sanctum::actingAs($requester);

        $this->getJson('/api/v1/request-forms/999999/shares')
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $requestForm->delete();

        $this->getJson($this->sharesUrl($requestForm))
            ->assertNotFound()
            ->assertJsonPath('message', 'Request not found.');
    }

    private function sharesUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/shares";
    }

    private function branch(
        string $code,
        string $branchName = 'Test Branch',
    ): Branch {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => $branchName,
            'branch' => 'Test Company',
            'acronym' => $code,
        ]);
    }

    private function user(
        Branch $branch,
        ?string $role,
        string $firstName,
        string $position = 'Employee',
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
            'contact' => "0917000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "list-shares-{$number}",
            'employee_id' => "LIST-SHARES-EMP-{$number}",
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
                'purpose' => 'Testing shared recipients listing.',
            ],
        ]);

        $requestForm->request_code = "LIST-SHARES-SRL-{$number}";
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
