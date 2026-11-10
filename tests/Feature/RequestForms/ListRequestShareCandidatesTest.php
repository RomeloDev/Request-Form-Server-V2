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

class ListRequestShareCandidatesTest extends TestCase
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
        $requester = $this->user($branch, 'User', 'GuestRequester');
        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
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

        $requestForm = $this->requestForm(
            requester: $owner,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($otherRequester);

        $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
        )->assertForbidden();
    }

    public function test_non_completed_request_is_rejected(): void
    {
        $branch = $this->branch('PENDING');
        $requester = $this->user($branch, 'User', 'PendingOwner');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Pending,
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Only completed requests can be shared.',
            );
    }

    public function test_prohibited_and_invalid_query_parameters_are_rejected(): void
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
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
            .'?user_id=1&requester_id=1&branch_id=1'
            .'&role=Admin&status=Completed&page=0&per_page=15'
            ."&search={$search}"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
                'role',
                'status',
                'page',
                'per_page',
                'search',
            ]);
    }

    public function test_owner_can_list_candidates_for_a_completed_request(): void
    {
        $branch = $this->branch('LIST');
        $requester = $this->user($branch, 'User', 'ListOwner');
        $firstCandidate = $this->user($branch, 'User', 'Alpha');
        $secondCandidate = $this->user($branch, 'User', 'Beta');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Share candidates retrieved successfully.',
            )
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [
                $firstCandidate->id,
                $secondCandidate->id,
            ],
            collect($response->json('data'))->pluck('id')->all(),
        );

        $this->assertNotContains(
            $requester->id,
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_requester_admin_unverified_and_existing_recipients_are_excluded(): void
    {
        $branch = $this->branch('EXCLUDE');
        $requester = $this->user($branch, 'User', 'ExcludeOwner');
        $included = $this->user($branch, 'User', 'Included');
        $admin = $this->user($branch, 'Admin', 'AdminUser');
        $roleless = $this->user(
            branch: $branch,
            role: null,
            firstName: 'RolelessUser',
        );
        $unverified = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'UnverifiedUser',
            verified: false,
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

        $response = $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $included->id);

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $this->assertNotContains($requester->id, $returnedIds);
        $this->assertNotContains($admin->id, $returnedIds);
        $this->assertNotContains($roleless->id, $returnedIds);
        $this->assertNotContains($unverified->id, $returnedIds);
        $this->assertNotContains(
            $existingRecipient->id,
            $returnedIds,
        );
    }

    public function test_search_matches_first_name_last_name_position_branch_code_and_branch_name(): void
    {
        $branch = $this->branch('SEARCH');
        $requester = $this->user($branch, 'User', 'SearchOwner');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        $codeBranch = $this->branch(
            'BRN-77',
            branchName: 'Code Test Branch',
        );
        $nameBranch = $this->branch(
            'BRN-88',
            branchName: 'Northpoint Needle',
        );

        $byFirstName = $this->user($branch, 'User', 'UniqueFirstName');
        $byLastName = $this->user($branch, 'User', 'LastSearch');
        $byLastName->forceFill([
            'lastName' => 'UniqueLastName',
        ])->save();
        $byPosition = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'PositionSearch',
            position: 'UniquePositionTitle',
        );
        $byBranchCode = $this->user(
            $codeBranch,
            'User',
            'BranchCodeSearch',
        );
        $byBranchName = $this->user(
            $nameBranch,
            'User',
            'BranchNameSearch',
        );

        Sanctum::actingAs($requester);

        foreach ([
            'UniqueFirstName' => $byFirstName,
            'UniqueLastName' => $byLastName,
            'UniquePositionTitle' => $byPosition,
            'BRN-77' => $byBranchCode,
            'Northpoint Needle' => $byBranchName,
        ] as $term => $expectedCandidate) {
            $response = $this->getJson(
                "/api/v1/request-forms/{$requestForm->id}/share-candidates"
                .'?search='.urlencode($term)
            );

            $response
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath(
                    'data.0.id',
                    $expectedCandidate->id,
                );
        }
    }

    public function test_search_and_pagination_work_together(): void
    {
        $branch = $this->branch('PAGE');
        $requester = $this->user($branch, 'User', 'PageOwner');

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        for ($index = 1; $index <= 12; $index++) {
            $this->user(
                $branch,
                'User',
                'Candidate'.$index,
            );
        }

        Sanctum::actingAs($requester);

        $baseUrl = "/api/v1/request-forms/{$requestForm->id}/share-candidates";

        $firstPage = $this->getJson(
            "{$baseUrl}?page=1&per_page=10"
        );

        $firstPage
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(10, 'data');

        $secondPage = $this->getJson(
            "{$baseUrl}?page=2&per_page=10"
        );

        $secondPage
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(2, 'data');

        $this->getJson("{$baseUrl}?per_page=20")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(12, 'data');

        $this->getJson("{$baseUrl}?per_page=50")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(12, 'data');
    }

    public function test_response_does_not_expose_sensitive_fields(): void
    {
        $branch = $this->branch('SAFE');
        $requester = $this->user($branch, 'User', 'SafeOwner');
        $candidate = $this->user($branch, 'User', 'SafeCandidate');

        $candidate->forceFill([
            'signature' => 'sensitive-signature-data',
        ])->save();

        $requestForm = $this->requestForm(
            requester: $requester,
            branch: $branch,
            status: RequestStatus::Completed,
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            "/api/v1/request-forms/{$requestForm->id}/share-candidates"
        );

        $response
            ->assertOk()
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.contact')
            ->assertJsonMissingPath('data.0.employee_id')
            ->assertJsonMissingPath('data.0.signature')
            ->assertJsonMissingPath('data.0.role')
            ->assertJsonMissingPath('data.0.roles')
            ->assertJsonMissingPath('data.0.userName');

        $this->assertSame(
            [
                'id',
                'firstname',
                'lastname',
                'position',
                'branch_code',
                'branch_name',
            ],
            array_keys($response->json('data.0')),
        );
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
            'contact' => "0919000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => $position,
            'userName' => "share-candidate-{$number}",
            'employee_id' => "SHARE-CANDIDATE-EMP-{$number}",
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
                'purpose' => 'Testing share candidates.',
            ],
        ]);

        $requestForm->request_code = "SHARE-CANDIDATE-SRL-{$number}";
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = $status;
        $requestForm->grand_total = '100.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }
}
