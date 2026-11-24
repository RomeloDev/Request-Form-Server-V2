<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfilePictureRequest;
use App\Http\Resources\ProfileResource;
use App\Services\ProfilePictureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        Gate::authorize('viewProfile', $user);

        $user->loadMissing([
            'roles:id,name',
            'assignedBranch:id,branch_code,branch_name,branch',
            'positionRecord:id,label,value',
        ]);

        return (new ProfileResource($user))
            ->additional([
                'status' => true,
            ])
            ->response();
    }

    public function updatePicture(UpdateProfilePictureRequest $request, ProfilePictureService $service): JsonResponse
    {
        $user = $request->user();

        $service->replace(
            $user,
            $request->file('profile_picture'),
        );

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'data' => [
                'has_profile_picture' => true,
            ],
        ]);
    }

    public function showPicture(
        Request $request,
        ProfilePictureService $service,
    ): StreamedResponse {
        $user = $request->user();

        Gate::authorize('viewProfile', $user);

        $profilePicture = is_string($user->profile_picture)
            ? $user->profile_picture
            : null;
        $located = $service->locate($profilePicture);

        if ($located === null) {
            abort(404, 'Profile picture not found.');
        }

        return Storage::disk($located['disk'])->response(
            $located['path'],
            'profile-picture.'.$located['extension'],
            [
                'Content-Type' => $located['mime_type'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    public function destroyPicture(
        Request $request,
        ProfilePictureService $service,
    ): JsonResponse {
        $user = $request->user();

        Gate::authorize('manageProfilePicture', $user);

        $service->remove($user);

        return response()->json([
            'message' => 'Profile picture removed successfully.',
            'data' => [
                'has_profile_picture' => false,
            ],
        ]);
    }
}
