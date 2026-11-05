<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()->withoutRole('Admin')->with('roles:id,name')->get([
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
            'profile_picture',
            'signature',
            'email_verified_at',
        ]);

        foreach ($users as $user) {
            // Preserve the current frontend role string using Spatie.
            $user->setAttribute(
                'role',
                $user->getRoleNames()->first()
            );

            // Avoid exposing Spatie's internal relationship structure.
            $user->unsetRelation('roles');

            $user->verification_status = $user->email_verified_at
                ? 'Verified'
                : 'Not Verified';
        }

        return response()->json([
            'message' => 'Users retrieved successfully',
            'data' => $users,
        ]);
    }

    public function verify(string $id): JsonResponse
    {
        $user = User::find($id);

        if ($user == null) {
            return response()->json([
                'message' => 'User not found or already deleted.',
            ], 404);
        }

        if ($user->email_verified_at !== null) {
            return response()->json([
                'message' => 'User is already verified.',
            ], 409);
        }

        $user->email_verified_at = now();
        $user->save();

        return response()->json([
            'message' => 'User '.$user->firstName.' verified successfully.',
        ], 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::find($id);

        if ($user == null) {
            return response()->json([
                'message' => 'User not found or already deleted.',
            ], 404);
        }

        Gate::authorize('delete', $user);

        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'User deleted successfully',
        ], 200);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->safe()->except([
            'password',
            'profile_picture',
        ]);

        $branchCode = $data['branch_code'] ?? null;
        unset($data['branch_code']);

        $branch = $branchCode === null
            ? null
            : Branch::query()
                ->where('branch_code', $branchCode)
                ->firstOrFail();

        $newPicture = null;

        try {
            $user->fill($data);

            // Keep the redundant company label consistent with its branch.
            $user->branch_code = $branch?->branch_code;
            $user->branch = $branch?->branch;

            // keep the rebuild's full name field consistent
            $user->name = trim(
                $user->firstName.' '.$user->lastName
            );

            if ($request->filled('password')) {
                $user->password = $request->validated('password');
            }

            if ($request->hasFile('profile_picture')) {
                $newPicture = $request->file('profile_picture')->store('request_form_profile_pictures', 'public');

                if ($newPicture === false) {
                    throw new \RuntimeException('Unable to store the profile picture');
                }

                $user->profile_picture = $newPicture;
            }

            $user->save();
        } catch (\Throwable $exception) {
            if (is_string($newPicture)) {
                try {
                    Storage::disk('public')->delete($newPicture);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'User information updated successfully',
        ], 200);
    }
}
