<?php

use App\Jobs\CheckProjectHealthJob;
use App\Jobs\SyncProjectCatalogJob;
use App\Models\Project;
use App\Models\SsoAuthCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Scheduled outbox worker processing sync jobs every minute
Schedule::command('giam:process-sync-jobs')->everyMinute()->name('process-outbox-sync-jobs');

// Recurring project health check across all active registered projects
Schedule::call(function () {
    $projects = Project::where('status', '!=', 'disabled')
        ->whereHas('integration')
        ->with('integration')
        ->get();

    foreach ($projects as $project) {
        dispatch(new CheckProjectHealthJob($project));
    }
})->everyFiveMinutes()->name('check-projects-health');

// Scheduled daily synchronization job for downstream RBAC catalogs
Schedule::call(function () {
    $projects = Project::where('status', '!=', 'disabled')
        ->whereHas('integration', fn ($q) => $q->where('sync_enabled', true))
        ->with('integration')
        ->get();

    foreach ($projects as $project) {
        dispatch(new SyncProjectCatalogJob($project));
    }
})->dailyAt('01:00')->name('sync-project-catalogs-daily');

// Scheduled daily purge of expired / redeemed SSO authorization codes
Schedule::call(function () {
    SsoAuthCode::where('expires_at', '<', now()->subHours(24))
        ->orWhereNotNull('used_at')
        ->where('created_at', '<', now()->subHours(24))
        ->delete();
})->dailyAt('02:00')->name('purge-expired-sso-codes');
