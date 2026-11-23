<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;
use App\Notifications\RequestWorkflowMailNotification;
use App\Support\NotificationPayload;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use stdClass;
use Tests\Support\NotificationContract;
use Tests\TestCase;

class RequestWorkflowMailNotificationTest extends TestCase
{
    public function test_smtp_timeout_uses_the_environment_backed_configuration(): void
    {
        $this->assertSame(
            (int) env('MAIL_TIMEOUT', 30),
            config('mail.mailers.smtp.timeout'),
        );
    }

    public function test_it_is_encrypted_queued_mail_only_and_after_commit(): void
    {
        $notification = new RequestWorkflowMailNotification(
            $this->payload(
                NotificationKind::RequestApproved,
                NotificationTarget::MyRequest,
            ),
        );

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame(['mail'], $notification->via(new stdClass));
        $this->assertTrue($notification->afterCommit);
        $this->assertSame('notifications', $notification->queue);

        $job = new SendQueuedNotifications(
            new stdClass,
            $notification,
            ['mail'],
        );

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([60, 300], $job->backoff());
        $this->assertTrue($job->afterCommit);
        $this->assertTrue($job->shouldBeEncrypted);
        $this->assertLessThan(
            config('queue.connections.database.retry_after'),
            $job->timeout,
        );
        $this->assertSame('database-uuids', config('queue.failed.driver'));
    }

    public function test_it_preserves_only_the_immutable_payload_snapshot(): void
    {
        $payload = $this->payload(
            NotificationKind::RequestApproved,
            NotificationTarget::MyRequest,
        );
        $notification = new RequestWorkflowMailNotification($payload);
        $property = new ReflectionProperty($notification, 'payload');

        $this->assertTrue($property->isReadOnly());
        $this->assertSame(NotificationPayload::class, (string) $property->getType());
        $this->assertSame($payload, $notification->payload());
        $this->assertSame(
            NotificationContract::KEYS,
            array_keys($notification->payload()->toArray()),
        );

        $serialized = serialize($notification);
        foreach ([
            'User',
            'RequestForm',
            'user@example.test',
            'account_no',
            'attachment_path',
            'comment',
            'form_data',
            'https://client.example.test',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serialized);
        }
    }

    #[DataProvider('workflowPayloads')]
    public function test_it_uses_the_trusted_title_message_and_request_summary(
        NotificationKind $kind,
        NotificationTarget $target,
        string $title,
        string $message,
        string $status,
    ): void {
        config(['app.frontend_url' => 'https://frontend.example.test/']);

        $mail = (new RequestWorkflowMailNotification(
            $this->payload($kind, $target, $title, $message, $status),
        ))->toMail(new stdClass);

        $this->assertSame($title, $mail->subject);
        $this->assertSame([
            $message,
            'Request code: REQ-000123',
            'Form type: Purchase Order Requisition Slip',
            "Status: {$status}",
        ], $mail->introLines);
    }

    public static function workflowPayloads(): array
    {
        return [
            'action required' => [
                NotificationKind::RequestActionRequired,
                NotificationTarget::AssignedRequest,
                'Action required: REQ-000123',
                'Request REQ-000123 is waiting for your approval.',
                'Pending',
            ],
            'approved' => [
                NotificationKind::RequestApproved,
                NotificationTarget::MyRequest,
                'Request approved: REQ-000123',
                'Jane Doe approved your request REQ-000123.',
                'Ongoing',
            ],
            'disapproved' => [
                NotificationKind::RequestDisapproved,
                NotificationTarget::MyRequest,
                'Request disapproved: REQ-000123',
                'Jane Doe disapproved your request REQ-000123.',
                'Disapproved',
            ],
            'completed' => [
                NotificationKind::RequestCompleted,
                NotificationTarget::MyRequest,
                'Request completed: REQ-000123',
                'Request REQ-000123 is now completed.',
                'Completed',
            ],
            'shared' => [
                NotificationKind::RequestShared,
                NotificationTarget::SharedRequest,
                'Request shared: REQ-000123',
                'Jane Doe shared request REQ-000123 with you.',
                'Completed',
            ],
        ];
    }

    public function test_action_path_is_allowlisted_for_every_notification_target(): void
    {
        config(['app.frontend_url' => 'https://frontend.example.test/base/']);

        foreach ([
            [
                NotificationTarget::MyRequest,
                NotificationKind::RequestApproved,
                '/request',
            ],
            [
                NotificationTarget::AssignedRequest,
                NotificationKind::RequestActionRequired,
                '/approver/request',
            ],
            [
                NotificationTarget::SharedRequest,
                NotificationKind::RequestShared,
                '/shared-requests',
            ],
        ] as [$target, $kind, $path]) {
            $mail = (new RequestWorkflowMailNotification(
                $this->payload($kind, $target),
            ))->toMail(new stdClass);

            $this->assertSame(
                "https://frontend.example.test/base{$path}",
                $mail->actionUrl,
            );
        }
    }

    public function test_client_supplied_target_or_url_cannot_control_the_action_path(): void
    {
        config(['app.frontend_url' => 'https://frontend.example.test/']);

        $data = NotificationContract::payload();
        $data['target'] = 'https://client.example.test/attacker';

        $this->expectException(\InvalidArgumentException::class);
        NotificationPayload::fromArray($data);
    }

    public function test_extra_client_url_is_ignored_and_not_retained(): void
    {
        config(['app.frontend_url' => 'https://frontend.example.test/']);

        $data = NotificationContract::payload();
        $data['url'] = 'https://client.example.test/attacker';
        $payload = NotificationPayload::fromArray($data);
        $mail = (new RequestWorkflowMailNotification($payload))
            ->toMail(new stdClass);

        $this->assertArrayNotHasKey('url', $payload->toArray());
        $this->assertSame(
            'https://frontend.example.test/request',
            $mail->actionUrl,
        );
    }

    public function test_backend_app_url_is_not_used_when_frontend_url_is_missing(): void
    {
        config([
            'app.frontend_url' => null,
            'app.url' => 'https://backend.example.test',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The frontend URL must be configured to send workflow email.',
        );

        (new RequestWorkflowMailNotification(
            $this->payload(
                NotificationKind::RequestApproved,
                NotificationTarget::MyRequest,
            ),
        ))->toMail(new stdClass);
    }

    #[DataProvider('invalidFrontendUrls')]
    public function test_frontend_url_rejects_unsafe_or_ambiguous_values(
        string $frontendUrl,
    ): void {
        config(['app.frontend_url' => $frontendUrl]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The frontend URL must be an HTTP(S) origin or base path without credentials, a query, or a fragment.',
        );

        (new RequestWorkflowMailNotification(
            $this->payload(
                NotificationKind::RequestApproved,
                NotificationTarget::MyRequest,
            ),
        ))->toMail(new stdClass);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidFrontendUrls(): array
    {
        return [
            'missing scheme' => ['//frontend.example.test'],
            'javascript scheme' => ['javascript:alert(1)'],
            'credentials' => ['https://user:secret@frontend.example.test'],
            'query string' => ['https://frontend.example.test?next=/admin'],
            'fragment' => ['https://frontend.example.test/#private'],
        ];
    }

    private function payload(
        NotificationKind $kind,
        NotificationTarget $target,
        ?string $title = null,
        ?string $message = null,
        ?string $status = null,
    ): NotificationPayload {
        return new NotificationPayload(
            kind: $kind,
            title: $title ?? 'Trusted notification title',
            message: $message ?? 'Trusted notification message.',
            target: $target,
            requestId: 123,
            requestCode: 'REQ-000123',
            formType: 'Purchase Order Requisition Slip',
            status: $status ?? 'Ongoing',
            approvalLevel: $kind === NotificationKind::RequestShared ? null : 1,
            approvalStepType: $kind === NotificationKind::RequestShared
                ? null
                : 'approved_by',
            actorName: 'Jane Doe',
        );
    }
}
