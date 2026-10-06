<?php

use App\Http\Controllers\Api\SsoController;
use App\Http\Middleware\AuthenticateGiamSessionOrBearer;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Single Sign-On (SSO) Routes
|--------------------------------------------------------------------------
|
| Handles browser SSO authorization handoffs, server-to-server token
| exchanges, and cross-project session telemetry events.
|
*/

Route::prefix('v1')->group(function () {
    // Authenticated SSO Browser & API Authorization Handoff
    Route::middleware([AuthenticateGiamSessionOrBearer::class, 'giam.can_login', 'throttle:sso'])->group(function () {
        Route::match(['get', 'post'], '/sso/authorize', [SsoController::class, 'authorize']);
    });

    // Public / Server-to-Server SSO Exchange Endpoints
    Route::prefix('sso')->middleware('throttle:sso')->group(function () {
        Route::post('/token', [SsoController::class, 'token']);
        Route::post('/logout-telemetry', [SsoController::class, 'logoutTelemetry']);
    });
});
