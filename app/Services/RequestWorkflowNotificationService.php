<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use App\Notifications\BaseDatabaseNotification;
use App\Notifications\RequestActionRequiredNotification;
use App\Notifications\RequestApprovedNotification;
use App\Notifications\RequestCompletedNotification;
use App\Notifications\RequestDisapprovedNotification;
use App\Notifications\RequestSharedNotification;
use App\Notifications\RequestWorkflowMailNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Creates database and queued-mail notifications for request workflow events.
 *
 * This service is called from inside each request create/update transaction,
 * after the persisted approval chain is final. It reads only server-owned
 * request and approval data; no recipient id or request identity ever comes
 * from the browser.
 */
class RequestWorkflowNotificationService
{
    public function __construct(
        private readonly Dispatcher $notifications,
    ) {}

    /**
     * Notify the earliest Pending approval step's assigned approver.
     *
     * Only the first actionable level is notified. Later levels stay silent
     * until the workflow reaches them.
     *
     * The database row is written synchronously in the caller's transaction;
     * its separate mail notification is queued only after commit.
     */
    public function notifyFirstApprover(RequestForm $requestForm): void
    {
        $requestForm->loadMissing('requester');

        $step = $requestForm->approvalSteps()
            ->where('status', ApprovalStepStatus::Pending->value)
            ->orderBy('level')
            ->with('approver')
            ->first();

        if (! $step instanceof RequestApprovalStep) {
            throw new RuntimeException(
                'The request has no pending approval step to notify.'
            );
        }

        $approver = $step->approver;

        if (! $approver instanceof User) {
            throw new RuntimeException(
                'The pending approval step has no assigned approver.'
            );
        }

        $this->notifyWorkflow(
            $approver,
            new RequestActionRequiredNotification(
                ...$this->notificationData(
                    $requestForm,
                    $step,
                    $this->requester($requestForm),
                ),
            ),
        );
    }

    public function notifyApproved(
        RequestForm $requestForm,
        User $approver,
        RequestApprovalStep $actedStep,
    ): void {
        $this->notifyWorkflow(
            $this->requester($requestForm),
            new RequestApprovedNotification(
                ...$this->decisionNotificationData(
                    $requestForm,
                    $actedStep,
                    $approver,
                ),
            ),
        );
    }

    public function notifyDisapproved(
        RequestForm $requestForm,
        User $approver,
        RequestApprovalStep $actedStep,
    ): void {
        $this->notifyWorkflow(
            $this->requester($requestForm),
            new RequestDisapprovedNotification(
                ...$this->decisionNotificationData(
                    $requestForm,
                    $actedStep,
                    $approver,
                ),
            ),
        );
    }

    public function notifyCompleted(
        RequestForm $requestForm,
        User $approver,
        RequestApprovalStep $actedStep,
    ): void {
        $this->notifyWorkflow(
            $this->requester($requestForm),
            new RequestCompletedNotification(
                ...$this->decisionNotificationData(
                    $requestForm,
                    $actedStep,
                    $approver,
                ),
            ),
        );
    }

    public function notifyShared(
        RequestForm $requestForm,
        User $sharer,
        User $recipient,
    ): void {
        $formType = $requestForm->form_type;
        $status = $requestForm->status;
        $actorName = $this->userDisplayName($sharer);

        if (
            ! $formType instanceof RequestFormType
            || ! $status instanceof RequestStatus
            || $status !== RequestStatus::Completed
        ) {
            throw new RuntimeException(
                'The shared request is missing its completed status or form type.'
            );
        }

        if ($actorName === null) {
            throw new RuntimeException(
                'The authenticated sharer has no display name.'
            );
        }

        $this->notifyWorkflow(
            $recipient,
            new RequestSharedNotification(
                requestId: (int) $requestForm->getKey(),
                requestCode: (string) $requestForm->request_code,
                formType: $formType->value,
                status: $status->value,
                actorName: $actorName,
            ),
        );
    }

    /**
     * Notify the already-selected next step from the caller's locked
     * approval-step collection; this method deliberately does not re-query
     * the chain.
     */
    public function notifyNextApprover(
        RequestForm $requestForm,
        RequestApprovalStep $nextStep,
    ): void {
        $nextStep->loadMissing('approver');
        $approver = $nextStep->approver;

        if (! $approver instanceof User) {
            throw new RuntimeException(
                'The pending approval step has no assigned approver.'
            );
        }

        $this->notifyWorkflow(
            $approver,
            new RequestActionRequiredNotification(
                ...$this->notificationData(
                    $requestForm,
                    $nextStep,
                    $this->requester($requestForm),
                ),
            ),
        );
    }

    /**
     * Prefer the established User name field, then the registration names.
     */
    private function userDisplayName(User $user): ?string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        $fallback = trim(
            trim((string) $user->firstName)
            .' '
            .trim((string) $user->lastName)
        );

        return $fallback !== '' ? $fallback : null;
    }

    private function requester(RequestForm $requestForm): User
    {
        $requestForm->loadMissing('requester');
        $requester = $requestForm->requester;

        if (! $requester instanceof User) {
            throw new RuntimeException(
                'The request requester could not be loaded.'
            );
        }

        return $requester;
    }

    /**
     * @return array{
     *     requestId: int,
     *     requestCode: string,
     *     formType: string,
     *     status: string,
     *     approvalLevel: int,
     *     approvalStepType: string,
     *     actorName: ?string
     * }
     */
    private function notificationData(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        User $actor,
    ): array {
        $formType = $requestForm->form_type;
        $status = $requestForm->status;
        $stepType = $step->step_type;

        if (
            ! $formType instanceof RequestFormType
            || ! $status instanceof RequestStatus
        ) {
            throw new RuntimeException(
                'The request is missing its form type or status.'
            );
        }

        if (! $stepType instanceof ApprovalStepType) {
            throw new RuntimeException(
                'The approval step has no step type.'
            );
        }

        return [
            'requestId' => (int) $requestForm->getKey(),
            'requestCode' => (string) $requestForm->request_code,
            'formType' => $formType->value,
            'status' => $status->value,
            'approvalLevel' => (int) $step->level,
            'approvalStepType' => $stepType->value,
            'actorName' => $this->userDisplayName($actor),
        ];
    }

    /**
     * @return array{
     *     requestId: int,
     *     requestCode: string,
     *     formType: string,
     *     status: string,
     *     approvalLevel: int,
     *     approvalStepType: string,
     *     actorName: string
     * }
     */
    private function decisionNotificationData(
        RequestForm $requestForm,
        RequestApprovalStep $step,
        User $approver,
    ): array {
        $data = $this->notificationData(
            $requestForm,
            $step,
            $approver,
        );

        if ($data['actorName'] === null) {
            throw new RuntimeException(
                'The acting approver has no display name.'
            );
        }

        return $data;
    }

    private function notifyWorkflow(
        User $recipient,
        BaseDatabaseNotification $notification,
    ): void {
        $this->notifications->sendNow(
            $recipient,
            $notification,
            ['database'],
        );

        $mailNotification = new RequestWorkflowMailNotification(
            $notification->payload(),
        );

        /*
         * Queue insertion happens only after the outer workflow transaction
         * commits. A queue outage at that point must not escape back through
         * DB::transaction(): the business state and database notification are
         * already durable, and several callers would otherwise mistake the
         * exception for a rollback and delete committed attachment files.
         */
        DB::afterCommit(function () use (
            $recipient,
            $mailNotification,
        ): void {
            try {
                $this->notifications->send(
                    $recipient,
                    $mailNotification,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
