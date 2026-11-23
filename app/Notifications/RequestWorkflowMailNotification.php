<?php

namespace App\Notifications;

use App\Enums\NotificationTarget;
use App\Support\NotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use LogicException;

#[Tries(3)]
#[Timeout(30)]
final class RequestWorkflowMailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly NotificationPayload $payload,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->payload->status;

        if ($status === null) {
            throw new LogicException(
                'Workflow mail notifications require a request status.'
            );
        }

        $frontendUrl = config('app.frontend_url');

        if (! is_string($frontendUrl) || trim($frontendUrl) === '') {
            throw new LogicException(
                'The frontend URL must be configured to send workflow email.'
            );
        }

        $frontendUrl = trim($frontendUrl);
        $parts = parse_url($frontendUrl);

        if (
            $parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new LogicException(
                'The frontend URL must be an HTTP(S) origin or base path without credentials, a query, or a fragment.'
            );
        }

        $path = match ($this->payload->target) {
            NotificationTarget::MyRequest => '/request',
            NotificationTarget::AssignedRequest => '/approver/request',
            NotificationTarget::SharedRequest => '/shared-requests',
        };

        return (new MailMessage)
            ->subject($this->payload->title)
            ->line($this->payload->message)
            ->line("Request code: {$this->payload->requestCode}")
            ->line("Form type: {$this->payload->formType}")
            ->line("Status: {$status}")
            ->action('View request', rtrim($frontendUrl, '/').$path);
    }

    public function payload(): NotificationPayload
    {
        return $this->payload;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }
}
