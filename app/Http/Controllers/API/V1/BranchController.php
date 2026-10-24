<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function viewBranch(): JsonResponse
    {
        $branches = Branch::all();

        $payload = [
            'message' => 'Branches retrieved successfully',
            'data' => $branches,
            'hasBranches' => $branches,
        ];

        return response()->json($payload, 200);
    }
}
