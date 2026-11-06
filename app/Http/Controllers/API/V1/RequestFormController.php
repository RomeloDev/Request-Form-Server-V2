<?php

namespace App\Http\Controllers\API\V1;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\AccessRequestAttachmentRequest;
use App\Http\Requests\RequestForms\CancelMyRequestRequest;
use App\Http\Requests\RequestForms\ListMyRequestsRequest;
use App\Http\Requests\RequestForms\ListRequestApproverCandidatesRequest;
use App\Http\Requests\RequestForms\ShowMyRequestRequest;
use App\Http\Requests\RequestForms\StoreStockRequisitionRequest;
use App\Http\Resources\RequestApproverCandidateResource;
use App\Http\Resources\RequestFormResource;
use App\Models\RequestAttachment;
use App\Models\RequestForm;
use App\Services\RequestApproverCandidateService;
use App\Services\RequestCancellationService;
use App\Services\StockRequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestFormController extends Controller
{
    private const PREVIEWABLE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    private const DOWNLOADABLE_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function index(ListMyRequestsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? 'ALL';

        $requesterId = $request->user()->getKey();
        $ownedRequests = RequestForm::query()->where('requester_id', $requesterId);

        $summaryResult = (clone $ownedRequests)
            ->selectRaw(
                '
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS ongoing,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS disapproved,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS canceled
                ',
                [
                    RequestStatus::Pending->value,
                    RequestStatus::Ongoing->value,
                    RequestStatus::Completed->value,
                    RequestStatus::Disapproved->value,
                    RequestStatus::Canceled->value,
                ],
            )
            ->first();

        $summary = [
            'total' => (int) ($summaryResult?->total ?? 0),
            'pending' => (int) ($summaryResult?->pending ?? 0),
            'ongoing' => (int) ($summaryResult?->ongoing ?? 0),
            'completed' => (int) ($summaryResult?->completed ?? 0),
            'disapproved' => (int) ($summaryResult?->disapproved ?? 0),
            'canceled' => (int) ($summaryResult?->canceled ?? 0),
        ];

        $requests = (clone $ownedRequests)
            ->with('branch')
            ->when(
                $search !== '',
                fn ($query) => $query->where(
                    fn ($searchQuery) => $searchQuery
                        ->where('request_code', 'like', "%{$search}%")
                        ->orWhere('form_type', 'like', "%{$search}%")
                        ->orWhere('kind_of_request', 'like', "%{$search}%")
                ),
            )->when(
                $status !== 'ALL',
                fn ($query) => $query->where('status', $status),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return RequestFormResource::collection($requests)
            ->additional([
                'message' => 'Your requests retrieved successfully.',
                'summary' => $summary,
            ])->response();
    }

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

    public function show(ShowMyRequestRequest $request, RequestForm $requestForm): JsonResponse
    {
        $requestForm->load([
            'requester',
            'branch',
            'items',
            'attachments',
            'approvalSteps.approver',
        ]);

        return (new RequestFormResource($requestForm))
            ->additional([
                'message' => 'Request retrieved successfully.',
            ])
            ->response();
    }

    public function previewAttachment(AccessRequestAttachmentRequest $request, RequestForm $requestForm, RequestAttachment $attachment): StreamedResponse
    {
        if (! in_array($attachment->mime_type, self::PREVIEWABLE_MIME_TYPES, true)) {
            abort(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'This attachment type cannot be previewed.'
            );
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            abort(
                Response::HTTP_NOT_FOUND,
                'Attachment file not found.'
            );
        }

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    public function downloadAttachment(
        AccessRequestAttachmentRequest $request,
        RequestForm $requestForm,
        RequestAttachment $attachment,
    ): StreamedResponse {
        if (! in_array(
            $attachment->mime_type,
            self::DOWNLOADABLE_MIME_TYPES,
            true,
        )) {
            abort(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'This attachment type cannot be downloaded here.',
            );
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            abort(
                Response::HTTP_NOT_FOUND,
                'Attachment file not found.',
            );
        }

        return $disk->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function cancel(
        CancelMyRequestRequest $request,
        RequestForm $requestForm,
        RequestCancellationService $service,
    ): JsonResponse {
        $data = $request->validated();

        $canceledRequest = $service->cancel(
            requestForm: $requestForm,
            reason: $data['cancellation_reason'],
        );

        return (new RequestFormResource($canceledRequest))
            ->additional([
                'message' => 'Request canceled successfully.',
            ])
            ->response();
    }
}
