<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteBranchHeadAssignmentRequest;
use App\Http\Requests\Admin\ListAvailableBranchHeadBranchesRequest;
use App\Http\Requests\Admin\ListBranchHeadAssignmentsRequest;
use App\Http\Requests\Admin\ListBranchHeadCandidatesRequest;
use App\Http\Requests\Admin\ShowBranchHeadAssignmentRequest;
use App\Http\Requests\Admin\StoreBranchHeadAssignmentRequest;
use App\Http\Requests\Admin\UpdateBranchHeadAssignmentRequest;
use App\Http\Resources\BranchHeadAssignmentResource;
use App\Http\Resources\BranchHeadBranchResource;
use App\Http\Resources\BranchHeadCandidateResource;
use App\Models\Branch;
use App\Models\BranchHeadAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BranchHeadController extends Controller
{
    /**
     * Positions allowed to act as a Branch Head.
     *
     * @var array<int, string>
     */
    private const ELIGIBLE_POSITIONS = [
        'Branch Manager',
        'Branch Manager/Sales Manager',
        'Branch Supervisor',
        'Branch Supervisor/Sales Supervisor',
    ];

    /**
     * Relationships and safe columns required by the assignment Resource.
     *
     * @return array<int, string>
     */
    private function assignmentRelations(): array
    {
        return [
            'user:id,firstName,lastName,position,branch_code,branch',
            'branches:id,branch_code,branch_name,branch,acronym',
        ];
    }

    /**
     * List approvers eligible to become Branch Heads.
     */
    public function candidates(ListBranchHeadCandidatesRequest $request): JsonResponse
    {
        $data = $request->validated();

        $search = trim($data['search'] ?? '');
        $sort = $data['sort'] ?? 'firstname';
        $direction = $data['direction'] ?? 'asc';
        $perPage = $data['per_page'] ?? 10;

        $sortColumns = [
            'id' => 'id',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'position' => 'position',
            'branch' => 'branch',
        ];

        $query = User::query()
            ->role('approver')
            ->whereIn('position', self::ELIGIBLE_POSITIONS)
            ->whereDoesntHave('branchHeadAssignment');

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

        return BranchHeadCandidateResource::collection($users)
            ->additional([
                'message' => 'Branch Head candidates retrieved successfully.',
            ])->response();
    }

    public function availableBranches(ListAvailableBranchHeadBranchesRequest $request): JsonResponse
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

        $query = Branch::query()->whereDoesntHave('branchHeadAssignments');

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

        return BranchHeadBranchResource::collection($branches)
            ->additional([
                'message' => 'Available Branch Head branches retrieved successfully.',
            ])
            ->response();
    }

    public function index(ListBranchHeadAssignmentsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $search = trim($data['search'] ?? '');
        $sort = $data['sort'] ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';
        $perPage = $data['per_page'] ?? 10;

        $sortColumns = [
            'id' => 'id',
            'created_at' => 'created_at',
        ];

        $query = BranchHeadAssignment::query()
            ->with($this->assignmentRelations());

        if ($search !== '') {
            $pattern = '%'.$search.'%';

            $query->where(
                function (Builder $query) use ($pattern): void {
                    $query
                        ->whereHas(
                            'user',
                            function (Builder $query) use ($pattern): void {
                                $query
                                    ->where('firstName', 'like', $pattern)
                                    ->orWhere('lastName', 'like', $pattern)
                                    ->orWhere('position', 'like', $pattern)
                                    ->orWhere('branch_code', 'like', $pattern)
                                    ->orWhere('branch', 'like', $pattern);
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

        $assignments = $query
            ->orderBy($sortColumns[$sort], $direction)
            ->orderBy('id')
            ->paginate(perPage: $perPage);

        return BranchHeadAssignmentResource::collection($assignments)
            ->additional([
                'message' => 'Branch Head assignments retrieved successfully.',
            ])
            ->response();
    }

    /**
     * Show one Branch Head assignment.
     */
    public function show(
        ShowBranchHeadAssignmentRequest $request,
        BranchHeadAssignment $assignment
    ): JsonResponse {
        $assignment->load($this->assignmentRelations());

        return (new BranchHeadAssignmentResource($assignment))
            ->additional([
                'message' => 'Branch Head assignment retrieved successfully.',
            ])
            ->response();
    }

    public function store(StoreBranchHeadAssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $branchIds = $data['branch_ids'];
        sort($branchIds);

        try {
            return DB::transaction(
                function () use ($data, $branchIds): JsonResponse {
                    $user = User::query()->whereKey($data['user_id'])->lockForUpdate()->first();

                    if ($user === null) {
                        abort(
                            409,
                            'The selected Branch Head candidate is no longer available.'
                        );
                    }

                    $branches = Branch::query()
                        ->whereIn('id', $branchIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id']);

                    if ($branches->count() !== count($branchIds)) {
                        abort(
                            409,
                            'One or more selected branches are no longer available.'
                        );
                    }

                    if (
                        ! $user->hasRole('approver')
                        || ! in_array(
                            $user->position,
                            self::ELIGIBLE_POSITIONS,
                            true
                        )
                    ) {
                        abort(
                            409,
                            'The selected user is no longer an eligible Branch Head.'
                        );
                    }

                    if (
                        BranchHeadAssignment::query()
                            ->where('user_id', $user->id)
                            ->exists()
                    ) {
                        abort(
                            409,
                            'The selected user already has a Branch Head assignment.'
                        );
                    }

                    if (
                        DB::table('branch_head_assignment_branch')
                            ->whereIn('branch_id', $branchIds)
                            ->exists()
                    ) {
                        abort(
                            409,
                            'One or more selected branches already have a Branch Head.'
                        );
                    }

                    $assignment = BranchHeadAssignment::create([
                        'user_id' => $user->id,
                    ]);

                    $assignment->branches()->attach($branchIds);

                    $assignment->load($this->assignmentRelations());

                    return (new BranchHeadAssignmentResource($assignment))
                        ->additional([
                            'message' => 'Branch Head assignment created successfully.',
                        ])
                        ->response()
                        ->setStatusCode(201);
                }
            );
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'The selected user or branch is already assigned.',
            ], 409);
        }
    }

    public function update(UpdateBranchHeadAssignmentRequest $request, BranchHeadAssignment $assignment): JsonResponse
    {
        $data = $request->validated();

        $branchIds = $data['branch_ids'];
        sort($branchIds);

        try {
            return DB::transaction(
                function () use ($assignment, $branchIds): JsonResponse {
                    $lockedAssignment = BranchHeadAssignment::query()
                        ->whereKey($assignment->getKey())
                        ->lockForUpdate()
                        ->first();

                    if ($lockedAssignment === null) {
                        abort(
                            409,
                            'The Branch Head assignment is no longer available.'
                        );
                    }

                    $branches = Branch::query()
                        ->whereIn('id', $branchIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id']);

                    if ($branches->count() !== count($branchIds)) {
                        abort(
                            409,
                            'One or more selected branches are no longer available.'
                        );
                    }

                    $hasBranchConflict = DB::table(
                        'branch_head_assignment_branch'
                    )
                        ->whereIn('branch_id', $branchIds)
                        ->where(
                            'branch_head_assignment_id',
                            '!=',
                            $lockedAssignment->id
                        )
                        ->exists();

                    if ($hasBranchConflict) {
                        abort(
                            409,
                            'One or more selected branches belong to another Branch Head assignment.'
                        );
                    }

                    $lockedAssignment->branches()->sync($branchIds);

                    $lockedAssignment->load($this->assignmentRelations());

                    return (new BranchHeadAssignmentResource($lockedAssignment))
                        ->additional([
                            'message' => 'Branch Head assignment updated successfully.',
                        ])
                        ->response();
                }
            );
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'One or more selected branches already have a Branch Head.',
            ], 409);
        }
    }

    public function destroy(
        DeleteBranchHeadAssignmentRequest $request,
        BranchHeadAssignment $assignment
    ): JsonResponse {
        DB::transaction(
            function () use ($assignment): void {
                $lockedAssignment = BranchHeadAssignment::query()
                    ->whereKey($assignment->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedAssignment === null) {
                    abort(
                        409,
                        'The Branch Head assignment is no longer available.'
                    );
                }

                $lockedAssignment->delete();
            }
        );

        return response()->json([
            'message' => 'Branch Head assignment deleted successfully.',
        ]);
    }
}
