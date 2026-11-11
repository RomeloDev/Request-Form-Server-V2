<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RequestSharingService
{
    public function __construct(
        private readonly RequestShareCandidateService $candidateService,
    ) {}

    /**
     * Paginate the users this request has already been shared with.
     *
     * This deliberately uses sharedRecipients() instead of the candidate
     * eligibility query, because existing recipients must remain visible
     * even if their role or verification state later changes.
     *
     * @param  array{
     *     search?: string|null,
     *     page?: int|string|null,
     *     per_page?: int|string|null
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateRecipientsFor(
        RequestForm $requestForm,
        array $filters,
    ): LengthAwarePaginator {
        $query = $requestForm->sharedRecipients();

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        $requestedPerPage = (int) ($filters['per_page'] ?? 10);

        /*
         * Keep a server-side allowlist even though the Form Request already
         * validates the value.
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
                            'users.firstName',
                            'like',
                            $pattern
                        )
                        ->orWhere(
                            'users.lastName',
                            'like',
                            $pattern
                        )
                        ->orWhere(
                            'users.position',
                            'like',
                            $pattern
                        )
                        ->orWhere(
                            'users.branch_code',
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
            ->orderBy('users.firstName')
            ->orderBy('users.lastName')
            ->orderBy('users.id')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'users.id',
                    'users.firstName',
                    'users.lastName',
                    'users.position',
                    'users.branch_code',
                ],
                pageName: 'page',
                page: $page,
            )
            ->withQueryString();
    }

    /**
     * Revoke one recipient's share from a completed request.
     *
     * The recipient is treated as a nested share identifier: a missing or
     * unrelated share produces the same 404 instead of revealing whether an
     * arbitrary user account exists.
     */
    public function revoke(
        RequestForm $requestForm,
        int $recipientId,
    ): void {
        DB::transaction(
            function () use ($requestForm, $recipientId): void {
                /*
                 * Keep the same parent-row lock order as share creation
                 * so status and shares cannot change underneath us.
                 */
                $lockedRequest = RequestForm::query()
                    ->lockForUpdate()
                    ->findOrFail($requestForm->getKey());

                if ($lockedRequest->status !== RequestStatus::Completed) {
                    throw new ConflictHttpException(
                        'Only completed requests can be shared.',
                    );
                }

                /*
                 * detach() is scoped to this request's relation, deletes
                 * only the request_shares pivot row, and never touches the
                 * recipient's users row.
                 */
                $deleted = $lockedRequest->sharedRecipients()
                    ->detach($recipientId);

                if ($deleted === 0) {
                    throw new NotFoundHttpException(
                        'Shared recipient not found.',
                    );
                }
            },
        );
    }

    /**
     * Share a completed request with the selected eligible recipients.
     *
     * All selected users must still be eligible. The request is rejected as
     * a whole rather than partially shared.
     *
     * @param  array<int, int>  $userIds
     * @return int The number of created share records.
     */
    public function share(
        RequestForm $requestForm,
        array $userIds,
    ): int {
        return DB::transaction(
            function () use ($requestForm, $userIds): int {
                /*
                 * Lock the request row so concurrent share attempts cannot
                 * both pass the completed-status and recipient checks.
                 */
                $lockedRequest = RequestForm::query()
                    ->lockForUpdate()
                    ->findOrFail($requestForm->getKey());

                if ($lockedRequest->status !== RequestStatus::Completed) {
                    throw new ConflictHttpException(
                        'Only completed requests can be shared.',
                    );
                }

                $requestedIds = array_values(
                    array_map('intval', $userIds)
                );

                /*
                 * Reuse the candidate rules so the users that can be
                 * submitted always match the users the selector displayed.
                 */
                $eligibleIds = $this->candidateService
                    ->eligibleQueryFor($lockedRequest)
                    ->whereKey($requestedIds)
                    ->pluck('id')
                    ->map(
                        fn ($id): int => (int) $id
                    )
                    ->all();

                if (count($eligibleIds) !== count($requestedIds)) {
                    throw ValidationException::withMessages([
                        'user_ids' => 'One or more selected recipients are not eligible to receive this request.',
                    ]);
                }

                $lockedRequest->sharedRecipients()->attach(
                    $eligibleIds
                );

                return count($eligibleIds);
            },
        );
    }
}
