<?php

namespace App\Providers;

use App\Policies\DatabaseNotificationPolicy;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
