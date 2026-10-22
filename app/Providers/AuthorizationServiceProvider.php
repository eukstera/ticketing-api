<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Define the admin gate and the login rate limiter.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user): Response => $user->role?->role === 'Department Head'
            ? Response::allow()
            : Response::deny('Only Department Heads can manage this.'));

        Gate::define('view-new-tickets', fn (User $user): Response => $user->department_id === 1 || Gate::forUser($user)->allows('admin')
            ? Response::allow()
            : Response::deny('Only IT staff and Department Heads can see new tickets.'));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));

        RateLimiter::for('track', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('profile', fn (Request $request) => Limit::perMinute(10)->by(
            (string) ($request->user()?->id ?? $request->ip())
        ));
    }
}
