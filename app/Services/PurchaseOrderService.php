<?php

namespace App\Services;

use App\Enums\RequestFormType;
use App\Models\RequestForm;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
}
