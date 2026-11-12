<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\Supplier;
use App\Models\User;
use App\Support\RequestCurrencies;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class PurchaseOrderService
{
    public function __construct(
        private readonly RequestApprovalChainResolver $approvalChainResolver,
        private readonly RequestSubmissionWriter $writer,
    ) {}

    /**
     * Create one Purchase Order and all of its related records.
     *
     * @param  array{
     *     kind_of_request: string,
     *     form_data: array{supplier: string, address: string},
     *     items: list<array{
     *         quantity: int|string,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *     attachments?: list<UploadedFile>,
     *     noted_by_ids?: list<int>,
     *     approved_by_ids: list<int>,
     * }  $data
     */
    public function create(User $requester, array $data): RequestForm
    {
        $branch = $this->writer->findRequesterBranch($requester);

        /*
         * Normalize the supplier identity and address once.
         *
         * The trimmed values are reused for both the request form and the
         * supplier record, so whitespace can never split one supplier into
         * two different database rows.
         */
        $supplierName = trim((string) $data['form_data']['supplier']);
        $supplierAddress = trim((string) $data['form_data']['address']);

        /** @var list<UploadedFile> $attachments */
        $attachments = $data['attachments'] ?? [];

        /*
         * Keep track of every physical file stored during this operation.
         *
         * A database rollback does not delete files, so the catch block
         * uses this list to clean them up.
         *
         * @var list<string> $storedPaths
         */
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $requester,
                $branch,
                $data,
                $attachments,
                $supplierName,
                $supplierAddress,
                &$storedPaths
            ): RequestForm {
                /*
                 * Recheck approver eligibility at submission time.
                 *
                 * The candidate list shown by the frontend may be stale because an
                 * administrator could change a role or assignment before submission.
                 */
                $approvalPlan = $this->approvalChainResolver->resolve(
                    requester: $requester,
                    notedByIds: $data['noted_by_ids'] ?? [],
                    approvedByIds: $data['approved_by_ids'],
                );

                $requestForm = $this->writer->createRequestForm(
                    requester: $requester,
                    branch: $branch,
                    formType: RequestFormType::PurchaseOrder,
                    codePrefix: 'PO',
                    kindOfRequest: $data['kind_of_request'],
                    formData: [
                        'supplier' => $supplierName,
                        'address' => $supplierAddress,
                    ],
                    currency: RequestCurrencies::PHP,
                );

                $this->writer->storeApprovalSteps(
                    requestForm: $requestForm,
                    approvalPlan: $approvalPlan,
                );

                $grandTotal = $this->writer->storeItems(
                    requestForm: $requestForm,
                    items: $data['items'],
                );

                $requestForm->grand_total = $grandTotal;
                $requestForm->save();

                foreach ($attachments as $file) {
                    $this->writer->storeAttachment(
                        requestForm: $requestForm,
                        file: $file,
                        storedPaths: $storedPaths,
                    );
                }

                /*
                 * Persist the supplier atomically with the request.
                 *
                 * The trimmed name is the identity, and the trimmed address is
                 * the value written for an existing supplier.
                 */
                Supplier::updateOrCreate(
                    ['name' => $supplierName],
                    ['address' => $supplierAddress],
                );

                return $requestForm->load([
                    'requester',
                    'branch',
                    'items',
                    'attachments',
                    'approvalSteps.approver',
                ]);
            });
        } catch (Throwable $exception) {
            /*
             * Database transactions do not cover filesystem operations.
             * Remove any files written before the failure.
             */
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }

            throw $exception;
        }
    }

    /**
     * Update and resubmit an existing Purchase Order.
     *
     * @param  array{
     *     updated_at: string,
     *     kind_of_request: string,
     *     form_data: array{supplier: string, address: string},
     *     noted_by_ids?: list<int>,
     *     approved_by_ids: list<int>,
     *     items: list<array{
     *         quantity: int|string,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *     attachments?: list<UploadedFile>,
     *     removed_attachment_ids?: list<int>,
     * }  $data
     */
    public function update(
        RequestForm $requestForm,
        User $requester,
        array $data,
    ): RequestForm {
        $supplierName = trim((string) $data['form_data']['supplier']);
        $supplierAddress = trim((string) $data['form_data']['address']);

        /** @var list<UploadedFile> $newAttachments */
        $newAttachments = $data['attachments'] ?? [];

        $removedAttachmentIds = array_map(
            'intval',
            $data['removed_attachment_ids'] ?? [],
        );

        /** @var list<string> $storedPaths */
        $storedPaths = [];

        /** @var list<array{disk: string, path: string}> $removedFiles */
        $removedFiles = [];

        try {
            $updatedRequest = DB::transaction(
                function () use (
                    $requestForm,
                    $requester,
                    $data,
                    $supplierName,
                    $supplierAddress,
                    $newAttachments,
                    $removedAttachmentIds,
                    &$storedPaths,
                    &$removedFiles,
                ): RequestForm {
                    $lockedRequest = RequestForm::query()
                        ->lockForUpdate()
                        ->findOrFail($requestForm->getKey());

                    if ($lockedRequest->form_type !== RequestFormType::PurchaseOrder) {
                        throw new ConflictHttpException(
                            'Only Purchase Order requests can be edited.',
                        );
                    }

                    $existingAttachments = $lockedRequest
                        ->attachments()
                        ->lockForUpdate()
                        ->get();

                    $attachmentsToRemove = $existingAttachments
                        ->whereIn('id', $removedAttachmentIds);

                    if ($attachmentsToRemove->count() !== count($removedAttachmentIds)) {
                        throw ValidationException::withMessages([
                            'removed_attachment_ids' => 'One or more selected attachments no longer belong to this request.',
                        ]);
                    }

                    $finalAttachmentCount = $existingAttachments->count()
                        - $attachmentsToRemove->count()
                        + count($newAttachments);

                    if ($finalAttachmentCount > 10) {
                        throw ValidationException::withMessages([
                            'attachments' => 'A request can have no more than 10 attachments.',
                        ]);
                    }

                    if (! in_array(
                        $lockedRequest->status,
                        [RequestStatus::Pending, RequestStatus::Disapproved],
                        true,
                    )) {
                        throw new ConflictHttpException(
                            'Only pending or disapproved requests can be edited.',
                        );
                    }

                    $expectedUpdatedAt = CarbonImmutable::parse($data['updated_at']);

                    if (
                        $lockedRequest->updated_at === null
                        || ! $lockedRequest->updated_at->equalTo($expectedUpdatedAt)
                    ) {
                        throw new ConflictHttpException(
                            'This request has changed since you opened it. Refresh and try again.',
                        );
                    }

                    $existingSteps = $lockedRequest
                        ->approvalSteps()
                        ->lockForUpdate()
                        ->get();

                    if ($lockedRequest->status === RequestStatus::Pending) {
                        $hasApprovalActivity = $existingSteps->contains(
                            fn (RequestApprovalStep $step): bool => $step->status !== ApprovalStepStatus::Pending
                                || $step->acted_at !== null,
                        );

                        if ($hasApprovalActivity) {
                            throw new ConflictHttpException(
                                'A pending request cannot be edited after approval activity has started.',
                            );
                        }
                    }

                    $approvalPlan = $this->approvalChainResolver->resolve(
                        requester: $requester,
                        notedByIds: $data['noted_by_ids'] ?? [],
                        approvedByIds: $data['approved_by_ids'],
                    );

                    $lockedRequest->kind_of_request = $data['kind_of_request'];
                    $lockedRequest->form_data = [
                        'supplier' => $supplierName,
                        'address' => $supplierAddress,
                    ];

                    $lockedRequest->items()->delete();
                    $grandTotal = $this->writer->storeItems(
                        requestForm: $lockedRequest,
                        items: $data['items'],
                    );

                    $lockedRequest->approvalSteps()->delete();
                    $this->writer->storeApprovalSteps(
                        requestForm: $lockedRequest,
                        approvalPlan: $approvalPlan,
                    );

                    $lockedRequest->grand_total = $grandTotal;
                    $lockedRequest->status = RequestStatus::Pending;
                    $lockedRequest->save();

                    foreach ($newAttachments as $file) {
                        $this->writer->storeAttachment(
                            requestForm: $lockedRequest,
                            file: $file,
                            storedPaths: $storedPaths,
                        );
                    }

                    $removedFiles = $attachmentsToRemove
                        ->map(fn ($attachment): array => [
                            'disk' => $attachment->disk,
                            'path' => $attachment->path,
                        ])
                        ->values()
                        ->all();

                    if ($removedAttachmentIds !== []) {
                        $lockedRequest
                            ->attachments()
                            ->whereKey($removedAttachmentIds)
                            ->delete();
                    }

                    Supplier::updateOrCreate(
                        ['name' => $supplierName],
                        ['address' => $supplierAddress],
                    );

                    return $lockedRequest->refresh()->load([
                        'requester',
                        'branch',
                        'items',
                        'attachments',
                        'approvalSteps.approver',
                    ]);
                },
            );
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }

            throw $exception;
        }

        foreach ($removedFiles as $removedFile) {
            Storage::disk($removedFile['disk'])->delete($removedFile['path']);
        }

        return $updatedRequest;
    }
}
