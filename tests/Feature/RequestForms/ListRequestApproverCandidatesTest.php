<?php

namespace Tests\Feature\RequestForms;

use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\BranchHeadAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListRequestApproverCandidatesTest extends TestCase
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

    public function test_route_requires_authentication(): void
    {
        $this->getJson(
            '/api/v1/request-forms/approver-candidates'
        )->assertUnauthorized();
    }

    public function test_account_without_an_application_role_is_forbidden(): void
    {
        $branch = $this->branch('CALI');

        $user = $this->user(
            branch: $branch,
            role: null,
            firstName: 'Roleless',
        );

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/v1/request-forms/approver-candidates'
        )->assertForbidden();
    }

    public function test_endpoint_returns_only_approvers_eligible_for_requester_branch(): void
    {
        $requesterBranch = $this->branch('CALI');
        $otherBranch = $this->branch('OTHER');

        /*
         * Give the requester the approver role so this test proves their
         * own ID is excluded, not merely excluded by the role filter.
         */
        $requester = $this->user(
            branch: $requesterBranch,
            role: 'approver',
            firstName: 'Requester',
        );

        /*
         * Included: ordinary approver from the same branch.
         */
        $sameBranchApprover = $this->user(
            branch: $requesterBranch,
            role: 'approver',
            firstName: 'SameBranch',
        );

        /*
         * Included: Branch Head configured to cover the requester branch,
         * even though their account belongs to another branch.
         */
        $branchHead = $this->user(
            branch: $otherBranch,
            role: 'approver',
            firstName: 'BranchHead',
            position: 'Branch Manager',
        );

        $branchHeadAssignment = BranchHeadAssignment::create([
            'user_id' => $branchHead->id,
        ]);

        $branchHeadAssignment->branches()->attach(
            $requesterBranch->id
        );

        /*
         * Included: AVP Finance whose AVP Staff assignment routes the
         * requester's branch.
         */
        $avp = $this->user(
            branch: $otherBranch,
            role: 'approver',
            firstName: 'FinanceAVP',
            position: 'AVP - Finance',
        );

        /*
         * Excluded: delegated staff is inserted automatically later.
         * Place the staff in the same branch to prove the exclusion wins
         * even over the same-branch rule.
         */
        $delegatedStaff = $this->user(
            branch: $requesterBranch,
            role: 'approver',
            firstName: 'DelegatedStaff',
            position: 'Accounting Staff',
        );

        $avpAssignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $delegatedStaff->id,
        ]);

        $avpAssignment->branches()->attach(
            $requesterBranch->id
        );

        /*
         * Excluded: approver from an unrelated branch.
         */
        $unrelatedApprover = $this->user(
            branch: $otherBranch,
            role: 'approver',
            firstName: 'Unrelated',
        );

        /*
         * Excluded: same branch but not an approver.
         */
        $standardUser = $this->user(
            branch: $requesterBranch,
            role: 'User',
            firstName: 'StandardUser',
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            '/api/v1/request-forms/approver-candidates?per_page=20'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Eligible request approvers retrieved successfully.',
            )
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data');

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [
                $sameBranchApprover->id,
                $branchHead->id,
                $avp->id,
            ],
            $returnedIds,
        );

        $this->assertNotContains(
            $requester->id,
            $returnedIds,
        );

        $this->assertNotContains(
            $delegatedStaff->id,
            $returnedIds,
        );

        $this->assertNotContains(
            $unrelatedApprover->id,
            $returnedIds,
        );

        $this->assertNotContains(
            $standardUser->id,
            $returnedIds,
        );
    }

    public function test_head_office_approvers_are_global_except_delegated_avp_staff(): void
    {
        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO', 'Head Office');
        $otherBranch = $this->branch('OTHER');

        $requester = $this->user(
            branch: $requesterBranch,
            role: 'User',
            firstName: 'Requester',
        );

        /*
         * Included: both Head Office identity fields match and the user
         * has the approver role.
         */
        $globalApprover = $this->user(
            branch: $headOffice,
            role: 'approver',
            firstName: 'GlobalApprover',
        );

        $avp = $this->user(
            branch: $headOffice,
            role: 'approver',
            firstName: 'FinanceAVP',
            position: 'AVP - Finance',
        );

        /*
         * Excluded: an AVP Staff member is inserted automatically for
         * assigned branches and must not be manually selectable, even
         * when their own account belongs to Head Office.
         */
        $delegatedStaff = $this->user(
            branch: $headOffice,
            role: 'approver',
            firstName: 'DelegatedStaff',
            position: 'Accounting Staff',
        );

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $delegatedStaff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        /*
         * Excluded: the global rule requires both branch_code = HO and
         * branch = Head Office, not only one matching field.
         */
        $codeOnlyApprover = $this->user(
            branch: $headOffice,
            role: 'approver',
            firstName: 'CodeOnly',
        );
        $codeOnlyApprover->forceFill([
            'branch' => 'Different Company',
        ])->save();

        $companyOnlyApprover = $this->user(
            branch: $otherBranch,
            role: 'approver',
            firstName: 'CompanyOnly',
        );
        $companyOnlyApprover->forceFill([
            'branch' => 'Head Office',
        ])->save();

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            '/api/v1/request-forms/approver-candidates?per_page=20'
        )->assertOk();

        $returnedIds = collect(
            $response->json('data')
        )->pluck('id')->all();

        $this->assertContains(
            $globalApprover->id,
            $returnedIds,
        );
        $this->assertContains($avp->id, $returnedIds);
        $this->assertNotContains(
            $delegatedStaff->id,
            $returnedIds,
        );
        $this->assertNotContains(
            $codeOnlyApprover->id,
            $returnedIds,
        );
        $this->assertNotContains(
            $companyOnlyApprover->id,
            $returnedIds,
        );
    }

    public function test_user_without_an_assigned_branch_cannot_list_candidates(): void
    {
        $requester = $this->user(
            branch: null,
            role: 'User',
            firstName: 'NoBranch',
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            '/api/v1/request-forms/approver-candidates'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch']);
    }

    public function test_user_with_an_unknown_branch_code_cannot_list_candidates(): void
    {
        $branch = $this->branch('KNOWN');

        $requester = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'UnknownBranch',
        );

        $requester->forceFill([
            'branch_code' => 'MISSING',
        ])->save();

        Sanctum::actingAs($requester);

        $this->getJson(
            '/api/v1/request-forms/approver-candidates'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch']);
    }

    public function test_identity_and_invalid_query_parameters_are_rejected(): void
    {
        $branch = $this->branch('CALI');

        $requester = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'Requester',
        );

        Sanctum::actingAs($requester);

        $this->getJson(
            '/api/v1/request-forms/approver-candidates?user_id=1&requester_id=1&branch_id=1&sort=email&direction=sideways&page=0&per_page=15'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'requester_id',
                'branch_id',
                'sort',
                'direction',
                'page',
                'per_page',
            ]);
    }

    public function test_search_sorting_and_page_size_are_applied(): void
    {
        $branch = $this->branch('CALI');

        $requester = $this->user(
            branch: $branch,
            role: 'User',
            firstName: 'Requester',
        );

        $zebra = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'Alice',
        );
        $zebra->forceFill(['lastName' => 'Zebra'])->save();

        $alpha = $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'Alice',
        );
        $alpha->forceFill(['lastName' => 'Alpha'])->save();

        $this->user(
            branch: $branch,
            role: 'approver',
            firstName: 'Bob',
        );

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            '/api/v1/request-forms/approver-candidates?search=Alice&sort=lastname&direction=asc&page=1&per_page=20'
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.0.lastname', 'Alpha')
            ->assertJsonPath('data.1.id', $zebra->id)
            ->assertJsonPath('data.1.lastname', 'Zebra')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.contact');
    }

    private function branch(
        string $code,
        string $company = 'Test Company',
    ): Branch {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => $company,
            'acronym' => $code,
        ]);
    }

    private function user(
        ?Branch $branch,
        ?string $role,
        string $firstName,
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0918000{$number}",
            'branch_code' => $branch?->branch_code,
            'branch' => $branch?->branch,
            'position' => $position,
            'userName' => "candidate-user-{$number}",
            'employee_id' => "CANDIDATE-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
