<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\RequestStatus;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestDecisionService
{
    public function decide(
        RequestForm $requestForm,
        User $approver,
        string $action,
        ?string $comment,
    ): RequestForm {
        return DB::transaction(
            function () use (
                $requestForm,
                $approver,
                $action,
                $comment,
            ): RequestForm {
                /*
                 * Always lock the parent before locking its approval steps.
                 * Cancellation and request editing use the same lock order.
                 */
                $lockedRequest = RequestForm::query()
                    ->lockForUpdate()
                    ->findOrFail($requestForm->getKey());

                if (! in_array(
                    $lockedRequest->status,
                    [
                        RequestStatus::Pending,
                        RequestStatus::Ongoing,
                    ],
                    true,
                )) {
                    throw new ConflictHttpException(
                        'This request can no longer be processed.',
                    );
                }

                $steps = $lockedRequest->approvalSteps()
                    ->lockForUpdate()
                    ->get();

                $assignedStep = $steps->first(
                    fn (RequestApprovalStep $step): bool => (int) $step->approver_id ===
                        (int) $approver->getKey(),
                );

                /*
                 * The assignment could have changed after policy
                 * authorization but before this transaction started.
                 */
                if ($assignedStep === null) {
                    throw new ConflictHttpException(
                        'Your approval assignment is no longer available.',
                    );
                }

                if (
                    $assignedStep->status !==
                    ApprovalStepStatus::Pending
                ) {
                    throw new ConflictHttpException(
                        'You have already processed this request.',
                    );
                }

                /*
                * Every earlier approval level must be Approved.
                *
                * Checking only the first Pending step is insufficient because an earlier
                * step could have a terminal non-approved status in inconsistent or legacy
                * data.
                */
                $hasBlockingEarlierStep = $steps->contains(
                    fn (RequestApprovalStep $step): bool => $step->level < $assignedStep->level &&
                        $step->status !== ApprovalStepStatus::Approved,
                );

                if ($hasBlockingEarlierStep) {
                    throw new ConflictHttpException(
                        'It is not yet your turn to process this request.',
                    );
                }

                $normalizedComment = trim((string) $comment);

                $assignedStep->status = $action === 'approve'
                    ? ApprovalStepStatus::Approved
                    : ApprovalStepStatus::Disapproved;
                $assignedStep->comment = $normalizedComment !== ''
                    ? $normalizedComment
                    : null;
                $assignedStep->acted_at = now();
                $assignedStep->save();

                if ($action === 'disapprove') {
                    /*
                     * Later approvers did not act. Mark their steps Skipped
                     * and keep acted_at null for accurate audit history.
                     */
                    foreach ($steps as $step) {
                        if (
                            $step->level > $assignedStep->level &&
                            $step->status === ApprovalStepStatus::Pending
                        ) {
                            $step->status =
                                ApprovalStepStatus::Skipped;
                            $step->save();
                        }
                    }

                    $lockedRequest->status =
                        RequestStatus::Disapproved;
                } else {
                    $hasRemainingPendingStep = $steps->contains(
                        fn (RequestApprovalStep $step): bool => $step->status ===
                            ApprovalStepStatus::Pending,
                    );

                    $lockedRequest->status = $hasRemainingPendingStep
                        ? RequestStatus::Ongoing
                        : RequestStatus::Completed;
                }

                $lockedRequest->save();

                return $lockedRequest->fresh([
                    'requester',
                    'branch',
                    'items',
                    'attachments',
                    'approvalSteps.approver',
                ]);
            },
        );
    }
}
