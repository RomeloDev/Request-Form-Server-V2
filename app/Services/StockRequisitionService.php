<?php

namespace App\Services;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StockRequisitionService
{
    /**
     * Create one Stock Requisition and all of its related records.
     *
     * @param  array{
     *     kind_of_request?: string|null,
     *     form_data: array{purpose: string},
     *     items: list<array{
     *         quantity: int|float|string,
     *         unit?: string|null,
     *         description: string,
     *         unit_cost: int|float|string,
     *         remarks?: string|null
     *     }>,
     *     attachments?: list<UploadedFile>
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
                $requestForm = $this->createRequestForm(
                    requester: $requester,
                    branch: $branch,
                    data: $data,
                );

                $grandTotal = '0.00';

                foreach ($data['items'] as $index => $itemData) {
                    $lineTotal = $this->calculateLineTotal(
                        quantity: (string) $itemData['quantity'],
                        unitCost: (string) $itemData['unit_cost'],
                    );

                    $item = new RequestItem([
                        'quantity' => $itemData['quantity'],
                        'unit' => $itemData['unit'] ?? null,
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
     * Calculate one item's money value using decimal arithmetic.
     *
     * All values are non-negative because the Form Request validates them.
     */
    private function calculateLineTotal(
        string $quantity,
        string $unitCost,
    ): string {
        /*
         * Keep three decimal places before rounding because quantity may
         * contain up to three decimal places.
         */
        $unroundedTotal = bcmul(
            $quantity,
            $unitCost,
            3,
        );

        /*
         * Add half of one cent, then truncate to two decimal places.
         * For non-negative values, this performs round-half-up.
         */
        return bcadd(
            $unroundedTotal,
            '0.005',
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
}
