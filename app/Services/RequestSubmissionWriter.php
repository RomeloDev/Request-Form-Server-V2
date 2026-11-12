<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use App\Support\RequestCurrencies;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class RequestSubmissionWriter
{
    public function findRequesterBranch(
        User $requester,
    ): Branch {
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
     * Create the parent Request Form record.
     *
     * The currency must already be selected and validated by the calling
     * service. This writer never reads a currency directly from the browser.
     *
     * @param  array<string, mixed>|null  $formData
     */
    public function createRequestForm(
        User $requester,
        Branch $branch,
        RequestFormType $formType,
        string $codePrefix,
        ?string $kindOfRequest,
        ?array $formData,
        string $currency,
    ): RequestForm {
        /*
         * Defense in depth: never persist a currency outside the allowlist,
         * even if a future caller forgets to validate it.
         */
        if (! in_array($currency, RequestCurrencies::VALUES, true)) {
            throw new InvalidArgumentException(
                "Unsupported request currency [{$currency}]."
            );
        }

        $requestForm = new RequestForm([
            'kind_of_request' => $kindOfRequest,
            'form_data' => $formData,
            'currency' => $currency,
        ]);

        $requestForm->request_code = 'TEMP-'.Str::uuid();
        $requestForm->form_type = $formType;
        $requestForm->status = RequestStatus::Pending;
        $requestForm->grand_total = '0.00';

        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        $branchCode = Str::upper(
            Str::slug($branch->branch_code, '-')
        );

        $requestForm->request_code = sprintf(
            '%s-%s-%07d',
            $branchCode,
            Str::upper($codePrefix),
            $requestForm->id,
        );

        $requestForm->save();

        return $requestForm;
    }

    /**
     * @param  list<array{
     *     quantity: int|string,
     *     description: string,
     *     unit_cost: int|float|string,
     *     remarks?: string|null
     * }>  $items
     */
    public function storeItems(
        RequestForm $requestForm,
        array $items,
    ): string {
        $grandTotal = '0.00';

        foreach ($items as $index => $itemData) {
            $quantity = (int) $itemData['quantity'];

            $lineTotal = bcmul(
                (string) $quantity,
                (string) $itemData['unit_cost'],
                2,
            );

            $item = new RequestItem([
                'quantity' => $quantity,
                'description' => $itemData['description'],
                'unit_cost' => $itemData['unit_cost'],
                'remarks' => $itemData['remarks'] ?? null,
            ]);

            $item->line_number = $index + 1;
            $item->line_total = $lineTotal;

            $requestForm->items()->save($item);

            $grandTotal = bcadd(
                $grandTotal,
                $lineTotal,
                2,
            );
        }

        return $grandTotal;
    }

    public function storeApprovalSteps(
        RequestForm $requestForm,
        array $approvalPlan,
    ): void {
        foreach ($approvalPlan as $index => $resolvedStep) {
            $approvalStep = new RequestApprovalStep;

            $approvalStep->level = $index + 1;
            $approvalStep->approver_id =
                $resolvedStep['approver_id'];
            $approvalStep->step_type =
                $resolvedStep['step_type'];
            $approvalStep->status =
                ApprovalStepStatus::Pending;

            $requestForm
                ->approvalSteps()
                ->save($approvalStep);
        }
    }

    public function storeAttachment(
        RequestForm $requestForm,
        UploadedFile $file,
        array &$storedPaths,
    ): void {
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
            'original_name' => Str::limit(
                $file->getClientOriginalName(),
                255,
                '',
            ),
            'mime_type' => $file->getMimeType()
                ?? 'application/octet-stream',
            'size_bytes' => (int) $file->getSize(),
        ]);
    }
}
