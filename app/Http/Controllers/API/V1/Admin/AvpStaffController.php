<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAvailableAvpBranchesRequest;
use App\Http\Requests\Admin\ListAvpStaffCandidatesRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AvpStaffController extends Controller
{
    /**
     * List approvers eligible to act as AVP Finance.
     */
    public function avpCandidates(
        ListAvpStaffCandidatesRequest $request
    ): JsonResponse {
        $query = User::query()
            // Spatie's role scope is the source of truth.
            ->role('approver')
            ->where('position', 'AVP - Finance');

        /*
         * The reference application also requires Head Office.
         * That condition is temporarily deferred because the rebuild
         * does not currently have an HO branch record.
         */
        return $this->paginatedCandidates(
            request: $request,
            query: $query,
            message: 'AVP Finance candidates retrieved successfully.',
        );
    }

    /**
     * List approvers eligible to become staff for the selected AVP.
     */
    public function staffCandidates(
        ListAvpStaffCandidatesRequest $request,
        User $avp
    ): JsonResponse {
        /*
         * Route model binding proves that the account exists.
         * This additional check proves it is eligible to act as an AVP.
         */
        if (
            ! $avp->hasExactRoles('approver')
            || $avp->position !== 'AVP - Finance'
        ) {
            return response()->json([
                'message' => 'The selected account is not an eligible AVP Finance user.',
            ], 409);
        }

        $query = User::query()
            ->role('approver')
            // The AVP cannot be assigned as their own staff member.
            ->where('id', '!=', $avp->id)
            // AVP Finance users should not appear as staff candidates.
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('position')
                    ->orWhere('position', '!=', 'AVP - Finance');
            })
            /*
             * The staff_user_id database constraint is unique.
             * Exclude users already assigned to another AVP.
             */
            ->whereDoesntHave('avpStaffAssignment');

        return $this->paginatedCandidates(
            request: $request,
            query: $query,
            message: 'AVP Staff candidates retrieved successfully.',
        );
    }

    public function availableBranches(ListAvailableAvpBranchesRequest $request): JsonResponse
    {
        $data = $request->validated();

        $search = trim($data['search'] ?? '');
        $sort = $data['sort'] ?? 'branch_code';
        $direction = $data['direction'] ?? 'asc';
        $perPage = $data['per_page'] ?? 10;

        $sortColumns = [
            'id' => 'id',
            'branch_code' => 'branch_code',
            'branch_name' => 'branch_name',
            'branch' => 'branch',
            'acronym' => 'acronym',
        ];

        $query = Branch::query()->whereDoesntHave('avpStaffAssignments');

        if ($search !== '') {
            $pattern = '%'.$search.'%';

            $query->where(
                function (Builder $query) use ($pattern): void {
                    $query
                        ->where('branch_code', 'like', $pattern)
                        ->orWhere('branch_name', 'like', $pattern)
                        ->orWhere('branch', 'like', $pattern)
                        ->orWhere('acronym', 'like', $pattern);
                }
            );
        }

        $branches = $query
            ->orderBy($sortColumns[$sort], $direction)
            ->orderBy('id')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'id',
                    'branch_code',
                    'branch_name',
                    'branch',
                    'acronym',
                ],
            );

        return response()->json([
            'message' => 'Available AVP Staff branches retrieved successfully.',
            'data' => $branches->items(),
            'meta' => [
                'current_page' => $branches->currentPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
                'last_page' => $branches->lastPage(),
                'from' => $branches->firstItem(),
                'to' => $branches->lastItem(),
            ],
        ]);
    }

    /**
     * Apply shared search, sorting, pagination, and response formatting.
     */
    private function paginatedCandidates(
        ListAvpStaffCandidatesRequest $request,
        Builder $query,
        string $message
    ): JsonResponse {
        $data = $request->validated();

        $search = trim($data['search'] ?? '');
        $sort = $data['sort'] ?? 'firstname';
        $direction = $data['direction'] ?? 'asc';
        $perPage = $data['per_page'] ?? 10;

        /*
         * Never pass a user-provided column directly into orderBy().
         * The API sort keys map to known database columns.
         */
        $sortColumns = [
            'id' => 'id',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'email' => 'email',
            'position' => 'position',
            'branch' => 'branch',
        ];

        if ($search !== '') {
            $pattern = '%'.$search.'%';

            $query->where(
                function (Builder $query) use ($pattern): void {
                    $query
                        ->where('firstName', 'like', $pattern)
                        ->orWhere('lastName', 'like', $pattern)
                        ->orWhere('userName', 'like', $pattern)
                        ->orWhere('email', 'like', $pattern)
                        ->orWhere('employee_id', 'like', $pattern)
                        ->orWhere('position', 'like', $pattern)
                        ->orWhere('branch', 'like', $pattern);
                }
            );
        }

        $users = $query
            ->orderBy($sortColumns[$sort], $direction)
            ->orderBy('id')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'id',
                    'firstName as firstname',
                    'lastName as lastname',
                    'userName as username',
                    'email',
                    'employee_id',
                    'position',
                    'contact',
                    'branch_code',
                    'branch',
                    'email_verified_at',
                ],
            );

        /*
         * Candidate queries already guarantee the approver role.
         * Add the role property to preserve the frontend user contract
         * without exposing Spatie's internal roles relationship.
         */
        $users->getCollection()->transform(
            function (User $user): User {
                $user->setAttribute('role', 'approver');

                return $user;
            }
        );

        return response()->json([
            'message' => $message,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }
}
