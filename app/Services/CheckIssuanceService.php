<?php

namespace App\Services;

use App\Enums\RequestFormType;
use App\Models\Bank;
use App\Models\RequestForm;
use App\Models\User;
use App\Support\RequestCurrencies;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
}
