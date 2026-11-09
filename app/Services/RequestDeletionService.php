<?php

namespace App\Services;

use App\Models\RequestForm;
use Illuminate\Support\Facades\DB;

class RequestDeletionService
{
    public function delete(RequestForm $requestForm): int
    {
        return DB::transaction(function () use ($requestForm): int {
            $lockedRequest = RequestForm::query()
                ->lockForUpdate()
                ->findOrFail($requestForm->getKey());

            $deletedRequestId = (int) $lockedRequest->getKey();

            $lockedRequest->delete();

            return $deletedRequestId;
        });
    }
}
