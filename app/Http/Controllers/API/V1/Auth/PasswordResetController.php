<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    private const LINK_RESPONSE = 'If an account exists for that email, a password reset link has been sent.';

    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return response()->json([
            'message' => self::LINK_RESPONSE,
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->saveOrFail();

                    $user->tokens()->delete();

                    if (config('session.driver') === 'database') {
                        DB::table((string) config('session.table', 'sessions'))
                            ->where('user_id', $user->getKey())
                            ->delete();
                    }
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
                'errors' => [
                    'token' => ['Request a new password reset link and try again.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset successfully.',
        ]);
    }
}
