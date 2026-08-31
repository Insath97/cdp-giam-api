<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\DepartmentController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\ZonalController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\DesignationController;
use App\Http\Controllers\V1\CountryController;
use App\Http\Controllers\V1\GroupController;
use App\Http\Controllers\V1\ApplicationController;
use App\Http\Controllers\V1\ModuleController;
use App\Http\Controllers\V1\PermissionGroupController;
use App\Http\Controllers\V1\SyncController;
use App\Http\Controllers\V1\ReportController;
use App\Http\Controllers\V1\ActivityLogController;
use App\Http\Controllers\V1\DashboardController;
use App\Http\Controllers\V1\GiamSecurityController;
use Illuminate\Support\Facades\Route;

/* public routes */

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('jwt.cookie');
});

Route::middleware(['project.sync.auth'])->prefix('v1/sync')->group(function () {
    Route::post('roles-permissions', [SyncController::class, 'syncRolesAndPermissions']);
});

Route::middleware(['project.sync.auth'])->prefix('v1/sso')->group(function () {
    Route::post('exchange-token', [AuthController::class, 'exchangeSsoToken']);
});

/* protected routes */
Route::middleware(['jwt.cookie', 'auth:api'])->prefix('v1')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('sso/generate-token', [AuthController::class, 'generateSsoToken']);

    // Dashboard & Notifications
    Route::get('dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('notifications/counts', [DashboardController::class, 'notificationCounts']);

    // GIAM Security PIN
    Route::get('giam-security/pin/status', [GiamSecurityController::class, 'status']);
    Route::post('giam-security/pin/setup', [GiamSecurityController::class, 'setup']);
    Route::post('giam-security/pin/verify', [GiamSecurityController::class, 'verify']);
    Route::post('giam-security/pin/reset/{userId}', [GiamSecurityController::class, 'reset']);

    Route::get('permission-groups/list', [PermissionGroupController::class, 'list']);
    Route::apiResource('permission-groups', PermissionGroupController::class);

    Route::get('permissions/list', [PermissionController::class, 'getPermissionList']);
    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class);
    Route::get('users/{id}/applications/{appId}/access', [UserController::class, 'projectAccess']);
    Route::get('users/{id}/effective-access', [UserController::class, 'effectiveAccess']);
    Route::get('users/{id}/field-access', [UserController::class, 'getFieldAccess']);
    Route::post('users/{id}/field-access', [UserController::class, 'updateFieldAccess']);
    Route::patch('users/{id}/applications/{appId}', [UserController::class, 'updateApplicationAccess']);
    Route::delete('users/{id}/applications/{appId}', [UserController::class, 'removeApplicationAccess']);

    // Applications
    Route::get('applications/list', [ApplicationController::class, 'getActiveList']);
    Route::get('applications/{id}/catalog', [ApplicationController::class, 'catalog']);
    Route::patch('applications/{id}/toggle-status', [ApplicationController::class, 'toggleStatus']);
    Route::post('applications/{id}/sync-users', [ApplicationController::class, 'syncUsers']);
    Route::apiResource('applications', ApplicationController::class);

    // Modules
    Route::apiResource('modules', ModuleController::class);

    // Countries
    Route::apiResource('countries', CountryController::class);
    Route::prefix('countries')->group(function () {
        Route::patch('{id}/toggle-status', [CountryController::class, 'toggleStatus']);
        Route::get('list', [CountryController::class, 'getActiveList']);
    });

    // Provinces
    Route::apiResource('provinces', ProvinceController::class);
    Route::prefix('provinces')->group(function () {
        Route::patch('{id}/toggle-status', [ProvinceController::class, 'toggleStatus']);
        Route::get('list', [ProvinceController::class, 'getProvinceList']);
    });

    // Zonals (Zones)
    Route::apiResource('zonals', ZonalController::class);
    Route::prefix('zonals')->group(function () {
        Route::patch('{id}/toggle-status', [ZonalController::class, 'toggleStatus']);
        Route::get('list', [ZonalController::class, 'getZonalList']);
    });

    // Regions
    Route::apiResource('regions', RegionController::class);
    Route::prefix('regions')->group(function () {
        Route::patch('{id}/toggle-status', [RegionController::class, 'toggleStatus']);
        Route::get('list', [RegionController::class, 'getRegionList']);
    });

    // Branches
    Route::apiResource('branches', BranchController::class);
    Route::prefix('branches')->group(function () {
        Route::patch('{id}/toggle-status', [BranchController::class, 'toggleStatus']);
        Route::get('list', [BranchController::class, 'getBranchList']);
    });

    // Departments
    Route::apiResource('departments', DepartmentController::class);
    Route::prefix('departments')->group(function () {
        Route::get('{id}/designations', [DepartmentController::class, 'getDesignations']);
        Route::patch('{id}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    });

    // Designations
    Route::apiResource('designations', DesignationController::class);
    Route::prefix('designations')->group(function () {
        Route::get('list', [DesignationController::class, 'getActiveList']);
        Route::patch('{id}/toggle-status', [DesignationController::class, 'toggleStatus']);
    });

    // Groups
    Route::apiResource('groups', GroupController::class);
    Route::prefix('groups')->group(function () {
        Route::get('list', [GroupController::class, 'getActiveList']);
        Route::patch('{id}/toggle-status', [GroupController::class, 'toggleStatus']);
    });

    // Reports & Activity Logs
    Route::get('reports/access-summary', [ReportController::class, 'accessSummary']);
    Route::get('reports/access-summary/export', [ReportController::class, 'exportAccessSummary']);
    Route::get('activity-logs', [ActivityLogController::class, 'index']);
});
