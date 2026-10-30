<?php

namespace Tests\Feature\Admin;

use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AvpStaffAssignmentTest extends TestCase
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

    public function test_routes_require_authentication(): void
    {
        $avp = $this->user('approver', 'AVP - Finance');
        $staff = $this->user('approver', 'Accounting Staff');
        $branch = $this->branch();
        $assignment = $this->assignment($avp, $staff, [$branch]);

        $this->getJson('/api/v1/avp-staffs')->assertUnauthorized();
        $this->getJson('/api/v1/avp-staffs/avp-candidates')->assertUnauthorized();
        $this->getJson('/api/v1/avp-staffs/available-branches')->assertUnauthorized();
        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
            'branch_ids' => [$branch->id],
        ])->assertUnauthorized();
        $this->putJson("/api/v1/avp-staffs/{$assignment->id}", [
            'branch_ids' => [$branch->id],
        ])->assertUnauthorized();
        $this->deleteJson("/api/v1/avp-staffs/{$assignment->id}")
            ->assertUnauthorized();
    }

    public function test_non_admin_users_cannot_access_the_feature(): void
    {
        $avp = $this->user('approver', 'AVP - Finance');
        $staff = $this->user('approver', 'Accounting Staff');
        $branch = $this->branch();
        $assignment = $this->assignment($avp, $staff, [$branch]);

        Sanctum::actingAs($this->user('User', 'Employee'));

        $this->getJson('/api/v1/avp-staffs')->assertForbidden();
        $this->getJson('/api/v1/avp-staffs/avp-candidates')->assertForbidden();
        $this->getJson('/api/v1/avp-staffs/available-branches')->assertForbidden();
        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
            'branch_ids' => [$branch->id],
        ])->assertForbidden();
        $this->putJson("/api/v1/avp-staffs/{$assignment->id}", [
            'branch_ids' => [$branch->id],
        ])->assertForbidden();
        $this->deleteJson("/api/v1/avp-staffs/{$assignment->id}")
            ->assertForbidden();
    }

    public function test_requests_reject_invalid_and_privileged_input(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $avp = $this->user('approver', 'AVP - Finance');
        $staff = $this->user('approver', 'Accounting Staff');
        $branch = $this->branch();
        $assignment = $this->assignment($avp, $staff, [$branch]);

        $this->postJson('/api/v1/avp-staffs', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'avp_user_id',
                'staff_user_id',
                'branch_ids',
            ]);

        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
            'branch_ids' => [$branch->id, $branch->id],
            'is_admin' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'branch_ids.0',
            'branch_ids.1',
            'is_admin',
        ]);

        $this->putJson("/api/v1/avp-staffs/{$assignment->id}", [
            'branch_ids' => [$branch->id],
            'staff_user_id' => $avp->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['staff_user_id']);

        $this->getJson('/api/v1/avp-staffs?sort=firstname&per_page=15')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_unknown_assignments_return_the_custom_not_found_response(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $branch = $this->branch();

        $this->putJson('/api/v1/avp-staffs/999999', [
            'branch_ids' => [$branch->id],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'AVP Staff assignment not found.');

        $this->deleteJson('/api/v1/avp-staffs/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'AVP Staff assignment not found.');
    }

    public function test_admin_can_create_an_assignment(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $avp = $this->user('approver', 'AVP - Finance');
        $staff = $this->user('approver', 'Accounting Staff');
        $firstBranch = $this->branch('FIRST');
        $secondBranch = $this->branch('SECOND');

        $response = $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
            'branch_ids' => [$secondBranch->id, $firstBranch->id],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'AVP Staff assignment created successfully.')
            ->assertJsonPath('data.avp.id', $avp->id)
            ->assertJsonPath('data.staff.id', $staff->id)
            ->assertJsonCount(2, 'data.branches')
            ->assertJsonMissingPath('data.staff.password');

        $assignmentId = $response->json('data.id');

        $this->assertDatabaseHas('avp_staff_assignments', [
            'id' => $assignmentId,
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $assignmentId,
            'branch_id' => $firstBranch->id,
        ]);
        $this->assertDatabaseHas('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $assignmentId,
            'branch_id' => $secondBranch->id,
        ]);
    }

    public function test_store_rejects_ineligible_or_already_assigned_records(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $validAvp = $this->user('approver', 'AVP - Finance');
        $invalidAvp = $this->user('approver', 'Accounting Staff');
        $assignedStaff = $this->user('approver', 'Accounting Staff');
        $freeStaff = $this->user('approver', 'Accounting Staff');
        $nonApprover = $this->user('User', 'Accounting Staff');
        $assignedBranch = $this->branch('ASSIGNED');
        $freeBranch = $this->branch('FREE');

        $this->assignment($validAvp, $assignedStaff, [$assignedBranch]);

        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $invalidAvp->id,
            'staff_user_id' => $freeStaff->id,
            'branch_ids' => [$freeBranch->id],
        ])->assertConflict();

        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $validAvp->id,
            'staff_user_id' => $nonApprover->id,
            'branch_ids' => [$freeBranch->id],
        ])->assertConflict();

        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $validAvp->id,
            'staff_user_id' => $assignedStaff->id,
            'branch_ids' => [$freeBranch->id],
        ])->assertConflict();

        $this->postJson('/api/v1/avp-staffs', [
            'avp_user_id' => $validAvp->id,
            'staff_user_id' => $freeStaff->id,
            'branch_ids' => [$assignedBranch->id],
        ])->assertConflict();
    }

    public function test_candidate_and_available_branch_endpoints_exclude_assigned_records(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $avp = $this->user('approver', 'AVP - Finance', 'Eligible');
        $assignedStaff = $this->user('approver', 'Accounting Staff', 'Assigned');
        $freeStaff = $this->user('approver', 'Accounting Staff', 'Free');
        $assignedBranch = $this->branch('ASSIGNED');
        $availableBranch = $this->branch('AVAILABLE');
        $this->assignment($avp, $assignedStaff, [$assignedBranch]);

        $this->getJson('/api/v1/avp-staffs/avp-candidates')
            ->assertOk()
            ->assertJsonPath('data.0.id', $avp->id)
            ->assertJsonMissingPath('data.0.password');

        $staffResponse = $this->getJson("/api/v1/avp-staffs/staff-candidates/{$avp->id}")
            ->assertOk();
        $staffIds = collect($staffResponse->json('data'))->pluck('id');
        $this->assertTrue($staffIds->contains($freeStaff->id));
        $this->assertFalse($staffIds->contains($assignedStaff->id));
        $this->assertFalse($staffIds->contains($avp->id));

        $branchResponse = $this->getJson('/api/v1/avp-staffs/available-branches')
            ->assertOk();
        $branchIds = collect($branchResponse->json('data'))->pluck('id');
        $this->assertTrue($branchIds->contains($availableBranch->id));
        $this->assertFalse($branchIds->contains($assignedBranch->id));
    }

    public function test_index_searches_relationships_and_returns_safe_paginated_resources(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $avp = $this->user('approver', 'AVP - Finance', 'Finance');
        $staff = $this->user('approver', 'Accounting Staff', 'Delegate');
        $branch = $this->branch('SEARCHABLE');
        $assignment = $this->assignment($avp, $staff, [$branch]);

        $this->getJson('/api/v1/avp-staffs?search=SEARCHABLE&sort=id&direction=asc&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.avp.firstname', 'Finance')
            ->assertJsonPath('data.0.staff.firstname', 'Delegate')
            ->assertJsonPath('data.0.branches.0.branch_code', 'SEARCHABLE')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonMissingPath('data.0.staff.password')
            ->assertJsonMissingPath('data.0.staff.signature');
    }

    public function test_admin_can_replace_assignment_branches_without_changing_users(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $avp = $this->user('approver', 'AVP - Finance');
        $staff = $this->user('approver', 'Accounting Staff');
        $removedBranch = $this->branch('REMOVE');
        $keptBranch = $this->branch('KEEP');
        $addedBranch = $this->branch('ADD');
        $assignment = $this->assignment($avp, $staff, [$removedBranch, $keptBranch]);

        $this->putJson("/api/v1/avp-staffs/{$assignment->id}", [
            'branch_ids' => [$keptBranch->id, $addedBranch->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.avp.id', $avp->id)
            ->assertJsonPath('data.staff.id', $staff->id)
            ->assertJsonCount(2, 'data.branches');

        $this->assertDatabaseMissing('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $assignment->id,
            'branch_id' => $removedBranch->id,
        ]);
        $this->assertDatabaseHas('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $assignment->id,
            'branch_id' => $keptBranch->id,
        ]);
        $this->assertDatabaseHas('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $assignment->id,
            'branch_id' => $addedBranch->id,
        ]);
        $this->assertDatabaseHas('avp_staff_assignments', [
            'id' => $assignment->id,
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
    }

    public function test_update_rejects_a_branch_owned_by_another_assignment(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $firstAssignment = $this->assignment(
            $this->user('approver', 'AVP - Finance'),
            $this->user('approver', 'Accounting Staff'),
            [$firstBranch = $this->branch('FIRST')],
        );
        $secondAssignment = $this->assignment(
            $this->user('approver', 'AVP - Finance'),
            $this->user('approver', 'Accounting Staff'),
            [$secondBranch = $this->branch('SECOND')],
        );

        $this->putJson("/api/v1/avp-staffs/{$firstAssignment->id}", [
            'branch_ids' => [$firstBranch->id, $secondBranch->id],
        ])->assertConflict();

        $this->assertDatabaseHas('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $firstAssignment->id,
            'branch_id' => $firstBranch->id,
        ]);
        $this->assertDatabaseHas('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $secondAssignment->id,
            'branch_id' => $secondBranch->id,
        ]);
    }

    public function test_delete_removes_only_the_assignment_and_its_pivots(): void
    {
        Sanctum::actingAs($this->user('Admin', 'Administrator'));

        $avp = $this->user('approver', 'AVP - Finance');
        $staff = $this->user('approver', 'Accounting Staff');
        $branch = $this->branch('DELETE');
        $assignment = $this->assignment($avp, $staff, [$branch]);

        $this->deleteJson("/api/v1/avp-staffs/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('message', 'AVP Staff assignment deleted successfully.');

        $this->assertDatabaseMissing('avp_staff_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseMissing('avp_staff_assignment_branch', [
            'avp_staff_assignment_id' => $assignment->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $avp->id]);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }

    private function user(
        string $role,
        string $position,
        ?string $firstName = null,
    ): User {
        $number = ++$this->sequence;
        $firstName ??= "User{$number}";

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => 'Example',
            'contact' => "0917000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => $position,
            'userName' => "user{$number}",
            'employee_id' => "EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function branch(?string $code = null): Branch
    {
        $number = ++$this->sequence;
        $code ??= "BRANCH-{$number}";

        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "Branch {$number}",
            'branch' => 'Test Company',
            'acronym' => "B{$number}",
        ]);
    }

    /**
     * @param  array<int, Branch>  $branches
     */
    private function assignment(User $avp, User $staff, array $branches): AvpStaffAssignment
    {
        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);

        $assignment->branches()->attach(
            collect($branches)->pluck('id')->all()
        );

        return $assignment;
    }
}
