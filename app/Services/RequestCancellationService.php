<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestStatus;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestCancellationService
{
    public function cancel(
        RequestForm $requestForm,
        string $reason,
    ): RequestForm {
        return DB::transaction(
            function () use ($requestForm, $reason): RequestForm {
                /*
                 * Reload and lock the database row. The model bound by
                 * the route could have become stale before this transaction.
                 */
                $lockedRequest = RequestForm::query()
                    ->lockForUpdate()
                    ->findOrFail($requestForm->getKey());

                if (
                    $lockedRequest->status !==
                    RequestStatus::Pending
                ) {
                    throw new ConflictHttpException(
                        'Only pending requests can be canceled.',
                    );
                }

                /*
                 * Lock the approval rows in the same order future approval
                 * actions should use: parent request first, steps second.
                 */
                $steps = $lockedRequest->approvalSteps()
                    ->lockForUpdate()
                    ->get();

                $hasApprovalActivity = $steps->contains(
                    fn (RequestApprovalStep $step): bool => $step->status !==
                            ApprovalStepStatus::Pending ||
                        $step->acted_at !== null,
                );

                if ($hasApprovalActivity) {
                    throw new ConflictHttpException(
                        'A request cannot be canceled after approval activity has started.',
                    );
                }

                $lockedRequest->status =
                    RequestStatus::Canceled;
                $lockedRequest->cancellation_reason = $reason;
                $lockedRequest->save();

                /*
                 * These steps were locked and verified as untouched.
                 * acted_at stays null because the approvers did not act.
                 */
                $lockedRequest->approvalSteps()->update([
                    'status' => ApprovalStepStatus::Canceled->value,
                    'updated_at' => now(),
                ]);

                return $lockedRequest->refresh();
            }
        );
    }
}
