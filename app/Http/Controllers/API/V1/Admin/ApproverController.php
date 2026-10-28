<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Admin\ListApproversRequest;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Admin\AssignApproversRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApproverController extends Controller
{
    public function index(
        ListApproversRequest $request
    ): JsonResponse {
        return $this->paginatedUsersByRole(
            request: $request,
            role: 'approver',
            message: 'Approvers retrieved successfully',
        );
    }

    public function candidates(
        ListApproversRequest $request
    ): JsonResponse {
        return $this->paginatedUsersByRole(
            request: $request,
            role: 'User',
            message: 'Approver candidates retrieved successfully',
        );
    }

    private function paginatedUsersByRole(
        ListApproversRequest $request,
        string $role,
        string $message,
    ): JsonResponse {
        $data = $request->validated();

        $search = trim($data['search'] ?? '');
        $sort = $data['sort'] ?? 'firstname';
        $direction = $data['direction'] ?? 'asc';
        $perPage = $data['per_page'] ?? 10;

        $sortColumns = [
            'id' => 'id',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'email' => 'email',
            'position' => 'position',
            'branch' => 'branch',
        ];

        $query = User::query()
            ->where('role', $role);

        if ($search !== '') {
            $pattern = '%' . $search . '%';

            $query->where(
                function (Builder $query) use ($pattern) {
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
                    'role',
                    'position',
                    'contact',
                    'branch_code',
                    'branch',
                    'email_verified_at',
                ],
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
        ], 200);
    }

    public function store(AssignApproversRequest $request): JsonResponse {
        $userIds = $request->validated('user_ids');

        return DB::transaction(
            function () use ($userIds): JsonResponse {
                $users = User::query()
                    ->whereKey($userIds)
                    ->lockForUpdate()
                    ->get(['id', 'role']);

                $allEligible =
                    $users->count() === count($userIds)
                    && $users->every(
                        fn (User $user) => $user->role === 'User'
                    );

                if (!$allEligible) {
                    return response()->json([
                        'message' =>
                            'One or more selected users are no longer eligible to become approvers.',
                    ], 409);
                }

                User::query()
                    ->whereKey($userIds)
                    ->update([
                        'role' => 'approver',
                    ]);

                $count = count($userIds);

                return response()->json([
                    'message' => $count === 1
                        ? 'Approver assigned successfully.'
                        : $count . ' approvers assigned successfully.',
                    'data' => [
                        'updated_count' => $count,
                    ],
                ], 200);
            }
        );
    }

    public function destroy(User $user): JsonResponse {
        Gate::authorize('update', $user);

        $updated = User::query()
            ->whereKey($user->id)
            ->where('role', 'approver')
            ->update([
                'role' => 'User'
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'The selected user is not currently an approver.',
            ], 409);
        }

        return response()->json([
            'message' => 'Approver role removed successfully',
        ], 200);
    }
}
