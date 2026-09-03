<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health Check endpoint
Route::get('/health-check', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'GIAM Central Identity API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

/* version 1 routes */
require __DIR__ . '/v1.php';

/* Single Sign-On (SSO) routes */
require __DIR__ . '/sso.php';
