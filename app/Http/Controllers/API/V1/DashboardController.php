<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowDashboardRequest;
use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Return the authenticated user's dashboard.
     *
     * The identity is read from the authenticated session only. The request
     * is authorized by ShowDashboardRequest before this method runs.
     */
    public function show(
        ShowDashboardRequest $request,
        DashboardService $service,
    ): JsonResponse {
        $dashboard = $service->forUser($request->user());

        return (new DashboardResource($dashboard))
            ->additional([
                'message' => 'Dashboard retrieved successfully.',
            ])
            ->response();
    }
}
