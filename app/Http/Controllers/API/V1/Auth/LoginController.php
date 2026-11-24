<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (Auth::guard('web')->check()) {
            return response()->json([
                'message' => 'You are already logged in.',
            ], 226);
        }

        $key = $this->loginThrottleKey($request, $data['email']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'status' => false,
                'message' => "Too many attempts. You may try again in {$seconds} seconds.",
            ], 429);
        }

        $user = User::whereAny(
            ['email', 'userName', 'employee_id'],
            $data['email']
        )->first();

        if (
            $user === null
            || ! Hash::check($data['password'], $user->getAuthPassword())
        ) {
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);

            return response()->json([
                'status' => false,
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'status' => false,
                'message' => 'Your email has not been verified yet. Please contact the administrator.',
            ], 403);
        }

        if (Hash::needsRehash($user->getAuthPassword())) {
            $user->forceFill([
                'password' => Hash::make($data['password']),
            ])->saveOrFail();
        }

        Auth::guard('web')->login($user);
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return response()->json([
            'status' => true,
            'message' => 'Login successful. Redirecting you to Dashboard.',
            'role' => $user->getRoleNames()->first(),
        ], 200);
    }

    private function loginThrottleKey(
        LoginRequest $request,
        string $identifier,
    ): string {
        return 'login:'.hash('sha256', implode('|', [
            Str::lower($identifier),
            (string) $request->ip(),
        ]));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
