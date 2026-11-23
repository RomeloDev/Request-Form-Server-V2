<?php

namespace Tests\Feature\Notifications;

use App\Enums\ApprovalStepStatus;
use App\Enums\ApprovalStepType;
use App\Enums\NotificationKind;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use App\Models\User;
use App\Notifications\RequestActionRequiredNotification;
use App\Notifications\RequestApprovedNotification;
use App\Notifications\RequestCompletedNotification;
use App\Notifications\RequestDisapprovedNotification;
use App\Notifications\RequestWorkflowMailNotification;
use App\Services\RequestDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\NotificationContract;
use Tests\Support\ProvidesUsableSignatures;
use Tests\TestCase;
use Throwable;

class RequestDecisionNotificationTest extends TestCase
{
    use ProvidesUsableSignatures;
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    #[DataProvider('supportedRequestFormTypes')]
    public function test_non_final_approval_notifies_requester_and_only_the_next_approver(
        RequestFormType $formType,
    ): void {
        [$requester, $firstApprover, $nextApprover, $laterApprover, $otherUser] =
            $this->workflowUsers('NEXT');
        $requestForm = $this->requestForm(
            $requester,
            RequestStatus::Pending,
            $formType,
        );

        $actedStep = $this->approvalStep(
            $requestForm,
            $firstApprover,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::DelegatedApproval,
        );
        $nextStep = $this->approvalStep(
            $requestForm,
            $nextApprover,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );
        $this->approvalStep(
            $requestForm,
            $laterApprover,
            3,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Queue::fake();
        Sanctum::actingAs($firstApprover);

        $this->post($this->decisionUrl($requestForm), [
            '_method' => 'PATCH',
            'action' => 'approve',
            'comment' => 'Private decision comment.',
            'attachments' => [
                UploadedFile::fake()->image('private-approval.png'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Ongoing->value);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(
            ApprovalStepStatus::Pending,
            $nextStep->fresh()->status,
        );
        $this->assertSame(0, $laterApprover->notifications()->count());

        $this->assertNotification(
            recipient: $requester,
            type: RequestApprovedNotification::class,
            expected: [
                'kind' => 'request_approved',
                'title' => "Request approved: {$requestForm->request_code}",
                'message' => "{$firstApprover->name} approved your request {$requestForm->request_code} at level 1.",
                'target' => 'my_request',
                'request_id' => $requestForm->id,
                'request_code' => $requestForm->request_code,
                'form_type' => $formType->value,
                'status' => RequestStatus::Ongoing->value,
                'approval_level' => 1,
                'approval_step_type' => ApprovalStepType::DelegatedApproval->value,
                'actor_name' => $firstApprover->name,
            ],
        );
        $this->assertNotification(
            recipient: $nextApprover,
            type: RequestActionRequiredNotification::class,
            expected: [
                'kind' => 'request_action_required',
                'title' => "Action required: {$requestForm->request_code}",
                'message' => "Request {$requestForm->request_code} is waiting for your approval at level 2.",
                'target' => 'assigned_request',
                'request_id' => $requestForm->id,
                'request_code' => $requestForm->request_code,
                'form_type' => $formType->value,
                'status' => RequestStatus::Ongoing->value,
                'approval_level' => 2,
                'approval_step_type' => ApprovalStepType::ApprovedBy->value,
                'actor_name' => $requester->name,
            ],
        );

        $this->assertSame(0, $firstApprover->notifications()->count());
        $this->assertSame(0, $otherUser->notifications()->count());

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertConflict();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertQueuedMailNotifications([
            [$requester, NotificationKind::RequestApproved],
            [$nextApprover, NotificationKind::RequestActionRequired],
        ]);
        $mailJob = Queue::pushed(SendQueuedNotifications::class)->first();
        $this->simulateQueuedMailFailure($mailJob);

        $this->assertSame(RequestStatus::Ongoing, $requestForm->fresh()->status);
        $this->assertSame(
            ApprovalStepStatus::Approved,
            $actedStep->fresh()->status,
        );
        $this->assertDatabaseCount('notifications', 2);

        $serialized = json_encode([
            $requester->notifications()->sole()->data,
            $nextApprover->notifications()->sole()->data,
        ]);
        foreach ([
            'Private decision comment.',
            'PRIVATE-FORM-DATA',
            'request-forms/',
            'private-approval.png',
            $requester->email,
            $firstApprover->email,
            'https://private.example/path',
            'notifiable_id',
            'notifiable_type',
        ] as $privateValue) {
            $this->assertStringNotContainsString(
                $privateValue,
                (string) $serialized,
            );
        }
    }

    public function test_disapproval_notifies_only_the_requester_and_skips_future_approvers(): void
    {
        [$requester, $disapprover, $futureApprover, $laterApprover, $otherUser] =
            $this->workflowUsers('REJECT');
        $requestForm = $this->requestForm($requester);
        $actedStep = $this->approvalStep(
            $requestForm,
            $disapprover,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::NotedBy,
        );
        $futureStep = $this->approvalStep(
            $requestForm,
            $futureApprover,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );
        $laterStep = $this->approvalStep(
            $requestForm,
            $laterApprover,
            3,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Queue::fake();
        Sanctum::actingAs($disapprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'disapprove',
            'comment' => 'Please correct the submitted details.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                RequestStatus::Disapproved->value,
            );

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(
            ApprovalStepStatus::Disapproved,
            $actedStep->fresh()->status,
        );
        $this->assertSame(
            ApprovalStepStatus::Skipped,
            $futureStep->fresh()->status,
        );
        $this->assertNull($futureStep->fresh()->acted_at);

        $this->assertNotification(
            recipient: $requester,
            type: RequestDisapprovedNotification::class,
            expected: [
                'kind' => 'request_disapproved',
                'title' => "Request disapproved: {$requestForm->request_code}",
                'message' => "{$disapprover->name} disapproved your request {$requestForm->request_code} at level 1.",
                'target' => 'my_request',
                'request_id' => $requestForm->id,
                'request_code' => $requestForm->request_code,
                'form_type' => RequestFormType::StockRequisition->value,
                'status' => RequestStatus::Disapproved->value,
                'approval_level' => 1,
                'approval_step_type' => ApprovalStepType::NotedBy->value,
                'actor_name' => $disapprover->name,
            ],
        );

        $this->assertSame(0, $disapprover->notifications()->count());
        $this->assertSame(0, $futureApprover->notifications()->count());
        $this->assertSame(0, $laterApprover->notifications()->count());
        $this->assertSame(0, $otherUser->notifications()->count());
        $this->assertDatabaseMissing('notifications', [
            'type' => RequestCompletedNotification::class,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => RequestActionRequiredNotification::class,
        ]);
        $this->assertSame(
            ApprovalStepStatus::Skipped,
            $laterStep->fresh()->status,
        );
        $this->assertQueuedMailNotifications([
            [$requester, NotificationKind::RequestDisapproved],
        ]);
    }

    public function test_final_approval_produces_one_combined_completion_notification(): void
    {
        [$requester, $priorApprover, $finalApprover, $laterApprover, $otherUser] =
            $this->workflowUsers('FINAL');
        $requestForm = $this->requestForm(
            $requester,
            RequestStatus::Ongoing,
        );
        $this->approvalStep(
            $requestForm,
            $priorApprover,
            1,
            ApprovalStepStatus::Approved,
            ApprovalStepType::NotedBy,
        );
        $finalStep = $this->approvalStep(
            $requestForm,
            $finalApprover,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Queue::fake();
        Sanctum::actingAs($finalApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                RequestStatus::Completed->value,
            );

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(
            ApprovalStepStatus::Approved,
            $finalStep->fresh()->status,
        );

        $this->assertNotification(
            recipient: $requester,
            type: RequestCompletedNotification::class,
            expected: [
                'kind' => 'request_completed',
                'title' => "Request completed: {$requestForm->request_code}",
                'message' => "Final approval has been recorded. Request {$requestForm->request_code} is now completed.",
                'target' => 'my_request',
                'request_id' => $requestForm->id,
                'request_code' => $requestForm->request_code,
                'form_type' => RequestFormType::StockRequisition->value,
                'status' => RequestStatus::Completed->value,
                'approval_level' => 2,
                'approval_step_type' => ApprovalStepType::ApprovedBy->value,
                'actor_name' => $finalApprover->name,
            ],
        );

        $this->assertDatabaseMissing('notifications', [
            'type' => RequestApprovedNotification::class,
        ]);
        $this->assertSame(
            0,
            $requester->notifications()
                ->where('type', RequestActionRequiredNotification::class)
                ->count(),
        );
        $this->assertSame(0, $finalApprover->notifications()->count());
        $this->assertSame(0, $laterApprover->notifications()->count());
        $this->assertSame(0, $otherUser->notifications()->count());

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertQueuedMailNotifications([
            [$requester, NotificationKind::RequestCompleted],
        ]);
    }

    public function test_out_of_turn_decision_does_not_create_notifications(): void
    {
        [$requester, $firstApprover, $nextApprover] =
            $this->workflowUsers('OUT-OF-TURN');
        $requestForm = $this->requestForm($requester);
        $this->approvalStep(
            $requestForm,
            $firstApprover,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::NotedBy,
        );
        $this->approvalStep(
            $requestForm,
            $nextApprover,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($nextApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertConflict();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(
            RequestStatus::Pending,
            $requestForm->fresh()->status,
        );
    }

    public function test_unauthorized_user_does_not_create_notifications(): void
    {
        [$requester, $approver, $nextApprover, , $unassignedUser] =
            $this->workflowUsers('UNAUTHORIZED');
        $requestForm = $this->requestForm($requester);
        $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );
        $this->approvalStep(
            $requestForm,
            $nextApprover,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($unassignedUser);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertForbidden();

        $this->assertDatabaseCount('notifications', 0);
    }

    #[DataProvider('terminalRequestStatuses')]
    public function test_terminal_request_statuses_do_not_create_notifications(
        RequestStatus $status,
    ): void {
        [$requester, $approver] = $this->workflowUsers('TERMINAL');
        $requestForm = $this->requestForm($requester, $status);
        $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($approver);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertConflict();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame($status, $requestForm->fresh()->status);
    }

    public function test_invalid_delegated_attachment_rejection_does_not_create_notifications(): void
    {
        [$requester, $approver] = $this->workflowUsers('INVALID-ATTACHMENT');
        $requestForm = $this->requestForm($requester);
        $step = $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($approver);

        $this->post($this->decisionUrl($requestForm), [
            '_method' => 'PATCH',
            'action' => 'approve',
            'attachments' => [
                UploadedFile::fake()->image('not-allowed.png'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(
            ApprovalStepStatus::Pending,
            $step->fresh()->status,
        );
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_same_user_as_requester_and_next_approver_receives_two_distinct_event_records(): void
    {
        [$requester, $firstApprover, , $laterApprover, $otherUser] =
            $this->workflowUsers('SAME-USER');
        $requester->assignRole('approver');
        $requestForm = $this->requestForm($requester);
        $this->approvalStep(
            $requestForm,
            $firstApprover,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::NotedBy,
        );
        $nextStep = $this->approvalStep(
            $requestForm,
            $requester,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::DelegatedApproval,
        );
        $this->approvalStep(
            $requestForm,
            $laterApprover,
            3,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );

        Sanctum::actingAs($firstApprover);

        $this->patchJson($this->decisionUrl($requestForm), [
            'action' => 'approve',
        ])->assertOk();

        $notifications = $requester->notifications()->get();
        $this->assertCount(2, $notifications);
        $byKind = $notifications->keyBy(
            fn (DatabaseNotification $notification): string => $notification->data['kind'],
        );
        $this->assertSame(
            ['request_action_required', 'request_approved'],
            $byKind->keys()->sort()->values()->all(),
        );
        $this->assertCount(2, $notifications->pluck('id')->unique());
        $this->assertNotificationRecord(
            $byKind['request_approved'],
            $requester,
            RequestApprovedNotification::class,
            [
                'kind' => 'request_approved',
                'title' => "Request approved: {$requestForm->request_code}",
                'message' => "{$firstApprover->name} approved your request {$requestForm->request_code} at level 1.",
                'target' => 'my_request',
                'request_id' => $requestForm->id,
                'request_code' => $requestForm->request_code,
                'form_type' => RequestFormType::StockRequisition->value,
                'status' => RequestStatus::Ongoing->value,
                'approval_level' => 1,
                'approval_step_type' => ApprovalStepType::NotedBy->value,
                'actor_name' => $firstApprover->name,
            ],
        );
        $this->assertNotificationRecord(
            $byKind['request_action_required'],
            $requester,
            RequestActionRequiredNotification::class,
            [
                'kind' => 'request_action_required',
                'title' => "Action required: {$requestForm->request_code}",
                'message' => "Request {$requestForm->request_code} is waiting for your approval at level 2.",
                'target' => 'assigned_request',
                'request_id' => $requestForm->id,
                'request_code' => $requestForm->request_code,
                'form_type' => RequestFormType::StockRequisition->value,
                'status' => RequestStatus::Ongoing->value,
                'approval_level' => 2,
                'approval_step_type' => ApprovalStepType::DelegatedApproval->value,
                'actor_name' => $requester->name,
            ],
        );
        $this->assertSame(
            ApprovalStepStatus::Pending,
            $nextStep->fresh()->status,
        );
        $this->assertSame(0, $laterApprover->notifications()->count());
        $this->assertSame(0, $otherUser->notifications()->count());
    }

    public function test_notification_persistence_failure_rolls_back_decision_and_cleans_uploaded_file(): void
    {
        config(['queue.default' => 'database']);

        [$requester, $approver, $nextApprover, $laterApprover, $otherUser] =
            $this->workflowUsers('ROLLBACK');
        $requestForm = $this->requestForm($requester);
        $actedStep = $this->approvalStep(
            $requestForm,
            $approver,
            1,
            ApprovalStepStatus::Pending,
            ApprovalStepType::DelegatedApproval,
        );
        $this->approvalStep(
            $requestForm,
            $nextApprover,
            2,
            ApprovalStepStatus::Pending,
            ApprovalStepType::ApprovedBy,
        );
        $thrown = null;

        DB::statement(
            'CREATE TRIGGER fail_second_decision_notification_insert '
            .'BEFORE INSERT ON notifications '
            .'WHEN (SELECT COUNT(*) FROM notifications) >= 1 '
            ."BEGIN SELECT RAISE(ABORT, 'Simulated notification failure'); END"
        );

        try {
            app(RequestDecisionService::class)->decide(
                $requestForm,
                $approver,
                'approve',
                'Transaction must roll this comment back.',
                [UploadedFile::fake()->image('rollback-proof.png')],
            );
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS fail_second_decision_notification_insert',
            );
        }

        $this->assertNotNull(
            $thrown,
            'Expected the notification insert to fail.',
        );
        $this->assertSame(
            RequestStatus::Pending,
            $requestForm->fresh()->status,
        );
        $this->assertSame(
            ApprovalStepStatus::Pending,
            $actedStep->fresh()->status,
        );
        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Pending->value,
        ]);
        $this->assertDatabaseCount('request_approval_attachments', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $laterApprover->notifications()->count());
        $this->assertSame(0, $otherUser->notifications()->count());
    }

    /**
     * @param  list<array{User, NotificationKind}>  $expected
     */
    private function assertQueuedMailNotifications(array $expected): void
    {
        Queue::assertPushedTimes(
            SendQueuedNotifications::class,
            count($expected),
        );

        $jobs = Queue::pushed(SendQueuedNotifications::class)->values();
        $this->assertCount(count($expected), $jobs);

        foreach ($expected as $index => [$recipient, $kind]) {
            $job = $jobs->get($index);

            $this->assertSame('notifications', $job->queue);
            $this->assertSame(['mail'], $job->channels);
            $this->assertTrue($job->afterCommit);
            $this->assertTrue($job->shouldBeEncrypted);
            $this->assertInstanceOf(
                RequestWorkflowMailNotification::class,
                $job->notification,
            );
            $this->assertSame(
                $kind,
                $job->notification->payload()->kind,
            );

            $notifiable = $job->notifiables->first();
            $this->assertInstanceOf(User::class, $notifiable);
            $this->assertTrue($notifiable->is($recipient));
        }

        $this->assertSame('array', config('mail.default'));
        $this->assertCount(
            0,
            Mail::mailer()->getSymfonyTransport()->messages(),
        );
    }

    private function simulateQueuedMailFailure(
        SendQueuedNotifications $job,
    ): void {
        $exception = new \RuntimeException('Simulated queued mail failure.');
        $channelManager = \Mockery::mock(ChannelManager::class);
        $channelManager
            ->shouldReceive('sendNow')
            ->once()
            ->andThrow($exception);
        $thrown = null;

        try {
            $job->handle($channelManager);
        } catch (\RuntimeException $failure) {
            $thrown = $failure;
        }

        $this->assertSame($exception, $thrown);
    }

    /**
     * @param  class-string  $type
     * @param  array<string, mixed>  $expected
     */
    private function assertNotification(
        User $recipient,
        string $type,
        array $expected,
    ): void {
        $notification = $recipient->notifications()->sole();

        $this->assertNotificationRecord(
            $notification,
            $recipient,
            $type,
            $expected,
        );
    }

    /**
     * @param  class-string  $type
     * @param  array<string, mixed>  $expected
     */
    private function assertNotificationRecord(
        DatabaseNotification $notification,
        User $recipient,
        string $type,
        array $expected,
    ): void {
        $this->assertSame($type, $notification->type);
        $this->assertSame(
            $recipient->getKey(),
            (int) $notification->notifiable_id,
        );
        $this->assertNull($notification->read_at);
        $this->assertSame(NotificationContract::KEYS, array_keys($notification->data));
        $this->assertSame($expected, $notification->data);
    }

    /**
     * @return array{User, User, User, User, User}
     */
    private function workflowUsers(string $prefix): array
    {
        $branch = $this->branch($prefix);

        return [
            $this->user($branch, 'User', "{$prefix} Requester"),
            $this->user($branch, 'approver', "{$prefix} First Approver"),
            $this->user($branch, 'approver', "{$prefix} Second Approver"),
            $this->user($branch, 'approver', "{$prefix} Third Approver"),
            $this->user($branch, 'User', "{$prefix} Unrelated User"),
        ];
    }

    private function branch(string $prefix): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$prefix}-{$number}",
            'branch_name' => "{$prefix} Test Branch",
            'branch' => 'Test Company',
            'acronym' => 'TEST',
        ]);
    }

    private function user(
        Branch $branch,
        string $role,
        string $name,
    ): User {
        $number = ++$this->sequence;
        $user = User::factory()->create([
            'name' => $name,
            'firstName' => "Notifier{$number}",
            'lastName' => 'Decision',
            'contact' => "0917000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "decision-notification-{$number}",
            'employee_id' => "DECISION-NOTIF-{$number}",
        ]);
        $user->assignRole($role);

        return $this->giveUsableSignature($user);
    }

    private function requestForm(
        User $requester,
        RequestStatus $status = RequestStatus::Pending,
        RequestFormType $formType = RequestFormType::StockRequisition,
    ): RequestForm {
        $branch = Branch::query()
            ->where('branch_code', $requester->branch_code)
            ->firstOrFail();
        $requestForm = new RequestForm([
            'kind_of_request' => 'For Stock',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'PRIVATE-FORM-DATA',
                'sensitive_reference' => 'PRIVATE-FORM-DATA',
                'arbitrary_url' => 'https://private.example/path',
            ],
        ]);
        $requestForm->request_code = sprintf(
            '%s-SRL-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = $formType;
        $requestForm->status = $status;
        $requestForm->grand_total = '250.00';
        $requestForm->requester()->associate($requester);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function approvalStep(
        RequestForm $requestForm,
        User $approver,
        int $level,
        ApprovalStepStatus $status,
        ApprovalStepType $stepType,
    ): RequestApprovalStep {
        $step = new RequestApprovalStep;
        $step->level = $level;
        $step->approver_id = $approver->id;
        $step->step_type = $stepType;
        $step->status = $status;
        $step->acted_at = $status === ApprovalStepStatus::Approved
            ? now()
            : null;
        $requestForm->approvalSteps()->save($step);

        return $step;
    }

    private function decisionUrl(RequestForm $requestForm): string
    {
        return "/api/v1/approver/request-forms/{$requestForm->id}/decision";
    }

    /**
     * @return array<string, array{RequestFormType}>
     */
    public static function supportedRequestFormTypes(): array
    {
        return [
            'stock requisition' => [RequestFormType::StockRequisition],
            'purchase order' => [RequestFormType::PurchaseOrder],
            'cash disbursement' => [RequestFormType::CashDisbursement],
            'check issuance' => [RequestFormType::CheckIssuance],
        ];
    }

    /**
     * @return array<string, array{RequestStatus}>
     */
    public static function terminalRequestStatuses(): array
    {
        return [
            'canceled' => [RequestStatus::Canceled],
            'completed' => [RequestStatus::Completed],
            'disapproved' => [RequestStatus::Disapproved],
        ];
    }
}
