<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class StockRequisitionService
{
    public function __construct(
        private readonly RequestApprovalChainResolver $approvalChainResolver,
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
        $branch = $this->findRequesterBranch($requester);

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

                $requestForm = $this->createRequestForm(
                    requester: $requester,
                    branch: $branch,
                    data: $data,
                );

                $this->storeApprovalSteps(
                    requestForm: $requestForm,
                    approvalPlan: $approvalPlan,
                );

                $grandTotal = '0.00';

                foreach ($data['items'] as $index => $itemData) {
                    $quantity = (int) $itemData['quantity'];

                    $lineTotal = $this->calculateLineTotal(
                        quantity: $quantity,
                        unitCost: (string) $itemData['unit_cost'],
                    );

                    $item = new RequestItem([
                        'quantity' => $quantity,
                        // 'unit' => $itemData['unit'] ?? null,
                        'description' => $itemData['description'],
                        'unit_cost' => $itemData['unit_cost'],
                        'remarks' => $itemData['remarks'] ?? null,
                    ]);

                    /*
                     * These values are not fillable because the server,
                     * rather than the browser, controls them.
                     */
                    $item->line_number = $index + 1;
                    $item->line_total = $lineTotal;

                    /*
                     * Saving through the relationship automatically sets
                     * request_form_id.
                     */
                    $requestForm->items()->save($item);

                    $grandTotal = bcadd(
                        $grandTotal,
                        $lineTotal,
                        2,
                    );
                }

                $requestForm->grand_total = $grandTotal;
                $requestForm->save();

                foreach ($attachments as $file) {
                    $this->storeAttachment(
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
     * Locate the authenticated user's branch.
     */
    private function findRequesterBranch(User $requester): Branch
    {
        if (blank($requester->branch_code)) {
            throw ValidationException::withMessages([
                'branch' => 'Your account does not have an assigned branch.',
            ]);
        }

        $branch = Branch::query()
            ->where('branch_code', $requester->branch_code)
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch' => 'Your assigned branch could not be found.',
            ]);
        }

        return $branch;
    }

    /**
     * Create the parent request record.
     *
     * A temporary UUID allows the record to be inserted before it has
     * a numeric database ID. We then use that ID in the final code.
     */
    private function createRequestForm(
        User $requester,
        Branch $branch,
        array $data,
    ): RequestForm {
        $requestForm = new RequestForm([
            'kind_of_request' => $data['kind_of_request'] ?? null,
            'form_data' => $data['form_data'],
            'currency' => 'PHP',
        ]);

        /*
         * These properties are server-controlled and intentionally absent
         * from the model's Fillable list.
         */
        $requestForm->request_code = 'TEMP-'.Str::uuid();
        $requestForm->form_type = RequestFormType::StockRequisition;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '0.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);

        $requestForm->save();

        /*
         * The request ID is globally unique, so the final code is safe
         * even when two users submit requests simultaneously.
         *
         * Example: CALI-SRL-0000123
         */
        $branchCode = Str::upper(
            Str::slug($branch->branch_code, '-')
        );

        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branchCode,
            $requestForm->id,
        );

        $requestForm->save();

        return $requestForm;
    }

    /**
     * Save the resolved approvers in their required processing order.
     *
     * @param  list<array{
     *     approver_id: int,
     *     step_type: ApprovalStepType
     * }>  $approvalPlan
     */
    private function storeApprovalSteps(
        RequestForm $requestForm,
        array $approvalPlan,
    ): void {
        foreach ($approvalPlan as $index => $resolvedStep) {
            $approvalStep = new RequestApprovalStep;

            /*
            * Array indexes begin at zero, but approval levels begin at one.
            */
            $approvalStep->level = $index + 1;
            $approvalStep->approver_id =
                $resolvedStep['approver_id'];
            $approvalStep->step_type =
                $resolvedStep['step_type'];
            $approvalStep->status =
                ApprovalStepStatus::Pending;

            /*
            * Saving through the relationship automatically supplies the
            * request_form_id foreign key.
            */
            $requestForm
                ->approvalSteps()
                ->save($approvalStep);
        }
    }

    /**
     * Calculate one item's money value using decimal arithmetic.
     *
     * All values are non-negative because the Form Request validates them.
     */
    private function calculateLineTotal(
        int $quantity,
        string $unitCost,
    ): string {
        return bcmul(
            (string) $quantity,
            $unitCost,
            2,
        );
    }

    /**
     * Store one file privately and create its metadata record.
     */
    private function storeAttachment(
        RequestForm $requestForm,
        UploadedFile $file,
        array &$storedPaths,
    ): void {
        /*
         * UploadedFile::store() generates a safe hashed filename.
         * The "local" disk points to storage/app/private.
         */
        $path = $file->store(
            "request-forms/{$requestForm->id}",
            'local',
        );

        if (! is_string($path)) {
            throw new RuntimeException(
                'The attachment could not be stored.'
            );
        }

        $storedPaths[] = $path;

        $requestForm->attachments()->create([
            'disk' => 'local',
            'path' => $path,

            /*
             * Original names are display metadata only.
             * Limit the stored length to match the database column.
             */
            'original_name' => Str::limit(
                $file->getClientOriginalName(),
                255,
                ''
            ),

            'mime_type' => $file->getMimeType()
                ?? 'application/octet-stream',

            'size_bytes' => (int) $file->getSize(),
        ]);
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

                    $grandTotal = '0.00';

                    foreach ($data['items'] as $index => $itemData) {
                        $quantity = (int) $itemData['quantity'];

                        $lineTotal = $this->calculateLineTotal(
                            quantity: $quantity,
                            unitCost: (string) $itemData['unit_cost'],
                        );

                        $item = new RequestItem([
                            'quantity' => $quantity,
                            'description' => $itemData['description'],
                            'unit_cost' => $itemData['unit_cost'],
                            'remarks' => $itemData['remarks'] ?? null,
                        ]);

                        /*
                        * Laravel controls the ordering and calculated amount.
                        */
                        $item->line_number = $index + 1;
                        $item->line_total = $lineTotal;

                        $lockedRequest->items()->save($item);

                        $grandTotal = bcadd(
                            $grandTotal,
                            $lineTotal,
                            2,
                        );
                    }

                    /*
                    * Replace the old approval chain with the newly resolved one.
                    */
                    $lockedRequest->approvalSteps()->delete();

                    $this->storeApprovalSteps(
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
                        $this->storeAttachment(
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
