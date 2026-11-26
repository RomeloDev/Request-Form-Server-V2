<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Timeout(30)]
final class PasswordResetNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);

        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function toMail($notifiable): MailMessage
    {
        $actionUrl = $this->resetUrl($notifiable);
        $viewData = [
            'brandName' => 'SMCT Request Form',
            'logoPath' => resource_path('images/smct-logo.png'),
            'actionUrl' => $actionUrl,
            'expiresInMinutes' => config(
                'auth.passwords.'.config('auth.defaults.passwords').'.expire'
            ),
        ];

        return (new MailMessage)
            ->subject('Reset your password')
            ->action('Reset password', $actionUrl)
            ->view('mail.notifications.password-reset', $viewData)
            ->text('mail.notifications.password-reset-text', $viewData);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }
}
