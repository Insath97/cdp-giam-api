<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // A. Auth limiter (login attempts)
        RateLimiter::for('auth', function (Request $request) {
            $identifier = (string) ($request->input('login') ?? $request->input('username') ?? $request->input('email') ?? 'guest');
            $perMinute = (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 60);
            return Limit::perMinute($perMinute)->by($request->ip() . '|' . $identifier);
        });

        // B. Password reset limiter (forgot-password, reset, assistance)
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // C. SSO limiter (authorize, token exchange)
        RateLimiter::for('sso', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()?->id ?? $request->ip());
        });

        // D. Sensitive write limiter (user/employee creation, access assignment, role change, revoke)
        RateLimiter::for('sensitive-writes', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // E. Integration API limiter (server-to-server endpoints)
        RateLimiter::for('integration', function (Request $request) {
            $clientIdentity = $request->header('X-Client-ID')
                ?? $request->header('X-Project-Code')
                ?? $request->bearerToken()
                ?? $request->ip();
            return Limit::perMinute(60)->by((string) $clientIdentity);
        });

        // F. Catalog sync limiter
        RateLimiter::for('catalog-sync', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip());
        });

        // G. General authenticated API limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });
    }
}
