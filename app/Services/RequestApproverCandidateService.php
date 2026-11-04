<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RequestApproverCandidateService
{
    /**
     * @param  array{
     *     search?: string|null,
     *     sort?: string|null,
     *     direction?: string|null,
     *     page?: int|string|null,
     *     per_page?: int|string|null
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateFor(
        User $requester,
        array $filters,
    ): LengthAwarePaginator {
        $query = $this->eligibleQueryFor($requester);

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        /*
         * Keep a server-side map even though the Form Request validates
         * the sort value. This prevents a future caller from passing a
         * raw database column into orderBy().
         */
        $sortColumns = [
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'position' => 'position',
            'branch' => 'branch',
        ];

        $requestedSort = $filters['sort'] ?? 'firstname';

        $sortColumn = $sortColumns[$requestedSort]
            ?? $sortColumns['firstname'];

        $direction = ($filters['direction'] ?? 'asc') === 'desc'
            ? 'desc'
            : 'asc';

        $requestedPerPage = (int) ($filters['per_page'] ?? 10);

        $perPage = in_array(
            $requestedPerPage,
            [10, 20, 50],
            true
        )
            ? $requestedPerPage
            : 10;

        $page = isset($filters['page'])
        ? (int) $filters['page']
        : null;

        if ($search !== '') {
            $pattern = '%'.$search.'%';

            $query->where(
                function (Builder $query) use ($pattern): void {
                    $query
                        ->where(
                            'firstName',
                            'like',
                            $pattern
                        )
                        ->orWhere(
                            'lastName',
                            'like',
                            $pattern
                        )
                        ->orWhere(
                            'position',
                            'like',
                            $pattern
                        )
                        ->orWhere(
                            'branch',
                            'like',
                            $pattern
                        );
                }
            );
        }

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('id')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'id',
                    'firstName',
                    'lastName',
                    'position',
                    'branch_code',
                    'branch',
                ],
                pageName: 'page',
                page: $page
            );
    }

    /**
     * Build the shared eligibility query for request approvers.
     *
     * Both the candidate endpoint and submission validation must use this
     * query so that displayed candidates and accepted candidates agree.
     *
     * @return Builder<User>
     */
    public function eligibleQueryFor(User $requester): Builder
    {
        $branch = $this->findRequesterBranch($requester);

        return User::query()
            ->role('approver')
            ->whereKeyNot($requester->getKey())
            ->whereDoesntHave('avpStaffAssignment')
            ->where(
                function (Builder $query) use ($branch): void {
                    $query
                        ->where(
                            'branch_code',
                            $branch->branch_code
                        )
                        ->orWhereHas(
                            'branchHeadAssignment.branches',
                            function (Builder $query) use ($branch): void {
                                $query->whereKey($branch->id);
                            }
                        )
                        ->orWhereHas(
                            'avpStaffAssignments.branches',
                            function (Builder $query) use ($branch): void {
                                $query->whereKey($branch->id);
                            }
                        );
                }
            );
    }

    /**
     * Resolve the branch from the authenticated requester.
     */
    private function findRequesterBranch(User $requester): Branch
    {
        if (blank($requester->branch_code)) {
            throw ValidationException::withMessages([
                'branch' => 'Your account does not have an assigned branch.',
            ]);
        }

        $branch = Branch::query()
            ->where(
                'branch_code',
                $requester->branch_code
            )
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch' => 'Your assigned branch could not be found.',
            ]);
        }

        return $branch;
    }
}
