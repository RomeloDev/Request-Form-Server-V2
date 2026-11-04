<?php

namespace Tests\Feature\RequestForms;

use App\Enums\ApprovalStepType;
use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\User;
use App\Services\RequestApprovalChainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RequestApprovalChainResolverTest extends TestCase
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

    public function test_it_preserves_noted_by_and_approved_by_order(): void
    {
        $branch = $this->branch('CALI');
        $requester = $this->user($branch, 'User', 'Requester');
        $notedFirst = $this->user($branch, 'approver', 'NotedFirst');
        $notedSecond = $this->user($branch, 'approver', 'NotedSecond');
        $approvedFirst = $this->user($branch, 'approver', 'ApprovedFirst');
        $approvedSecond = $this->user($branch, 'approver', 'ApprovedSecond');

        $steps = $this->resolver()->resolve(
            requester: $requester,
            notedByIds: [$notedFirst->id, $notedSecond->id],
            approvedByIds: [$approvedFirst->id, $approvedSecond->id],
        );

        $this->assertSame([
            [
                'approver_id' => $notedFirst->id,
                'step_type' => ApprovalStepType::NotedBy,
            ],
            [
                'approver_id' => $notedSecond->id,
                'step_type' => ApprovalStepType::NotedBy,
            ],
            [
                'approver_id' => $approvedFirst->id,
                'step_type' => ApprovalStepType::ApprovedBy,
            ],
            [
                'approver_id' => $approvedSecond->id,
                'step_type' => ApprovalStepType::ApprovedBy,
            ],
        ], $steps);
    }

    public function test_it_inserts_matching_delegated_staff_before_the_avp(): void
    {
        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO');
        $requester = $this->user($requesterBranch, 'User', 'Requester');
        $avp = $this->user($headOffice, 'approver', 'FinanceAVP', 'AVP - Finance');
        $staff = $this->user($requesterBranch, 'approver', 'DelegatedStaff');

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        $steps = $this->resolver()->resolve(
            requester: $requester,
            notedByIds: [],
            approvedByIds: [$avp->id],
        );

        $this->assertSame([
            [
                'approver_id' => $staff->id,
                'step_type' => ApprovalStepType::DelegatedApproval,
            ],
            [
                'approver_id' => $avp->id,
                'step_type' => ApprovalStepType::ApprovedBy,
            ],
        ], $steps);
    }

    public function test_it_does_not_insert_staff_for_an_unrelated_branch_assignment(): void
    {
        $requesterBranch = $this->branch('CALI');
        $otherBranch = $this->branch('OTHER');
        $requester = $this->user($requesterBranch, 'User', 'Requester');

        /*
         * The AVP remains eligible through the same-branch rule, but this
         * staff assignment must not apply because it covers another branch.
         */
        $avp = $this->user($requesterBranch, 'approver', 'FinanceAVP', 'AVP - Finance');
        $staff = $this->user($otherBranch, 'approver', 'OtherBranchStaff');

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($otherBranch->id);

        $steps = $this->resolver()->resolve(
            requester: $requester,
            notedByIds: [],
            approvedByIds: [$avp->id],
        );

        $this->assertSame([
            [
                'approver_id' => $avp->id,
                'step_type' => ApprovalStepType::ApprovedBy,
            ],
        ], $steps);
    }

    public function test_it_rejects_an_empty_approved_by_list(): void
    {
        $branch = $this->branch('CALI');
        $requester = $this->user($branch, 'User', 'Requester');

        $this->assertValidationError(
            field: 'approved_by_ids',
            callback: fn () => $this->resolver()->resolve(
                requester: $requester,
                notedByIds: [],
                approvedByIds: [],
            ),
        );
    }

    public function test_it_rejects_duplicate_and_overlapping_users(): void
    {
        $branch = $this->branch('CALI');
        $requester = $this->user($branch, 'User', 'Requester');
        $approver = $this->user($branch, 'approver', 'Approver');

        $this->assertValidationError(
            field: 'approved_by_ids',
            callback: fn () => $this->resolver()->resolve(
                requester: $requester,
                notedByIds: [],
                approvedByIds: [$approver->id, $approver->id],
            ),
        );

        $this->assertValidationError(
            field: 'approved_by_ids',
            callback: fn () => $this->resolver()->resolve(
                requester: $requester,
                notedByIds: [$approver->id],
                approvedByIds: [$approver->id],
            ),
        );
    }

    public function test_it_rejects_a_selected_user_who_is_not_eligible(): void
    {
        $requesterBranch = $this->branch('CALI');
        $otherBranch = $this->branch('OTHER');
        $requester = $this->user($requesterBranch, 'User', 'Requester');
        $unrelatedApprover = $this->user($otherBranch, 'approver', 'Unrelated');

        $this->assertValidationError(
            field: 'approved_by_ids',
            callback: fn () => $this->resolver()->resolve(
                requester: $requester,
                notedByIds: [],
                approvedByIds: [$unrelatedApprover->id],
            ),
        );
    }

    public function test_it_rejects_stale_avp_staff_routing(): void
    {
        $requesterBranch = $this->branch('CALI');
        $headOffice = $this->branch('HO');
        $requester = $this->user($requesterBranch, 'User', 'Requester');
        $avp = $this->user($headOffice, 'approver', 'FinanceAVP', 'AVP - Finance');
        $staff = $this->user($requesterBranch, 'approver', 'DelegatedStaff');

        $assignment = AvpStaffAssignment::create([
            'avp_user_id' => $avp->id,
            'staff_user_id' => $staff->id,
        ]);
        $assignment->branches()->attach($requesterBranch->id);

        /*
         * Simulate an Admin role change that left stale routing behind.
         */
        $staff->syncRoles('User');

        $this->assertValidationError(
            field: 'approved_by_ids',
            callback: fn () => $this->resolver()->resolve(
                requester: $requester,
                notedByIds: [],
                approvedByIds: [$avp->id],
            ),
        );
    }

    private function resolver(): RequestApprovalChainResolver
    {
        return app(RequestApprovalChainResolver::class);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertValidationError(
        string $field,
        callable $callback,
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected a validation error for {$field}."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors(),
            );
        }
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
        ?Branch $branch,
        ?string $role,
        string $firstName,
        string $position = 'Employee',
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => $firstName,
            'lastName' => "Example{$number}",
            'contact' => "0919000{$number}",
            'branch_code' => $branch?->branch_code,
            'branch' => $branch?->branch,
            'position' => $position,
            'userName' => "resolver-user-{$number}",
            'employee_id' => "RESOLVER-EMP-{$number}",
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
