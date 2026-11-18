<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileSignatureRequest;
use App\Services\ProfileSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileSignatureController extends Controller
{
    /**
     * Stream the authenticated user's own signature.
     *
     * The owner always comes from the session. No user identifier is read
     * from the URL, query string, or request body, and the response never
     * reveals the disk or storage path.
     */
    public function show(
        Request $request,
        ProfileSignatureService $service,
    ): StreamedResponse {
        $user = $request->user();

        Gate::authorize('manageSignature', $user);

        $signaturePath = is_string($user->signature)
            ? $user->signature
            : null;
        $located = $service->locate($signaturePath);

        if ($located === null) {
            abort(404, 'Signature not found.');
        }

        return Storage::disk($located['disk'])->response(
            $located['path'],
            'signature.png',
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    /**
     * Create or replace the authenticated user's signature.
     */
    public function update(
        UpdateProfileSignatureRequest $request,
        ProfileSignatureService $service,
    ): JsonResponse {
        $service->replace(
            $request->user(),
            $request->file('signature'),
        );

        return response()->json([
            'message' => 'Signature updated successfully.',
            'data' => [
                'has_signature' => true,
            ],
        ]);
    }

    /**
     * Remove the authenticated user's signature.
     */
    public function destroy(
        Request $request,
        ProfileSignatureService $service,
    ): JsonResponse {
        $user = $request->user();

        Gate::authorize('manageSignature', $user);

        $service->remove($user);

        return response()->json([
            'message' => 'Signature removed successfully.',
            'data' => [
                'has_signature' => false,
            ],
        ]);
    }
}
