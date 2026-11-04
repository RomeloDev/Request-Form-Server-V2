<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\ListRequestApproverCandidatesRequest;
use App\Http\Requests\RequestForms\StoreStockRequisitionRequest;
use App\Http\Resources\RequestApproverCandidateResource;
use App\Http\Resources\RequestFormResource;
use App\Services\RequestApproverCandidateService;
use App\Services\StockRequisitionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RequestFormController extends Controller
{
    /**
     * List approvers eligible for the authenticated requester's branch.
     */
    public function approverCandidates(
        ListRequestApproverCandidatesRequest $request,
        RequestApproverCandidateService $service,
    ): JsonResponse {
        /*
        * auth:sanctum guarantees that this is an authenticated User.
        *
        * The Form Request also checks RequestFormPolicy::create().
        */
        $requester = $request->user();

        $candidates = $service->paginateFor(
            requester: $requester,
            filters: $request->validated(),
        );

        /*
        * A paginated Resource collection automatically creates:
        * - data
        * - links
        * - meta
        */
        return RequestApproverCandidateResource::collection(
            $candidates
        )
            ->additional([
                'message' => 'Eligible request approvers retrieved successfully.',
            ])
            ->response();
    }

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
