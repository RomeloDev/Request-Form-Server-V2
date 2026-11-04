<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\StoreStockRequisitionRequest;
use App\Http\Resources\RequestFormResource;
use App\Services\StockRequisitionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RequestFormController extends Controller
{
    public function storeStockRequisition(
        StoreStockRequisitionRequest $request,
        StockRequisitionService $service
    ): JsonResponse {
        $requester = $request->user();

        $requestForm = $service->create(
            requester: $requester,
            data: $request->validated(),
        );

        $response = (new RequestFormResource($requestForm))->additional([
            'message' => 'Stock Requisition created successfully.',
        ])->response();

        $response->setStatusCode(Response::HTTP_CREATED);

        return $response;
    }
}
