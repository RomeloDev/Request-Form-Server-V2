<?php

namespace App\Services;

use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RequestShareCandidateService
{
    /**
     * @param  array{
     *     search?: string|null,
     *     page?: int|string|null,
     *     per_page?: int|string|null
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateFor(
        RequestForm $requestForm,
        array $filters,
    ): LengthAwarePaginator {
        $query = $this->eligibleQueryFor($requestForm);

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        $requestedPerPage = (int) ($filters['per_page'] ?? 10);

        /*
         * Keep a server-side allowlist even though the Form Request already
         * validates the value. Pagination must never follow raw browser
         * input into the query.
         */
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
                            'branch_code',
                            'like',
                            $pattern
                        )
                        ->orWhereHas(
                            'assignedBranch',
                            function (Builder $branchQuery) use ($pattern): void {
                                $branchQuery->where(
                                    'branch_name',
                                    'like',
                                    $pattern
                                );
                            }
                        );
                }
            );
        }

        return $query
            ->with([
                'assignedBranch' => function ($query): void {
                    $query->select([
                        'branch_code',
                        'branch_name',
                    ]);
                },
            ])
            ->orderBy('firstName')
            ->orderBy('lastName')
            ->orderBy('users.id')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'id',
                    'firstName',
                    'lastName',
                    'position',
                    'branch_code',
                ],
                pageName: 'page',
                page: $page,
            );
    }

    /**
     * Build the eligibility query shared by the candidate endpoint.
     *
     * The request is supplied by route model binding, so the requester is
     * derived server-side instead of from a client-supplied ID.
     *
     * @return Builder<User>
     */
    public function eligibleQueryFor(
        RequestForm $requestForm
    ): Builder {
        return User::query()
            ->whereKeyNot($requestForm->requester_id)
            ->whereNotNull('users.email_verified_at')
            ->whereHas(
                'roles',
                fn (Builder $query) => $query->whereIn(
                    'name',
                    [
                        'User',
                        'approver',
                    ]
                )
            )
            ->whereDoesntHave(
                'roles',
                fn (Builder $query) => $query->where(
                    'name',
                    'Admin'
                )
            )
            ->whereDoesntHave(
                'sharedRequests',
                fn (Builder $query) => $query->whereKey(
                    $requestForm->getKey()
                )
            );
    }
}
