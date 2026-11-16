<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
}
