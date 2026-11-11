<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Application service provider. Nothing is registered here; authorization and rate limits are in
 * AuthorizationServiceProvider.
 */
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
        if (filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOLEAN)) {
            URL::forceScheme('https');
        }
    }
}
