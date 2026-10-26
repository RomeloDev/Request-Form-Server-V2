<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::whereAny(
            ['email', 'userName', 'employee_id'],
            $data['email']
        )->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => "We couldn't find an account with that email.",
            ], 404);
        }

        $key = "loginAttempts: {$user->id}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'status' => false,
                'message' => "Too many attempts. You may try again in {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($key, 60);

        if (Auth::guard('web')->check()) {
            return response()->json([
                'message' => 'You are already logged in.',
            ], 226);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'status' => false,
                'message' => 'Your email has not been verified yet. Please contact the administrator.',
            ], 403);
        }

        if (! Auth::guard('web')->attempt([
            'email' => $user->email,
            'password' => $data['password'],
        ])) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Credentials.',
            ], 400);
        }

        $request->session()->regenerate();

        return response()->json([
            'status' => true,
            'message' => 'Login successful. Redirecting you to Dashboard.',
            'role' => $user->role,
        ], 200);
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
