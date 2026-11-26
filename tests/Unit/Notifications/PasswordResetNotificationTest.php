<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use LogicException;
use Tests\TestCase;

class PasswordResetNotificationTest extends TestCase
{
    public function test_it_is_encrypted_queued_mail_with_the_frontend_reset_url(): void
    {
        config()->set('app.frontend_url', 'https://frontend.example.test/base');
        $user = new User(['email' => 'user+reset@example.test']);
        $notification = new PasswordResetNotification('sensitive token');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame('notifications', $notification->queue);
        $this->assertTrue($notification->afterCommit);
        $this->assertSame([60, 300], $notification->backoff());

        $mail = $notification->toMail($user);
        $this->assertSame('Reset your password', $mail->subject);
        $this->assertSame([
            'html' => 'mail.notifications.password-reset',
            'text' => 'mail.notifications.password-reset-text',
        ], $mail->view);
        $this->assertSame('SMCT Request Form', $mail->viewData['brandName']);
        $this->assertSame(
            resource_path('images/smct-logo.png'),
            $mail->viewData['logoPath'],
        );
        $this->assertFileExists($mail->viewData['logoPath']);
        $this->assertSame(60, $mail->viewData['expiresInMinutes']);
        $this->assertSame(
            'https://frontend.example.test/base/reset-password?token=sensitive%20token&email=user%2Breset%40example.test',
            $mail->actionUrl,
        );

        $job = new SendQueuedNotifications($user, $notification, ['mail']);
        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
    }

    public function test_it_renders_the_branded_password_reset_email(): void
    {
        config()->set('app.frontend_url', 'https://frontend.example.test');

        $mail = (new PasswordResetNotification('sensitive token'))->toMail(
            new User(['email' => 'user+reset@example.test']),
        );

        $html = $this->renderHtml($mail);
        $text = view($mail->view['text'], $mail->viewData)->render();
        $mailerRenderedHtml = app('mailer')->render(
            $mail->view,
            $mail->viewData,
        );

        foreach ([$html, $text] as $rendered) {
            $this->assertStringContainsString('SMCT Request Form', $rendered);
            $this->assertStringContainsString('Reset your password', $rendered);
            $this->assertStringContainsString('expires in 60 minutes', $rendered);
            $this->assertStringNotContainsString('Regards,', $rendered);
            $this->assertStringNotContainsString('Laravel', $rendered);
        }

        $this->assertStringContainsString(
            'https://frontend.example.test/reset-password?token=sensitive%20token&amp;email=user%2Breset%40example.test',
            $html,
        );
        $this->assertStringContainsString(
            'https://frontend.example.test/reset-password?token=sensitive%20token&email=user%2Breset%40example.test',
            $text,
        );
        $this->assertStringContainsString('cid:smct-logo.png', $html);
        $this->assertStringContainsString('#162f65', $html);
        $this->assertStringContainsString(
            'data:image/png;base64,',
            $mailerRenderedHtml,
        );
    }

    public function test_it_rejects_an_unsafe_frontend_url(): void
    {
        config()->set('app.frontend_url', 'https://user:secret@frontend.example.test/reset');

        $this->expectException(LogicException::class);

        (new PasswordResetNotification('token'))->toMail(
            new User(['email' => 'user@example.test']),
        );
    }

    private function renderHtml(MailMessage $mail): string
    {
        $message = new class
        {
            public function embed(string $path): string
            {
                return 'cid:'.basename($path);
            }
        };

        return view(
            $mail->view['html'],
            [...$mail->viewData, 'message' => $message],
        )->render();
    }
}
