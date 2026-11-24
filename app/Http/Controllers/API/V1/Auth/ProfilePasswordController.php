<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeProfilePasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProfilePasswordController extends Controller
{
    public function update(ChangeProfilePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($data, $user): void {
            $user->forceFill([
                'password' => $data['new_password'],
            ])->saveOrFail();

            // A password change invalidates every personal access token.
            $user->tokens()->delete();
        });

        if ($request->hasSession()) {
            $request->session()->regenerate(true);
        }

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}
