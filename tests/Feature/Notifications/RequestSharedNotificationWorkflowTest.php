<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationKind;
use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\Branch;
use App\Models\RequestForm;
use App\Models\User;
use App\Notifications\RequestSharedNotification;
use App\Notifications\RequestWorkflowMailNotification;
use App\Services\RequestSharingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\NotificationContract;
use Tests\TestCase;
use Throwable;

class RequestSharedNotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }

    }

    public function test_sharing_one_request_notifies_only_its_eligible_recipient(): void
    {
        [$sharer, $recipient, $otherRecipient, $unrelatedUser] =
            $this->shareUsers('ONE');
        $requestForm = $this->completedRequest(
            $sharer,
            RequestFormType::PurchaseOrder,
        );

        Sanctum::actingAs($sharer);

        $this->postJson($this->shareUrl($requestForm), [
            'user_ids' => [$recipient->id],
        ])
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Request shared successfully.',
                'data' => [
                    'request_id' => $requestForm->id,
                    'shared_count' => 1,
                ],
            ]);

        $this->assertDatabaseCount('request_shares', 1);
        $this->assertDatabaseHas('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
        $this->assertSharedNotification($recipient, $requestForm, $sharer);
        $this->assertSame(0, $sharer->notifications()->count());
        $this->assertSame(0, $otherRecipient->notifications()->count());
        $this->assertSame(0, $unrelatedUser->notifications()->count());

        $serialized = json_encode($recipient->notifications()->sole()->data);
        foreach ([
            $sharer->email,
            $recipient->email,
            'PRIVATE-FORM-DATA',
            'PRIVATE-COMMENT',
            'private/storage/path.pdf',
            'private-original.pdf',
            'https://client.example/private-request',
            'notifiable_id',
            'notifiable_type',
        ] as $privateValue) {
            $this->assertStringNotContainsString(
                $privateValue,
                (string) $serialized,
            );
        }
    }

    public function test_sharing_with_multiple_recipients_creates_one_private_notification_each(): void
    {
        [$sharer, $firstRecipient, $secondRecipient, $unselectedRecipient] =
            $this->shareUsers('MULTI');
        $requestForm = $this->completedRequest($sharer);

        Queue::fake();
        Sanctum::actingAs($sharer);

        $this->postJson($this->shareUrl($requestForm), [
            'user_ids' => [$firstRecipient->id, $secondRecipient->id],
        ])
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Request shared successfully.',
                'data' => [
                    'request_id' => $requestForm->id,
                    'shared_count' => 2,
                ],
            ]);

        $this->assertDatabaseCount('request_shares', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSharedNotification(
            $firstRecipient,
            $requestForm,
            $sharer,
        );
        $this->assertSharedNotification(
            $secondRecipient,
            $requestForm,
            $sharer,
        );
        $this->assertSame(0, $sharer->notifications()->count());
        $this->assertSame(0, $unselectedRecipient->notifications()->count());

        $this->assertQueuedSharedMailNotifications(
            [
                $firstRecipient->id,
                $secondRecipient->id,
            ],
            $requestForm->id,
        );
        $this->simulateQueuedMailFailure(
            Queue::pushed(SendQueuedNotifications::class)->first(),
        );
        $this->assertSame(RequestStatus::Completed, $requestForm->fresh()->status);
        $this->assertDatabaseCount('request_shares', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_separate_share_operations_notify_only_the_new_recipient_each_time(): void
    {
        [$sharer, $firstRecipient, $secondRecipient, $unselectedRecipient] =
            $this->shareUsers('SEPARATE');
        $requestForm = $this->completedRequest($sharer);

        Queue::fake();
        Sanctum::actingAs($sharer);

        $this->postJson($this->shareUrl($requestForm), [
            'user_ids' => [$firstRecipient->id],
        ])->assertCreated();

        $firstNotification = $firstRecipient->notifications()->sole();
        $firstPayload = $firstNotification->data;

        $this->postJson($this->shareUrl($requestForm), [
            'user_ids' => [$secondRecipient->id],
        ])
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Request shared successfully.',
                'data' => [
                    'request_id' => $requestForm->id,
                    'shared_count' => 1,
                ],
            ]);
        $this->assertQueuedSharedMailNotifications(
            [$firstRecipient->id, $secondRecipient->id],
            $requestForm->id,
        );

        $this->assertDatabaseCount('request_shares', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(
            $firstPayload,
            $firstRecipient->notifications()
                ->find($firstNotification->id)?->data,
        );
        $this->assertSame(1, $firstRecipient->notifications()->count());
        $this->assertSharedNotification(
            $secondRecipient,
            $requestForm,
            $sharer,
        );
        $this->assertSame(0, $unselectedRecipient->notifications()->count());
        $this->assertSame(0, $sharer->notifications()->count());
    }

    public function test_ineligible_recipient_rejects_the_whole_share_without_notifications(): void
    {
        [$sharer, $eligibleRecipient] = $this->shareUsers('INELIGIBLE');
        $adminRecipient = $this->user(
            $this->branch('INELIGIBLE-ADMIN'),
            'Admin',
            'Ineligible Admin',
        );
        $requestForm = $this->completedRequest($sharer);

        Queue::fake();
        Sanctum::actingAs($sharer);

        $this->postJson($this->shareUrl($requestForm), [
            'user_ids' => [$eligibleRecipient->id, $adminRecipient->id],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);

        $this->assertDatabaseCount('request_shares', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
        $this->assertSame(0, $eligibleRecipient->notifications()->count());
        $this->assertSame(0, $adminRecipient->notifications()->count());
        $this->assertSame(0, $sharer->notifications()->count());
    }

    public function test_service_rejects_a_forged_non_owner_sharer_without_side_effects(): void
    {
        [$owner, $recipient, $forgedSharer] = $this->shareUsers('FORGED');
        $requestForm = $this->completedRequest($owner);
        $thrown = null;

        try {
            app(RequestSharingService::class)->share(
                $requestForm,
                $forgedSharer,
                [$recipient->id],
            );
        } catch (AuthorizationException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $thrown);
        $this->assertDatabaseCount('request_shares', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertSame(0, $recipient->notifications()->count());
        $this->assertSame(0, $forgedSharer->notifications()->count());
    }

    public function test_existing_recipient_is_not_notified_again(): void
    {
        [$sharer, $recipient] = $this->shareUsers('EXISTING');
        $requestForm = $this->completedRequest($sharer);

        Queue::fake();
        Sanctum::actingAs($sharer);

        $payload = ['user_ids' => [$recipient->id]];
        $this->postJson($this->shareUrl($requestForm), $payload)
            ->assertCreated();
        $this->assertQueuedSharedMailNotifications(
            [$recipient->id],
            $requestForm->id,
        );

        $historical = $recipient->notifications()->sole();
        $historicalData = $historical->data;

        $this->postJson($this->shareUrl($requestForm), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids']);

        $this->assertDatabaseCount('request_shares', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(
            $historicalData,
            $recipient->notifications()->find($historical->id)?->data,
        );
        $this->assertSame(0, $sharer->notifications()->count());
        $this->assertQueuedSharedMailNotifications(
            [$recipient->id],
            $requestForm->id,
        );
    }

    public function test_notification_persistence_failure_rolls_back_all_new_shares(): void
    {
        config(['queue.default' => 'database']);

        [$sharer, $firstRecipient, $secondRecipient] =
            $this->shareUsers('ROLLBACK');
        $requestForm = $this->completedRequest($sharer);
        $thrown = null;

        DB::statement(
            'CREATE TRIGGER fail_second_shared_notification_insert '
            .'BEFORE INSERT ON notifications '
            .'WHEN (SELECT COUNT(*) FROM notifications) >= 1 '
            ."BEGIN SELECT RAISE(ABORT, 'Simulated notification failure'); END"
        );

        try {
            app(RequestSharingService::class)->share(
                $requestForm,
                $sharer,
                [$firstRecipient->id, $secondRecipient->id],
            );
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS fail_second_shared_notification_insert',
            );
        }

        $this->assertNotNull(
            $thrown,
            'Expected the notification insert to fail.',
        );
        $this->assertDatabaseCount('request_shares', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('request_forms', [
            'id' => $requestForm->id,
            'status' => RequestStatus::Completed->value,
            'request_code' => $requestForm->request_code,
            'form_type' => RequestFormType::StockRequisition->value,
        ]);
        $this->assertSame(0, $firstRecipient->notifications()->count());
        $this->assertSame(0, $secondRecipient->notifications()->count());
    }

    public function test_revocation_preserves_the_historical_notification(): void
    {
        [$sharer, $recipient] = $this->shareUsers('REVOKE');
        $requestForm = $this->completedRequest($sharer);

        Sanctum::actingAs($sharer);

        $this->postJson($this->shareUrl($requestForm), [
            'user_ids' => [$recipient->id],
        ])->assertCreated();

        $historical = $recipient->notifications()->sole();
        $historicalData = $historical->data;

        $this->deleteJson($this->revokeUrl($requestForm, $recipient))
            ->assertOk()
            ->assertExactJson([
                'message' => 'Shared recipient removed successfully.',
                'data' => [
                    'request_id' => $requestForm->id,
                    'recipient_id' => $recipient->id,
                ],
            ]);

        $this->assertDatabaseMissing('request_shares', [
            'request_form_id' => $requestForm->id,
            'recipient_id' => $recipient->id,
        ]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(
            $historicalData,
            $recipient->notifications()->find($historical->id)?->data,
        );
        $this->assertSame(0, $sharer->notifications()->count());
    }

    private function assertSharedNotification(
        User $recipient,
        RequestForm $requestForm,
        User $sharer,
    ): DatabaseNotification {
        $notification = $recipient->notifications()->sole();

        $this->assertSame(RequestSharedNotification::class, $notification->type);
        $this->assertSame(
            (int) $recipient->getKey(),
            (int) $notification->notifiable_id,
        );
        $this->assertNull($notification->read_at);
        $this->assertSame(
            NotificationContract::KEYS,
            array_keys($notification->data),
        );
        foreach ([
            'user_id',
            'requester_id',
            'recipient_id',
            'sharer_id',
            'notifiable_id',
            'notifiable_type',
            'email',
            'comment',
            'attachments',
            'storage_path',
            'form_data',
            'url',
        ] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $notification->data);
        }
        $this->assertSame([
            'kind' => 'request_shared',
            'title' => "Request shared: {$requestForm->request_code}",
            'message' => "{$sharer->name} shared request {$requestForm->request_code} with you.",
            'target' => 'shared_request',
            'request_id' => $requestForm->id,
            'request_code' => $requestForm->request_code,
            'form_type' => $requestForm->form_type->value,
            'status' => RequestStatus::Completed->value,
            'approval_level' => null,
            'approval_step_type' => null,
            'actor_name' => $sharer->name,
        ], $notification->data);

        return $notification;
    }

    /**
     * @param  list<int>  $recipientIds
     */
    private function assertQueuedSharedMailNotifications(
        array $recipientIds,
        int $requestId,
    ): void {
        Queue::assertPushedTimes(
            SendQueuedNotifications::class,
            count($recipientIds),
        );

        $queuedRecipientIds = [];

        foreach (Queue::pushed(SendQueuedNotifications::class) as $job) {
            $this->assertSame('notifications', $job->queue);
            $this->assertSame(['mail'], $job->channels);
            $this->assertTrue($job->afterCommit);
            $this->assertTrue($job->shouldBeEncrypted);
            $this->assertInstanceOf(
                RequestWorkflowMailNotification::class,
                $job->notification,
            );
            $this->assertSame(
                NotificationKind::RequestShared,
                $job->notification->payload()->kind,
            );
            $this->assertSame(
                $requestId,
                $job->notification->payload()->requestId,
            );
            $queuedRecipientIds[] = (int) $job->notifiables->first()->getKey();
        }

        sort($recipientIds);
        sort($queuedRecipientIds);
        $this->assertSame($recipientIds, $queuedRecipientIds);
        $this->assertNoSynchronousMailDelivery();
    }

    private function assertNoSynchronousMailDelivery(): void
    {
        $this->assertSame('array', config('mail.default'));
        $this->assertCount(
            0,
            Mail::mailer()->getSymfonyTransport()->messages(),
        );
    }

    private function simulateQueuedMailFailure(
        SendQueuedNotifications $job,
    ): void {
        $exception = new RuntimeException('Simulated queued mail failure.');
        $channelManager = \Mockery::mock(ChannelManager::class);
        $channelManager
            ->shouldReceive('sendNow')
            ->once()
            ->andThrow($exception);
        $thrown = null;

        try {
            $job->handle($channelManager);
        } catch (RuntimeException $failure) {
            $thrown = $failure;
        }

        $this->assertSame($exception, $thrown);
    }

    /**
     * @return array{User, User, User, User}
     */
    private function shareUsers(string $prefix): array
    {
        $branch = $this->branch($prefix);

        return [
            $this->user($branch, 'User', "{$prefix} Sharer"),
            $this->user($branch, 'User', "{$prefix} First Recipient"),
            $this->user($branch, 'approver', "{$prefix} Second Recipient"),
            $this->user($branch, 'User', "{$prefix} Unselected User"),
        ];
    }

    private function branch(string $code): Branch
    {
        $number = ++$this->sequence;

        return Branch::factory()->create([
            'branch_code' => "{$code}-{$number}",
            'branch_name' => "{$code} Test Branch",
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
            'firstName' => "Share{$number}",
            'lastName' => 'Workflow',
            'email_verified_at' => now(),
            'contact' => "0915000{$number}",
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'Employee',
            'userName' => "shared-notification-{$number}",
            'employee_id' => "SHARED-NOTIF-{$number}",
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function completedRequest(
        User $sharer,
        RequestFormType $formType = RequestFormType::StockRequisition,
    ): RequestForm {
        $branch = Branch::query()
            ->where('branch_code', $sharer->branch_code)
            ->firstOrFail();
        $requestForm = new RequestForm([
            'kind_of_request' => 'Office Supplies',
            'currency' => 'PHP',
            'form_data' => [
                'purpose' => 'PRIVATE-FORM-DATA',
                'comment' => 'PRIVATE-COMMENT',
                'attachment_path' => 'private/storage/path.pdf',
                'original_filename' => 'private-original.pdf',
                'url' => 'https://client.example/private-request',
            ],
        ]);
        $requestForm->request_code = sprintf(
            '%s-SHARE-%07d',
            $branch->branch_code,
            ++$this->sequence,
        );
        $requestForm->form_type = $formType;
        $requestForm->status = RequestStatus::Completed;
        $requestForm->grand_total = '100.00';
        $requestForm->requester()->associate($sharer);
        $requestForm->branch()->associate($branch);
        $requestForm->save();

        return $requestForm;
    }

    private function shareUrl(RequestForm $requestForm): string
    {
        return "/api/v1/request-forms/{$requestForm->id}/shares";
    }

    private function revokeUrl(
        RequestForm $requestForm,
        User $recipient,
    ): string {
        return "/api/v1/request-forms/{$requestForm->id}/shares/{$recipient->id}";
    }
}
