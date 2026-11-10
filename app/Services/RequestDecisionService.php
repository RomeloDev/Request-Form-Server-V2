<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestStatus;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class RequestDecisionService
{
    public function decide(
        RequestForm $requestForm,
        User $approver,
        string $action,
        ?string $comment,
        array $attachments = [],
    ): RequestForm {
        /** @var list<string> $storedPaths */
        $storedPaths = [];

        try {
            return DB::transaction(
                function () use (
                    $requestForm,
                    $approver,
                    $action,
                    $comment,
                    $attachments,
                    &$storedPaths,
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

                    if (
                        $attachments !== []
                        && (
                            $action !== 'approve'
                            || $assignedStep->step_type !== ApprovalStepType::DelegatedApproval
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'attachments' => 'Approved attachments are only allowed for delegated staff approvals.',
                        ]);
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

                    foreach ($attachments as $file) {
                        if (! $file instanceof UploadedFile) {
                            throw ValidationException::withMessages([
                                'attachments' => 'Every approved attachment must be a valid file.',
                            ]);
                        }

                        $path = $file->store(
                            "request-forms/{$lockedRequest->id}/approval-steps/{$assignedStep->id}",
                            'local',
                        );

                        if (! is_string($path) || $path === '') {
                            throw new RuntimeException(
                                'The approved attachment could not be stored.',
                            );
                        }

                        $storedPaths[] = $path;

                        $assignedStep->attachments()->create([
                            'disk' => 'local',
                            'path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type' => (string) $file->getMimeType(),
                            'size_bytes' => (int) $file->getSize(),
                        ]);
                    }

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
                        'approvalSteps.attachments',
                    ]);
                },
            );
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }
    }
}
