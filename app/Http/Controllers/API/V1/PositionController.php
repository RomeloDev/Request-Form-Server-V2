<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    public function index(): JsonResponse
    {
        $positions = Position::orderBy(
            'label', 'asc'
        )->get(['id','label', 'value']);

        if ($positions->isEmpty()) {
            return response()->json([
                "message" => "No position found",
            ], 404);
        }

        return response()->json(['position' => $positions]);
    }
}
