<?php

namespace App\Http\Controllers\API\V1;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestForms\AccessApprovalAttachmentRequest;
use App\Http\Requests\RequestForms\AccessRequestAttachmentRequest;
use App\Http\Requests\RequestForms\CancelMyRequestRequest;
use App\Http\Requests\RequestForms\DecideAssignedRequestRequest;
use App\Http\Requests\RequestForms\DeleteMyRequestRequest;
use App\Http\Requests\RequestForms\DestroyRequestShareRequest;
use App\Http\Requests\RequestForms\ListAssignedRequestsRequest;
use App\Http\Requests\RequestForms\ListMyRequestsRequest;
use App\Http\Requests\RequestForms\ListRequestApproverCandidatesRequest;
use App\Http\Requests\RequestForms\ListRequestShareCandidatesRequest;
use App\Http\Requests\RequestForms\ListRequestSharesRequest;
use App\Http\Requests\RequestForms\ListSharedRequestsRequest;
use App\Http\Requests\RequestForms\ShowAssignedRequestRequest;
use App\Http\Requests\RequestForms\ShowMyRequestRequest;
use App\Http\Requests\RequestForms\ShowPrintableRequestRequest;
use App\Http\Requests\RequestForms\ShowSharedRequestRequest;
use App\Http\Requests\RequestForms\StoreCashDisbursementRequest;
use App\Http\Requests\RequestForms\StoreCheckIssuanceRequest;
use App\Http\Requests\RequestForms\StorePurchaseOrderRequest;
use App\Http\Requests\RequestForms\StoreRequestShareRequest;
use App\Http\Requests\RequestForms\StoreStockRequisitionRequest;
use App\Http\Requests\RequestForms\UpdateCashDisbursementRequest;
use App\Http\Requests\RequestForms\UpdateCheckIssuanceRequest;
use App\Http\Requests\RequestForms\UpdatePurchaseOrderRequest;
use App\Http\Requests\RequestForms\UpdateStockRequisitionRequest;
use App\Http\Requests\RequestForms\ViewPrintableRequestSignatureRequest;
use App\Http\Resources\AssignedRequestResource;
use App\Http\Resources\PrintableRequestResource;
use App\Http\Resources\RequestApproverCandidateResource;
use App\Http\Resources\RequestFormResource;
use App\Http\Resources\RequestShareCandidateResource;
use App\Http\Resources\RequestShareRecipientResource;
use App\Http\Resources\SharedRequestResource;
use App\Models\RequestApprovalAttachment;
use App\Models\RequestApprovalStep;
use App\Models\RequestAttachment;
use App\Models\RequestForm;
use App\Models\User;
use App\Services\CashDisbursementService;
use App\Services\CheckIssuanceService;
use App\Services\PurchaseOrderService;
use App\Services\RequestApproverCandidateService;
use App\Services\RequestCancellationService;
use App\Services\RequestDecisionService;
use App\Services\RequestDeletionService;
use App\Services\RequestShareCandidateService;
use App\Services\RequestSharingService;
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

    private const APPROVED_ATTACHMENT_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/vnd.microsoft.icon',
        'image/webp',
        'image/x-icon',
    ];

    public function assignedRequests(
        ListAssignedRequestsRequest $request
    ): JsonResponse {
        $filters = $request->validated();

        $approverId = $request->user()->getKey();
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? 'ALL';
        $assignmentStatus = $filters['assignment_status'] ?? 'ALL';

        $assignments = RequestApprovalStep::query()
            /*
            * Never accept the approver identity from the browser.
            */
            ->where('approver_id', $approverId)

            /*
            * Canceled requests do not appear in Process Requests.
            */
            ->whereHas(
                'requestForm',
                fn ($query) => $query
                    ->where('status', '!=', RequestStatus::Canceled->value)
                    ->when(
                        $status !== 'ALL',
                        fn ($statusQuery) => $statusQuery->where(
                            'status',
                            $status,
                        ),
                    ),
            )

            /*
            * Include:
            * 1. This approver's completed/disapproved history.
            * 2. A Pending step only when every earlier level is Approved.
            */
            ->where(function ($query): void {
                $query
                    ->where(
                        'status',
                        '!=',
                        ApprovalStepStatus::Pending->value,
                    )
                    ->orWhere(function ($pendingQuery): void {
                        $pendingQuery
                            ->where(
                                'status',
                                ApprovalStepStatus::Pending->value,
                            )
                            ->whereHas(
                                'requestForm',
                                fn ($requestQuery) => $requestQuery->whereIn(
                                    'status',
                                    [
                                        RequestStatus::Pending->value,
                                        RequestStatus::Ongoing->value,
                                    ],
                                ),
                            )
                            ->whereNotExists(function ($earlierSteps): void {
                                $earlierSteps
                                    ->selectRaw('1')
                                    ->from(
                                        'request_approval_steps as earlier_steps'
                                    )
                                    ->whereColumn(
                                        'earlier_steps.request_form_id',
                                        'request_approval_steps.request_form_id',
                                    )
                                    ->whereColumn(
                                        'earlier_steps.level',
                                        '<',
                                        'request_approval_steps.level',
                                    )
                                    ->where(
                                        'earlier_steps.status',
                                        '!=',
                                        ApprovalStepStatus::Approved->value,
                                    );
                            });
                    });
            })

            ->when(
                $assignmentStatus !== 'ALL',
                fn ($query) => $query->where(
                    'status',
                    $assignmentStatus,
                ),
            )

            ->when(
                $search !== '',
                fn ($query) => $query->whereHas(
                    'requestForm',
                    fn ($requestQuery) => $requestQuery->where(
                        function ($searchQuery) use ($search): void {
                            $searchQuery
                                ->where(
                                    'request_code',
                                    'like',
                                    "%{$search}%",
                                )
                                ->orWhere(
                                    'form_type',
                                    'like',
                                    "%{$search}%",
                                )
                                ->orWhere(
                                    'kind_of_request',
                                    'like',
                                    "%{$search}%",
                                )
                                ->orWhereHas(
                                    'requester',
                                    fn ($requesterQuery) => $requesterQuery
                                        ->where(
                                            'firstName',
                                            'like',
                                            "%{$search}%",
                                        )
                                        ->orWhere(
                                            'lastName',
                                            'like',
                                            "%{$search}%",
                                        ),
                                )
                                ->orWhereHas(
                                    'branch',
                                    fn ($branchQuery) => $branchQuery
                                        ->where(
                                            'branch_code',
                                            'like',
                                            "%{$search}%",
                                        )
                                        ->orWhere(
                                            'branch_name',
                                            'like',
                                            "%{$search}%",
                                        ),
                                );
                        },
                    ),
                ),
            )

            /*
            * Prevent an N+1 query for each table row.
            */
            ->with([
                'requestForm.requester',
                'requestForm.branch',
            ])
            ->latest('request_approval_steps.created_at')
            ->latest('request_approval_steps.id')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return AssignedRequestResource::collection($assignments)
            ->additional([
                'message' => 'Assigned requests retrieved successfully.',
            ])
            ->response();
    }

    public function showAssignedRequest(
        ShowAssignedRequestRequest $request,
        RequestForm $requestForm,
    ): JsonResponse {
        $requestForm->load([
            'requester',
            'branch',
            'items',
            'attachments',
            'approvalSteps.approver',
            'approvalSteps.attachments',
        ]);

        return (new RequestFormResource($requestForm))
            ->additional([
                'message' => 'Assigned request retrieved successfully.',
            ])
            ->response();
    }

    public function decideAssignedRequest(
        DecideAssignedRequestRequest $request,
        RequestForm $requestForm,
        RequestDecisionService $service,
    ): JsonResponse {
        $data = $request->validated();

        $updatedRequest = $service->decide(
            requestForm: $requestForm,
            approver: $request->user(),
            action: $data['action'],
            comment: $data['comment'] ?? null,
            attachments: $data['attachments'] ?? [],
        );

        $message = $data['action'] === 'approve'
            ? 'Request approved successfully.'
            : 'Request disapproved successfully.';

        return (new RequestFormResource($updatedRequest))
            ->additional([
                'message' => $message,
            ])
            ->response();
    }

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

        /*
         * withCount() aggregates every page row's share count in the same
         * SQL as the list query itself, instead of counting per row later.
         */
        $requests = (clone $ownedRequests)
            ->with('branch')
            ->withCount('sharedRecipients')
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
     * List the requests shared with the authenticated recipient.
     *
     * The query is scoped exclusively through the authenticated user's
     * sharedRequests() relation; no recipient ID is accepted from the client.
     */
    public function sharedRequests(
        ListSharedRequestsRequest $request
    ): JsonResponse {
        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $sharedRequests = $request->user()
            ->sharedRequests()
            ->with([
                'requester',
                'branch',
            ])
            ->when(
                $search !== '',
                fn ($query) => $query->where(
                    function ($searchQuery) use ($search): void {
                        $searchQuery
                            ->where(
                                'request_code',
                                'like',
                                "%{$search}%",
                            )
                            ->orWhere(
                                'form_type',
                                'like',
                                "%{$search}%",
                            )
                            ->orWhere(
                                'kind_of_request',
                                'like',
                                "%{$search}%",
                            )
                            ->orWhereHas(
                                'requester',
                                fn ($requesterQuery) => $requesterQuery
                                    ->where(
                                        'firstName',
                                        'like',
                                        "%{$search}%",
                                    )
                                    ->orWhere(
                                        'lastName',
                                        'like',
                                        "%{$search}%",
                                    ),
                            )
                            ->orWhereHas(
                                'branch',
                                fn ($branchQuery) => $branchQuery
                                    ->where(
                                        'branch_code',
                                        'like',
                                        "%{$search}%",
                                    )
                                    ->orWhere(
                                        'branch_name',
                                        'like',
                                        "%{$search}%",
                                    ),
                            );
                    },
                ),
            )
            ->orderBy('request_shares.created_at', 'desc')
            ->orderBy('request_forms.id', 'desc')
            ->paginate($filters['per_page'] ?? 10)
            ->withQueryString();

        return SharedRequestResource::collection(
            $sharedRequests
        )
            ->additional([
                'message' => 'Shared requests retrieved successfully.',
            ])
            ->response();
    }

    /**
     * Show one request shared with the authenticated recipient.
     *
     * Authorization is relationship-based only. Loading and returning the
     * details performs no writes, so request, share, and approval data stay
     * untouched.
     */
    public function showSharedRequest(
        ShowSharedRequestRequest $request,
        RequestForm $requestForm,
    ): JsonResponse {
        $requestForm->load([
            'requester',
            'branch',
            'items',
            'attachments',
            'approvalSteps.approver',
            'approvalSteps.attachments',
        ]);

        return (new RequestFormResource($requestForm))
            ->additional([
                'message' => 'Shared request retrieved successfully.',
            ])
            ->response();
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

    /**
     * List verified, non-admin users this completed request can be
     * shared with.
     */
    public function shareCandidates(
        ListRequestShareCandidatesRequest $request,
        RequestForm $requestForm,
        RequestShareCandidateService $service,
    ): JsonResponse {
        /*
         * Sharing is only meaningful after the request is fully completed.
         * This is a business-state conflict, not an authorization failure.
         */
        if ($requestForm->status !== RequestStatus::Completed) {
            return response()->json([
                'message' => 'Only completed requests can be shared.',
            ], Response::HTTP_CONFLICT);
        }

        $candidates = $service->paginateFor(
            requestForm: $requestForm,
            filters: $request->validated(),
        );

        return RequestShareCandidateResource::collection(
            $candidates
        )
            ->additional([
                'message' => 'Share candidates retrieved successfully.',
            ])
            ->response();
    }

    /**
     * List the users this completed request has been shared with.
     */
    public function indexShares(
        ListRequestSharesRequest $request,
        RequestForm $requestForm,
        RequestSharingService $service,
    ): JsonResponse {
        if ($requestForm->status !== RequestStatus::Completed) {
            return response()->json([
                'message' => 'Only completed requests can be shared.',
            ], Response::HTTP_CONFLICT);
        }

        $recipients = $service->paginateRecipientsFor(
            requestForm: $requestForm,
            filters: $request->validated(),
        );

        return RequestShareRecipientResource::collection(
            $recipients
        )
            ->additional([
                'message' => 'Shared recipients retrieved successfully.',
            ])
            ->response();
    }

    /**
     * Share a completed request with the selected eligible recipients.
     */
    public function storeShare(
        StoreRequestShareRequest $request,
        RequestForm $requestForm,
        RequestSharingService $service,
    ): JsonResponse {
        if ($requestForm->status !== RequestStatus::Completed) {
            return response()->json([
                'message' => 'Only completed requests can be shared.',
            ], Response::HTTP_CONFLICT);
        }

        $sharedCount = $service->share(
            requestForm: $requestForm,
            userIds: $request->validated()['user_ids'],
        );

        return response()->json([
            'message' => 'Request shared successfully.',
            'data' => [
                'request_id' => $requestForm->id,
                'shared_count' => $sharedCount,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Revoke one recipient from a completed request's share list.
     */
    public function destroyShare(
        DestroyRequestShareRequest $request,
        RequestForm $requestForm,
        string $recipient,
        RequestSharingService $service,
    ): JsonResponse {
        if ($requestForm->status !== RequestStatus::Completed) {
            return response()->json([
                'message' => 'Only completed requests can be shared.',
            ], Response::HTTP_CONFLICT);
        }

        $recipientId = (int) $recipient;

        $service->revoke(
            requestForm: $requestForm,
            recipientId: $recipientId,
        );

        return response()->json([
            'message' => 'Shared recipient removed successfully.',
            'data' => [
                'request_id' => $requestForm->id,
                'recipient_id' => $recipientId,
            ],
        ]);
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

    public function storePurchaseOrder(
        StorePurchaseOrderRequest $request,
        PurchaseOrderService $service
    ): JsonResponse {
        $requester = $request->user();

        $requestForm = $service->create(
            requester: $requester,
            data: $request->validated(),
        );

        $response = (new RequestFormResource($requestForm))->additional([
            'message' => 'Purchase Order created successfully.',
        ])->response();

        $response->setStatusCode(Response::HTTP_CREATED);

        return $response;
    }

    public function storeCashDisbursement(
        StoreCashDisbursementRequest $request,
        CashDisbursementService $service
    ): JsonResponse {
        $requestForm = $service->create(
            requester: $request->user(),
            data: $request->validated(),
        );

        $response = (new RequestFormResource($requestForm))->additional([
            'message' => 'Cash Disbursement created successfully.',
        ])->response();

        $response->setStatusCode(Response::HTTP_CREATED);

        return $response;
    }

    public function storeCheckIssuance(
        StoreCheckIssuanceRequest $request,
        CheckIssuanceService $service,
    ): JsonResponse {
        $requestForm = $service->create(
            requester: $request->user(),
            data: $request->validated(),
        );

        $response = (new RequestFormResource($requestForm))
            ->additional([
                'message' => 'Check Issuance created successfully.',
            ])
            ->response();

        $response->setStatusCode(
            Response::HTTP_CREATED,
        );

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
            'approvalSteps.attachments',
        ]);

        return (new RequestFormResource($requestForm))
            ->additional([
                'message' => 'Request retrieved successfully.',
            ])
            ->response();
    }

    public function showPrintable(
        ShowPrintableRequestRequest $request,
        RequestForm $requestForm,
    ): JsonResponse {
        if ($requestForm->status === RequestStatus::Canceled) {
            return response()->json([
                'message' => 'Canceled requests cannot be printed.',
            ], Response::HTTP_CONFLICT);
        }

        $requestForm->load([
            'requester',
            'branch',
            'items',
            'approvalSteps.approver',
        ]);

        return (new PrintableRequestResource($requestForm))
            ->additional([
                'message' => 'Printable request retrieved successfully.',
            ])
            ->response();
    }

    public function viewPrintableSignature(
        ViewPrintableRequestSignatureRequest $request,
        RequestForm $requestForm,
        User $signatureOwner,
    ): StreamedResponse|JsonResponse {
        if ($requestForm->status === RequestStatus::Canceled) {
            return response()->json([
                'message' => 'Canceled requests cannot be printed.',
            ], Response::HTTP_CONFLICT);
        }

        $isRequester = (int) $requestForm->requester_id === (int) $signatureOwner->getKey();

        $isApprovedApprover = $requestForm->approvalSteps()
            ->where('approver_id', $signatureOwner->getKey())
            ->where(
                'status',
                ApprovalStepStatus::Approved->value,
            )
            ->exists();

        if (! $isRequester && ! $isApprovedApprover) {
            abort(
                Response::HTTP_NOT_FOUND,
                'Signature not available.',
            );
        }

        $signaturePath = $signatureOwner->signature;
        $disk = Storage::disk('public');

        if (
            ! is_string($signaturePath)
            || $signaturePath === ''
            || ! $disk->exists($signaturePath)
        ) {
            abort(
                Response::HTTP_NOT_FOUND,
                'Signature not available.',
            );
        }

        return $disk->response(
            $signaturePath,
            "signature-{$signatureOwner->id}.png",
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
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

    public function previewApprovalAttachment(
        AccessApprovalAttachmentRequest $request,
        RequestForm $requestForm,
        RequestApprovalStep $approvalStep,
        RequestApprovalAttachment $approvalAttachment,
    ): StreamedResponse {
        $this->ensureApprovedAttachmentIsAvailable($approvalAttachment);

        return Storage::disk($approvalAttachment->disk)->response(
            $approvalAttachment->path,
            $approvalAttachment->original_name,
            [
                'Content-Type' => $approvalAttachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    public function downloadApprovalAttachment(
        AccessApprovalAttachmentRequest $request,
        RequestForm $requestForm,
        RequestApprovalStep $approvalStep,
        RequestApprovalAttachment $approvalAttachment,
    ): StreamedResponse {
        $this->ensureApprovedAttachmentIsAvailable($approvalAttachment);

        return Storage::disk($approvalAttachment->disk)->download(
            $approvalAttachment->path,
            $approvalAttachment->original_name,
            [
                'Content-Type' => $approvalAttachment->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function ensureApprovedAttachmentIsAvailable(
        RequestApprovalAttachment $attachment,
    ): void {
        if (! in_array($attachment->mime_type, self::APPROVED_ATTACHMENT_MIME_TYPES, true)) {
            abort(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'This approved attachment type is unavailable.',
            );
        }

        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(
                Response::HTTP_NOT_FOUND,
                'Approved attachment file not found.',
            );
        }
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

    public function updateStockRequisition(
        UpdateStockRequisitionRequest $request,
        RequestForm $requestForm,
        StockRequisitionService $service,
    ): JsonResponse {
        $updatedRequest = $service->update(
            requestForm: $requestForm,
            requester: $request->user(),
            data: $request->validated(),
        );

        return (new RequestFormResource($updatedRequest))
            ->additional([
                'message' => 'Stock Requisition updated successfully.',
            ])
            ->response();
    }

    public function updatePurchaseOrder(
        UpdatePurchaseOrderRequest $request,
        RequestForm $requestForm,
        PurchaseOrderService $service,
    ): JsonResponse {
        $updatedRequest = $service->update(
            requestForm: $requestForm,
            requester: $request->user(),
            data: $request->validated(),
        );

        return (new RequestFormResource($updatedRequest))
            ->additional([
                'message' => 'Purchase Order updated successfully.',
            ])
            ->response();
    }

    public function updateCashDisbursement(
        UpdateCashDisbursementRequest $request,
        RequestForm $requestForm,
        CashDisbursementService $service,
    ): JsonResponse {
        $updatedRequest = $service->update(
            requestForm: $requestForm,
            requester: $request->user(),
            data: $request->validated(),
        );

        return (new RequestFormResource($updatedRequest))
            ->additional([
                'message' => 'Cash Disbursement updated successfully.',
            ])
            ->response();
    }

    public function updateCheckIssuance(
        UpdateCheckIssuanceRequest $request,
        RequestForm $requestForm,
        CheckIssuanceService $service,
    ): JsonResponse {
        $updatedRequest = $service->update(
            requestForm: $requestForm,
            requester: $request->user(),
            data: $request->validated(),
        );

        return (new RequestFormResource($updatedRequest))
            ->additional([
                'message' => 'Check Issuance updated successfully.',
            ])
            ->response();
    }

    public function destroy(
        DeleteMyRequestRequest $request,
        RequestForm $requestForm,
        RequestDeletionService $service,
    ): JsonResponse {
        $deletedRequestId = $service->delete($requestForm);

        return response()->json([
            'message' => 'Request deleted successfully.',
            'data' => [
                'id' => $deletedRequestId,
            ],
        ]);
    }
}
