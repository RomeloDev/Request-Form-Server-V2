<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeProfilePasswordRequest;
use Illuminate\Http\JsonResponse;

class ProfilePasswordController extends Controller
{
    public function update(ChangeProfilePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->forceFill([
            'password' => $data['new_password'],
        ])->save();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}
