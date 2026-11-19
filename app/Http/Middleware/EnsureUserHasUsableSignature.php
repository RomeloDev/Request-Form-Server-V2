<?php

namespace App\Http\Middleware;

use App\Services\ProfileSignatureService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block request-writing actions until the signed-in user has a usable signature.
 *
 * A non-null database value is not enough: ProfileSignatureService::locate()
 * must also find a real PNG on the private disk (or the controlled legacy
 * public fallback). This prevents a request from being submitted with a
 * broken or forged signature path that would later break printing.
 */
class EnsureUserHasUsableSignature
{
    public function __construct(
        private readonly ProfileSignatureService $signatures,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * This middleware is always registered after auth:sanctum. Return a
         * JSON 401 rather than continuing if it is ever reached without an
         * authenticated user, so a misordered route cannot bypass the check.
         */
        if ($user === null) {
            return new JsonResponse([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $signaturePath = is_string($user->signature)
            ? $user->signature
            : null;

        if ($this->signatures->locate($signaturePath) === null) {
            return new JsonResponse([
                'message' => 'Add your signature in your Profile before continuing.',
                'code' => 'signature_required',
            ], Response::HTTP_CONFLICT);
        }

        return $next($request);
    }
}
