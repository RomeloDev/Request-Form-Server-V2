<?php

namespace App\Services;

use App\Enums\ApprovalStepType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RequestApprovalChainResolver
{
    public function __construct(
        private readonly RequestApproverCandidateService $candidateService,
    ) {}

    /**
     * Validate and resolve an ordered request approval chain.
     *
     * @param  array<int, int|string>  $notedByIds
     * @param  array<int, int|string>  $approvedByIds
     * @return list<array{
     *     approver_id: int,
     *     step_type: ApprovalStepType
     * }>
     */
    public function resolve(
        User $requester,
        array $notedByIds,
        array $approvedByIds,
    ): array {
        $notedByIds = array_map(
            'intval',
            array_values($notedByIds)
        );

        $approvedByIds = array_map(
            'intval',
            array_values($approvedByIds)
        );

        $this->validateStructure(
            notedByIds: $notedByIds,
            approvedByIds: $approvedByIds,
        );

        $selectedIds = array_values(
            array_unique([
                ...$notedByIds,
                ...$approvedByIds,
            ])
        );

        /*
         * Query the selected users through the same eligibility rules used
         * by the candidate endpoint.
         *
         * Load only AVP Staff assignments that cover the requester branch.
         */
        $eligibleApprovers = $this->candidateService
            ->eligibleQueryFor($requester)
            ->whereKey($selectedIds)
            ->with([
                'avpStaffAssignments' => function (HasMany $query) use (
                    $requester
                ): void {
                    $query
                        ->whereHas(
                            'branches',
                            function (Builder $query) use (
                                $requester
                            ): void {
                                $query->where(
                                    'branches.branch_code',
                                    $requester->branch_code
                                );
                            }
                        )
                        ->with('staff');
                },
            ])
            ->get()
            ->keyBy('id');

        $this->validateEligibility(
            notedByIds: $notedByIds,
            approvedByIds: $approvedByIds,
            eligibleApprovers: $eligibleApprovers,
        );

        $steps = [];
        $addedApproverIds = [];

        /*
         * Noted By users always come before Approved By users.
         */
        foreach ($notedByIds as $approverId) {
            $steps[] = [
                'approver_id' => $approverId,
                'step_type' => ApprovalStepType::NotedBy,
            ];

            $addedApproverIds[$approverId] = true;
        }

        foreach ($approvedByIds as $approverId) {
            /** @var User $approver */
            $approver = $eligibleApprovers->get($approverId);

            /*
             * A matching AVP Staff assignment is loaded only when it
             * covers the requester's branch.
             */
            $assignment = $approver
                ->avpStaffAssignments
                ->first();

            if ($assignment !== null) {
                $staff = $assignment->staff;

                /*
                 * Treat invalid Admin routing as a validation conflict
                 * instead of silently skipping the configured delegate.
                 */
                if (
                    ! $staff instanceof User
                    || ! $staff->hasRole('approver')
                ) {
                    throw ValidationException::withMessages([
                        'approved_by_ids' => 'The selected AVP Finance routing configuration is no longer valid.',
                    ]);
                }

                if (! isset($addedApproverIds[$staff->id])) {
                    $steps[] = [
                        'approver_id' => $staff->id,
                        'step_type' => ApprovalStepType::DelegatedApproval,
                    ];

                    $addedApproverIds[$staff->id] = true;
                }
            }

            $steps[] = [
                'approver_id' => $approverId,
                'step_type' => ApprovalStepType::ApprovedBy,
            ];

            $addedApproverIds[$approverId] = true;
        }

        return $steps;
    }

    /**
     * Validate ordering input before querying the database.
     *
     * @param  list<int>  $notedByIds
     * @param  list<int>  $approvedByIds
     */
    private function validateStructure(
        array $notedByIds,
        array $approvedByIds,
    ): void {
        $errors = [];

        if ($approvedByIds === []) {
            $errors['approved_by_ids'] =
                'At least one Approved By user is required.';
        }

        if (
            count($notedByIds)
            !== count(array_unique($notedByIds))
        ) {
            $errors['noted_by_ids'] =
                'The Noted By list contains duplicate users.';
        }

        if (
            count($approvedByIds)
            !== count(array_unique($approvedByIds))
        ) {
            $errors['approved_by_ids'] =
                'The Approved By list contains duplicate users.';
        }

        if (
            array_intersect(
                $notedByIds,
                $approvedByIds
            ) !== []
        ) {
            $errors['approved_by_ids'] =
                'A user cannot be both Noted By and Approved By.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Ensure every selected user remains eligible at submission time.
     *
     * @param  list<int>  $notedByIds
     * @param  list<int>  $approvedByIds
     * @param  Collection<int, User>  $eligibleApprovers
     */
    private function validateEligibility(
        array $notedByIds,
        array $approvedByIds,
        Collection $eligibleApprovers,
    ): void {
        $eligibleIds = $eligibleApprovers
            ->keys()
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $errors = [];

        if (
            array_diff(
                $notedByIds,
                $eligibleIds
            ) !== []
        ) {
            $errors['noted_by_ids'] =
                'One or more selected Noted By users are no longer eligible.';
        }

        if (
            array_diff(
                $approvedByIds,
                $eligibleIds
            ) !== []
        ) {
            $errors['approved_by_ids'] =
                'One or more selected Approved By users are no longer eligible.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
