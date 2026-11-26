<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\DatabaseNotificationPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            if (! $notifiable instanceof User) {
                throw new LogicException('Password reset links require a user account.');
            }

            $frontendUrl = config('app.frontend_url');

            if (! is_string($frontendUrl) || trim($frontendUrl) === '') {
                throw new LogicException('The frontend URL must be configured to send password resets.');
            }

            $frontendUrl = rtrim(trim($frontendUrl), '/');
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

            return $frontendUrl.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);
        });

        /*
         * DatabaseNotification lives in Illuminate\Notifications, so
         * Laravel's policy auto-discovery cannot map it to an App policy.
         * Register the policy explicitly for $this->authorize() checks.
         */
        Gate::policy(
            DatabaseNotification::class,
            DatabaseNotificationPolicy::class,
        );
    }
}
