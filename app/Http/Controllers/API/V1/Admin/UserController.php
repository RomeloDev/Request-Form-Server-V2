<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use App\Http\Requests\Admin\UpdateUserRequest;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::where('role', '!=', 'Admin')->get([
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
            'profile_picture',
            'signature',
            'email_verified_at',
        ]);

        foreach ($users as $user) {
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
            'message' => 'User ' . $user->firstName . ' verified successfully.',
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

        $newPicture = null;

        try{
            $user->fill($data);

            // Missing optional fields become null, matching the reference code
            $user->branch_code = $data['branch_code'] ?? null;
            $user->branch = $data['branch'] ?? null;

            // keep the rebuild's full name field consistent
            $user->name = trim(
                $user->firstName . ' ' . $user->lastName
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
                try{
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
