<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\RequestForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestSharingService
{
    public function __construct(
        private readonly RequestShareCandidateService $candidateService,
    ) {}

    /**
     * Share a completed request with the selected eligible recipients.
     *
     * All selected users must still be eligible. The request is rejected as
     * a whole rather than partially shared.
     *
     * @param  array<int, int>  $userIds
     * @return int The number of created share records.
     */
    public function share(
        RequestForm $requestForm,
        array $userIds,
    ): int {
        return DB::transaction(
            function () use ($requestForm, $userIds): int {
                /*
                 * Lock the request row so concurrent share attempts cannot
                 * both pass the completed-status and recipient checks.
                 */
                $lockedRequest = RequestForm::query()
                    ->lockForUpdate()
                    ->findOrFail($requestForm->getKey());

                if ($lockedRequest->status !== RequestStatus::Completed) {
                    throw new ConflictHttpException(
                        'Only completed requests can be shared.',
                    );
                }

                $requestedIds = array_values(
                    array_map('intval', $userIds)
                );

                /*
                 * Reuse the candidate rules so the users that can be
                 * submitted always match the users the selector displayed.
                 */
                $eligibleIds = $this->candidateService
                    ->eligibleQueryFor($lockedRequest)
                    ->whereKey($requestedIds)
                    ->pluck('id')
                    ->map(
                        fn ($id): int => (int) $id
                    )
                    ->all();

                if (count($eligibleIds) !== count($requestedIds)) {
                    throw ValidationException::withMessages([
                        'user_ids' => 'One or more selected recipients are not eligible to receive this request.',
                    ]);
                }

                $lockedRequest->sharedRecipients()->attach(
                    $eligibleIds
                );

                return count($eligibleIds);
            },
        );
    }
}
