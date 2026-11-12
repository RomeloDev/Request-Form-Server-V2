<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use App\Support\RequestCurrencies;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class StockRequisitionService
{
    public function __construct(
        private readonly RequestApprovalChainResolver $approvalChainResolver,
        private readonly RequestSubmissionWriter $writer,
    ) {}

    /**
     * Create one Stock Requisition and all of its related records.
     *
     * @param  array{
     *     kind_of_request?: string|null,
     *     form_data: array{purpose: string},
     *     items: list<array{
     *         quantity: int|string,
     *         unit?: string|null,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *     attachments?: list<UploadedFile>,
     *      noted_by_ids?: list<int>,
     *     approved_by_ids: list<int>,
     * }  $data
     */
    public function create(User $requester, array $data): RequestForm
    {
        $branch = $this->writer->findRequesterBranch($requester);

        /** @var list<UploadedFile> $attachments */
        $attachments = $data['attachments'] ?? [];

        /*
         * Keep track of every physical file stored during this operation.
         *
         * A database rollback does not delete files, so the catch block
         * uses this list to clean them up.
         */
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $requester,
                $branch,
                $data,
                $attachments,
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
                    formType: RequestFormType::StockRequisition,
                    codePrefix: 'SRL',
                    kindOfRequest: $data['kind_of_request'] ?? null,
                    formData: [
                        'purpose' => $data['form_data']['purpose'],
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
     * Update and resubmit an existing Stock Requisition.
     *
     * Existing attachments are intentionally retained unchanged.
     *
     * @param  array{
     *     updated_at: string,
     *     kind_of_request?: string|null,
     *     form_data: array{purpose: string},
     *     noted_by_ids?: list<int>,
     *     approved_by_ids: list<int>,
     *     items: list<array{
     *         quantity: int|string,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *      attachments?: list<UploadedFile>,
     *      removed_attachment_ids?: list<int>,
     * }  $data
     */
    public function update(
        RequestForm $requestForm,
        User $requester,
        array $data,
    ): RequestForm {
        /** @var list<UploadedFile> $newAttachments */
        $newAttachments = $data['attachments'] ?? [];

        $removedAttachmentIds = array_map(
            'intval',
            $data['removed_attachment_ids'] ?? [],
        );

        /*
        * New files must be deleted if the database transaction fails.
        */
        $storedPaths = [];

        /*
        * Old files must only be physically deleted after the database commits.
        *
        * @var list<array{disk: string, path: string}>
        */
        $removedFiles = [];

        try {
            $updatedRequest = DB::transaction(
                function () use (
                    $requestForm,
                    $requester,
                    $data,
                    $newAttachments,
                    $removedAttachmentIds,
                    &$storedPaths,
                    &$removedFiles,
                ): RequestForm {
                    /*
                    * Reload and lock the current database record.
                    *
                    * The model created by route binding may already be stale.
                    */
                    $lockedRequest = RequestForm::query()
                        ->lockForUpdate()
                        ->findOrFail($requestForm->getKey());

                    if (
                        $lockedRequest->form_type !==
                        RequestFormType::StockRequisition
                    ) {
                        throw new ConflictHttpException(
                            'Only Stock Requisition requests can be edited.',
                        );
                    }

                    $existingAttachments = $lockedRequest
                        ->attachments()
                        ->lockForUpdate()
                        ->get();

                    $attachmentsToRemove = $existingAttachments
                        ->whereIn('id', $removedAttachmentIds);

                    if (
                        $attachmentsToRemove->count()
                        !== count($removedAttachmentIds)
                    ) {
                        throw ValidationException::withMessages([
                            'removed_attachment_ids' => 'One or more selected attachments no longer belong to this request.',
                        ]);
                    }

                    $finalAttachmentCount =
                        $existingAttachments->count()
                        - $attachmentsToRemove->count()
                        + count($newAttachments);

                    if ($finalAttachmentCount > 10) {
                        throw ValidationException::withMessages([
                            'attachments' => 'A request can have no more than 10 attachments.',
                        ]);
                    }

                    if (
                        ! in_array(
                            $lockedRequest->status,
                            [
                                RequestStatus::Pending,
                                RequestStatus::Disapproved,
                            ],
                            true,
                        )
                    ) {
                        throw new ConflictHttpException(
                            'Only pending or disapproved requests can be edited.',
                        );
                    }

                    /*
                    * Reject a stale browser copy.
                    *
                    * This prevents one tab from overwriting changes made through
                    * another tab or request.
                    */
                    $expectedUpdatedAt = CarbonImmutable::parse(
                        $data['updated_at'],
                    );

                    if (
                        $lockedRequest->updated_at === null
                        || ! $lockedRequest->updated_at->equalTo(
                            $expectedUpdatedAt,
                        )
                    ) {
                        throw new ConflictHttpException(
                            'This request has changed since you opened it. Refresh and try again.',
                        );
                    }

                    /*
                    * Lock approval rows after locking the parent request.
                    * All approval-related services should use this lock order.
                    */
                    $existingSteps = $lockedRequest
                        ->approvalSteps()
                        ->lockForUpdate()
                        ->get();

                    /*
                    * An untouched Pending request may be edited.
                    *
                    * A Disapproved request is handled as an intentional
                    * resubmission, so its old approval activity may be replaced.
                    */
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

                    /*
                    * Revalidate approver eligibility at update time.
                    *
                    * Roles and branch assignments may have changed since the
                    * browser originally loaded the request.
                    */
                    $approvalPlan = $this->approvalChainResolver->resolve(
                        requester: $requester,
                        notedByIds: $data['noted_by_ids'] ?? [],
                        approvedByIds: $data['approved_by_ids'],
                    );

                    /*
                    * Update only requester-editable parent fields.
                    *
                    * Request code, requester, branch, form type, and currency
                    * remain server-controlled and unchanged.
                    */
                    $lockedRequest->kind_of_request =
                        $data['kind_of_request'] ?? null;

                    $lockedRequest->form_data = [
                        'purpose' => $data['form_data']['purpose'],
                    ];

                    /*
                    * Replace the item collection.
                    *
                    * Item IDs are not accepted from the browser, preventing a
                    * user from attaching another request's item.
                    */
                    $lockedRequest->items()->delete();

                    $grandTotal = $this->writer->storeItems(
                        requestForm: $lockedRequest,
                        items: $data['items'],
                    );

                    /*
                    * Replace the old approval chain with the newly resolved one.
                    */
                    $lockedRequest->approvalSteps()->delete();

                    $this->writer->storeApprovalSteps(
                        requestForm: $lockedRequest,
                        approvalPlan: $approvalPlan,
                    );

                    /*
                    * Editing a Disapproved request is a resubmission.
                    */
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
                        ->map(
                            fn ($attachment): array => [
                                'disk' => $attachment->disk,
                                'path' => $attachment->path,
                            ],
                        )
                        ->values()
                        ->all();

                    if ($removedAttachmentIds !== []) {
                        $lockedRequest
                            ->attachments()
                            ->whereKey($removedAttachmentIds)
                            ->delete();
                    }

                    /*
                    * Existing attachments remain associated with the request.
                    */
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
            /*
            * The database rollback removes the new metadata, but not the newly
            * stored physical files.
            */
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }

            throw $exception;
        }

        /*
        * The database has committed successfully. It is now safe to remove the
        * physical files whose metadata was deleted.
        */
        foreach ($removedFiles as $removedFile) {
            Storage::disk($removedFile['disk'])
                ->delete($removedFile['path']);
        }

        return $updatedRequest;
    }
}
