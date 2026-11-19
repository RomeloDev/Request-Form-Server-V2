<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkStoreUsersRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Branch;
use App\Models\User;
use App\Services\AdminUserCreationService;
use App\Services\ProfilePictureService;
use App\Services\ProfileSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()->withoutRole('Admin')->with('roles:id,name')->get([
            'id',
            'firstName',
            'lastName',
            'userName',
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

        return response()->json([
            'message' => 'Users retrieved successfully',
            'data' => AdminUserResource::collection($users)->resolve(),
        ]);
    }

    public function store(
        StoreUserRequest $request,
        AdminUserCreationService $service,
    ): JsonResponse {
        $user = $service->create($request->validated());

        return response()->json([
            'message' => 'User created successfully.',
            'data' => (new AdminUserResource($user))->resolve(),
        ], 201);
    }

    public function storeBulk(
        BulkStoreUsersRequest $request,
        AdminUserCreationService $service,
    ): JsonResponse {
        $users = $service->createMany($request->validated('users'));

        return response()->json([
            'message' => 'Users created successfully.',
            'created_count' => count($users),
        ], 201);
    }

    /**
     * Stream a protected profile picture to an authorized Admin.
     *
     * The response is built from the located disk and path only. Neither the
     * disk name, the storage path, nor a public URL is exposed to the client.
     */
    public function profilePicture(
        User $user,
        ProfilePictureService $service,
    ): StreamedResponse {
        Gate::authorize('viewProfilePicture', $user);

        $located = $service->locate(
            is_string($user->profile_picture) ? $user->profile_picture : null,
        );

        if ($located === null) {
            abort(404, 'Profile picture not found.');
        }

        return Storage::disk($located['disk'])->response(
            $located['path'],
            "user-{$user->id}-profile-picture.{$located['extension']}",
            [
                'Content-Type' => $located['mime_type'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    /**
     * Stream a protected signature to an authorized Admin.
     *
     * ProfileSignatureService::locate() already restricts reads to a real PNG
     * inside the signatures directory, with the legacy public fallback.
     */
    public function signature(
        User $user,
        ProfileSignatureService $service,
    ): StreamedResponse {
        Gate::authorize('viewSignature', $user);

        $located = $service->locate(
            is_string($user->signature) ? $user->signature : null,
        );

        if ($located === null) {
            abort(404, 'Signature not found.');
        }

        return Storage::disk($located['disk'])->response(
            $located['path'],
            "user-{$user->id}-signature.png",
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    public function verify(string $user): JsonResponse
    {
        // Reject non-Admin callers before looking up the supplied identifier,
        // so this endpoint cannot be used to probe whether an account exists.
        Gate::authorize('viewAny', User::class);

        $target = User::find($user);

        if ($target == null) {
            return response()->json([
                'message' => 'User not found or already deleted.',
            ], 404);
        }

        // Authorize before revealing or changing verification state.
        Gate::authorize('verify', $target);

        if ($target->email_verified_at !== null) {
            return response()->json([
                'message' => 'User is already verified.',
            ], 409);
        }

        $target->email_verified_at = now();
        $target->save();

        return response()->json([
            'message' => 'User '.$target->firstName.' verified successfully.',
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
