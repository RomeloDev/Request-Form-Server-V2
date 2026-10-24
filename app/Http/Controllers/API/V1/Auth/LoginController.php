<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Sign in is temporary unavailable.',
        ], 501);
    }
}
