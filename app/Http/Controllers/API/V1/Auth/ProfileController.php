<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Load the Spatie assignment and preserve the existing API contract.
        $user->loadMissing('roles');
        $user->setAttribute('role', $user->getRoleNames()->first());

        // The frontend needs one role string, not the relationship array.
        $user->unsetRelation('roles');

        return response()->json([
            'status' => true,
            'data' => $user,
        ]);
    }
}
