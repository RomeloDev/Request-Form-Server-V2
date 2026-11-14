<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Bank;
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

class CheckIssuanceService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly RequestApprovalChainResolver $approvalChainResolver,
        private readonly RequestSubmissionWriter $writer,
    ) {}

    /**
     * Create one Check Issuance request and its related records.
     *
     * @param  array{
     *     kind_of_request: string,
     *     form_data: array{
     *         payee: string,
     *         bank: string,
     *         account_no: string,
     *         swift_code?: string|null
     *     },
     *     items: list<array{
     *         quantity: int|string,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *     attachments?: list<UploadedFile>,
     *     noted_by_ids?: list<int>,
     *     approved_by_ids: list<int>
     * }  $data
     */
    public function create(
        User $requester,
        array $data,
    ): RequestForm {
        $branch = $this->writer->findRequesterBranch(
            $requester,
        );

        $payee = trim(
            (string) $data['form_data']['payee'],
        );

        $bankName = trim(
            (string) $data['form_data']['bank'],
        );

        $accountNumber = trim(
            (string) $data['form_data']['account_no'],
        );

        $swiftCode = trim(
            (string) ($data['form_data']['swift_code'] ?? ''),
        );

        if ($swiftCode === '') {
            $swiftCode = null;
        }

        /** @var list<UploadedFile> $attachments */
        $attachments = $data['attachments'] ?? [];

        /** @var list<string> $storedPaths */
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $requester,
                $branch,
                $data,
                $payee,
                $bankName,
                $accountNumber,
                $swiftCode,
                $attachments,
                &$storedPaths,
            ): RequestForm {
                $approvalPlan = $this
                    ->approvalChainResolver
                    ->resolve(
                        requester: $requester,
                        notedByIds: $data['noted_by_ids'] ?? [],
                        approvedByIds: $data['approved_by_ids'],
                    );

                $requestForm = $this->writer->createRequestForm(
                    requester: $requester,
                    branch: $branch,
                    formType: RequestFormType::CheckIssuance,
                    codePrefix: 'CIRS',
                    kindOfRequest: $data['kind_of_request'],
                    formData: [
                        'payee' => $payee,
                        'bank' => $bankName,
                        'account_no' => $accountNumber,
                        'swift_code' => $swiftCode,
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

                Bank::query()->firstOrCreate([
                    'name' => $bankName,
                ]);

                return $requestForm->load([
                    'requester',
                    'branch',
                    'items',
                    'attachments',
                    'approvalSteps.approver',
                ]);
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }

            throw $exception;
        }
    }

    /**
     * Update and resubmit an existing Check Issuance request.
     *
     * @param  array{
     *     updated_at: string,
     *     kind_of_request: string,
     *     form_data: array{
     *         payee: string,
     *         bank: string,
     *         account_no: string,
     *         swift_code?: string|null
     *     },
     *     noted_by_ids?: list<int>,
     *     approved_by_ids: list<int>,
     *     items: list<array{
     *         quantity: int|string,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *     attachments?: list<UploadedFile>,
     *     removed_attachment_ids?: list<int>
     * }  $data
     */
    public function update(
        RequestForm $requestForm,
        User $requester,
        array $data,
    ): RequestForm {
        $payee = trim(
            (string) $data['form_data']['payee'],
        );

        $bankName = trim(
            (string) $data['form_data']['bank'],
        );

        $accountNumber = trim(
            (string) $data['form_data']['account_no'],
        );

        $swiftCode = trim(
            (string) ($data['form_data']['swift_code'] ?? ''),
        );

        if ($swiftCode === '') {
            $swiftCode = null;
        }

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
                    $payee,
                    $bankName,
                    $accountNumber,
                    $swiftCode,
                    $newAttachments,
                    $removedAttachmentIds,
                    &$storedPaths,
                    &$removedFiles,
                ): RequestForm {
                    $lockedRequest = RequestForm::query()
                        ->lockForUpdate()
                        ->findOrFail($requestForm->getKey());

                    if (
                        $lockedRequest->form_type
                        !== RequestFormType::CheckIssuance
                    ) {
                        throw new ConflictHttpException(
                            'Only Check Issuance requests can be edited.',
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

                    $existingSteps = $lockedRequest
                        ->approvalSteps()
                        ->lockForUpdate()
                        ->get();

                    if ($lockedRequest->status === RequestStatus::Pending) {
                        $hasApprovalActivity = $existingSteps->contains(
                            fn (RequestApprovalStep $step): bool => $step->status
                                    !== ApprovalStepStatus::Pending
                                || $step->acted_at !== null,
                        );

                        if ($hasApprovalActivity) {
                            throw new ConflictHttpException(
                                'A pending request cannot be edited after approval activity has started.',
                            );
                        }
                    }

                    $approvalPlan = $this
                        ->approvalChainResolver
                        ->resolve(
                            requester: $requester,
                            notedByIds: $data['noted_by_ids'] ?? [],
                            approvedByIds: $data['approved_by_ids'],
                        );

                    $lockedRequest->kind_of_request =
                        $data['kind_of_request'];

                    $lockedRequest->form_data = [
                        'payee' => $payee,
                        'bank' => $bankName,
                        'account_no' => $accountNumber,
                        'swift_code' => $swiftCode,
                    ];

                    /*
                     * Currency is server-owned and fixed for Check Issuance.
                     */
                    $lockedRequest->currency =
                        RequestCurrencies::PHP;

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

                    Bank::query()->firstOrCreate([
                        'name' => $bankName,
                    ]);

                    return $lockedRequest
                        ->refresh()
                        ->load([
                            'requester',
                            'branch',
                            'items',
                            'attachments',
                            'approvalSteps.approver',
                        ]);
                }
            );
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }

            throw $exception;
        }

        /*
         * The transaction has committed. It is now safe to remove old files.
         */
        $removedFilesByDisk = collect($removedFiles)
            ->groupBy('disk');

        foreach ($removedFilesByDisk as $disk => $files) {
            Storage::disk((string) $disk)->delete(
                $files->pluck('path')->all(),
            );
        }

        return $updatedRequest;
    }
}
