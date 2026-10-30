<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAvpStaffAssignmentRequest;
use App\Http\Requests\Admin\ListAvailableAvpBranchesRequest;
use App\Http\Requests\Admin\ListAvpStaffAssignmentsRequest;
use App\Http\Requests\Admin\ListAvpStaffCandidatesRequest;
use App\Http\Requests\Admin\StoreAvpStaffAssignmentRequest;
use App\Http\Requests\Admin\UpdateAvpStaffAssignmentRequest;
use App\Http\Resources\AvpStaffAssignmentResource;
use App\Http\Resources\AvpStaffBranchResource;
use App\Http\Resources\AvpStaffCandidateResource;
use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

        return AvpStaffBranchResource::collection($branches)
            ->additional([
                'message' => 'Available AVP Staff branches retrieved successfully.',
            ])
            ->response();
    }

    /**
     * List configured AVP Staff assignments.
     */
    public function index(ListAvpStaffAssignmentsRequest $request): JsonResponse
    {
        /*
        * Only validated query parameters enter the database query.
        */
        $data = $request->validated();

        $search = trim($data['search'] ?? '');
        $sort = $data['sort'] ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';
        $perPage = $data['per_page'] ?? 10;

        /*
        * Client sort names map to known database columns.
        * We never pass an arbitrary client value to orderBy().
        */
        $sortColumns = [
            'id' => 'id',
            'created_at' => 'created_at',
        ];

        /*
        * with() loads related AVP, staff, and branch records ahead
        * of time so the Resource does not cause N+1 queries.
        */
        $query = AvpStaffAssignment::query()
            ->with([
                'avp:id,firstName,lastName,position',
                'staff:id,firstName,lastName,position',
                'branches:id,branch_code,branch_name,branch,acronym',
            ]);

        if ($search !== '') {
            $pattern = '%'.$search.'%';

            /*
            * The outer where() groups all relationship searches:
            *
            * WHERE (
            *     AVP matches
            *     OR staff matches
            *     OR branch matches
            * )
            */
            $query->where(
                function (Builder $query) use ($pattern): void {
                    $query
                        ->whereHas(
                            'avp',
                            function (Builder $query) use ($pattern): void {
                                $query
                                    ->where('firstName', 'like', $pattern)
                                    ->orWhere('lastName', 'like', $pattern);
                            }
                        )
                        ->orWhereHas(
                            'staff',
                            function (Builder $query) use ($pattern): void {
                                $query
                                    ->where('firstName', 'like', $pattern)
                                    ->orWhere('lastName', 'like', $pattern)
                                    ->orWhere('position', 'like', $pattern);
                            }
                        )
                        ->orWhereHas(
                            'branches',
                            function (Builder $query) use ($pattern): void {
                                $query
                                    ->where('branch_code', 'like', $pattern)
                                    ->orWhere('branch_name', 'like', $pattern)
                                    ->orWhere('branch', 'like', $pattern)
                                    ->orWhere('acronym', 'like', $pattern);
                            }
                        );
                }
            );
        }

        /*
        * Filtering happens first, followed by sorting and pagination.
        */
        $assignments = $query
            ->orderBy($sortColumns[$sort], $direction)
            ->orderBy('id')
            ->paginate(perPage: $perPage);

        /*
        * collection() runs the Resource once for every assignment.
        * Because $assignments is a paginator, Laravel also generates
        * pagination links and metadata.
        */
        return AvpStaffAssignmentResource::collection($assignments)
            ->additional([
                'message' => 'AVP Staff assignments retrieved successfully.',
            ])
            ->response();
    }

    /**
     * Create an AVP Staff assignment and its branch routing.
     */
    public function store(StoreAvpStaffAssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $userIds = [
            $data['avp_user_id'],
            $data['staff_user_id'],
        ];
        sort($userIds);

        $branchIds = $data['branch_ids'];
        sort($branchIds);

        try {
            return DB::transaction(function () use ($data, $userIds, $branchIds): JsonResponse {
                $users = User::query()
                    ->whereIn('id', $userIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $branches = Branch::query()
                    ->whereIn('id', $branchIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                if ($users->count() !== count($userIds)) {
                    abort(409, 'The selected AVP or staff member is no longer available.');
                }

                if ($branches->count() !== count($branchIds)) {
                    abort(409, 'One or more selected branches are no longer available.');
                }

                $avp = $users->get($data['avp_user_id']);
                $staff = $users->get($data['staff_user_id']);

                if (! $avp->hasExactRoles('approver') || $avp->position !== 'AVP - Finance') {
                    abort(409, 'The selected AVP is no longer eligible.');
                }

                if (! $staff->hasRole('approver') || $staff->position === 'AVP - Finance') {
                    abort(409, 'The selected staff member is no longer eligible.');
                }

                if (AvpStaffAssignment::query()->where('staff_user_id', $staff->id)->exists()) {
                    abort(409, 'The selected staff member is already assigned.');
                }

                if (DB::table('avp_staff_assignment_branch')->whereIn('branch_id', $branchIds)->exists()) {
                    abort(409, 'One or more selected branches are already assigned.');
                }

                $assignment = AvpStaffAssignment::create([
                    'avp_user_id' => $avp->id,
                    'staff_user_id' => $staff->id,
                ]);

                $assignment->branches()->attach($branchIds);
                $assignment->load($this->assignmentRelations());

                return (new AvpStaffAssignmentResource($assignment))
                    ->additional([
                        'message' => 'AVP Staff assignment created successfully.',
                    ])
                    ->response()
                    ->setStatusCode(201);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'The selected staff member or branch is already assigned.',
            ], 409);
        }
    }

    /**
     * Replace the branches routed through an AVP Staff assignment.
     */
    public function update(
        UpdateAvpStaffAssignmentRequest $request,
        AvpStaffAssignment $assignment
    ): JsonResponse {
        $data = $request->validated();

        /*
        * Stable ordering reduces deadlock risk when concurrent
        * requests select overlapping branches.
        */
        $branchIds = $data['branch_ids'];
        sort($branchIds);

        try {
            return DB::transaction(
                function () use ($assignment, $branchIds): JsonResponse {
                    /*
                    * Route model binding loaded the assignment before the
                    * transaction. Load it again with a database lock.
                    */
                    $lockedAssignment = AvpStaffAssignment::query()
                        ->whereKey($assignment->getKey())
                        ->lockForUpdate()
                        ->first();

                    if ($lockedAssignment === null) {
                        abort(
                            409,
                            'The AVP Staff assignment is no longer available.'
                        );
                    }

                    /*
                    * Lock every selected branch before checking and syncing.
                    */
                    $branches = Branch::query()
                        ->whereIn('id', $branchIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id']);

                    /*
                    * A branch could disappear after Form Request validation.
                    */
                    if ($branches->count() !== count($branchIds)) {
                        abort(
                            409,
                            'One or more selected branches are no longer available.'
                        );
                    }

                    /*
                    * Allow branches already owned by this assignment.
                    * Reject only branches owned by a different assignment.
                    */
                    $hasBranchConflict = DB::table(
                        'avp_staff_assignment_branch'
                    )
                        ->whereIn('branch_id', $branchIds)
                        ->where(
                            'avp_staff_assignment_id',
                            '!=',
                            $lockedAssignment->id
                        )
                        ->exists();

                    if ($hasBranchConflict) {
                        abort(
                            409,
                            'One or more selected branches belong to another assignment.'
                        );
                    }

                    /*
                    * Make the pivot table exactly match branchIds.
                    */
                    $lockedAssignment->branches()->sync($branchIds);

                    /*
                    * Reload the relationships required by the Resource.
                    */
                    $lockedAssignment->load($this->assignmentRelations());

                    return (new AvpStaffAssignmentResource(
                        $lockedAssignment
                    ))
                        ->additional([
                            'message' => 'AVP Staff assignment updated successfully.',
                        ])
                        ->response();
                }
            );
        } catch (UniqueConstraintViolationException) {
            /*
            * The database remains the final protection if another request
            * claims a branch between our application checks.
            */
            return response()->json([
                'message' => 'One or more selected branches are already assigned.',
            ], 409);
        }
    }

    /**
     * Delete an assignment without deleting its users, roles, or branches.
     */
    public function destroy(
        DeleteAvpStaffAssignmentRequest $request,
        AvpStaffAssignment $assignment
    ): JsonResponse {
        DB::transaction(function () use ($assignment): void {
            $lockedAssignment = AvpStaffAssignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedAssignment === null) {
                abort(409, 'The AVP Staff assignment is no longer available.');
            }

            $lockedAssignment->delete();
        });

        return response()->json([
            'message' => 'AVP Staff assignment deleted successfully.',
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
                    'firstName',
                    'lastName',
                    'position',
                    'branch_code',
                    'branch',
                ],
            );

        return AvpStaffCandidateResource::collection($users)
            ->additional([
                'message' => $message,
            ])
            ->response();
    }

    /**
     * Relationships and safe columns required by the assignment resource.
     *
     * @return array<int, string>
     */
    private function assignmentRelations(): array
    {
        return [
            'avp:id,firstName,lastName,position',
            'staff:id,firstName,lastName,position',
            'branches:id,branch_code,branch_name,branch,acronym',
        ];
    }
}
