<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard/overview
     * Returns aggregate stats needed by the frontend dashboard.
     */
    public function overview(): JsonResponse
    {
        $totalProjects        = Application::count();
        $totalUsers           = User::count();
        $totalRoles           = DB::table('roles')->count();
        $totalPermissionGroups = DB::table('permission_groups')->count();
        $pendingRequests      = 0; // placeholder – extend when access-request module exists

        $recentActivity = [];
        if (class_exists(ActivityLog::class) && \Schema::hasTable('activity_logs')) {
            $recentActivity = ActivityLog::with('user')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($a) => [
                    'id'          => $a->id,
                    'action'      => $a->action,
                    'description' => $a->description ?? null,
                    'details'     => $a->description ?? null,
                    'causer'      => $a->user ? ['name' => $a->user->name] : null,
                    'created_at'  => $a->created_at,
                ])
                ->toArray();
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'total_projects'         => $totalProjects,
                'total_users'            => $totalUsers,
                'total_roles'            => $totalRoles,
                'total_permission_groups' => $totalPermissionGroups,
                'pending_requests'       => $pendingRequests,
                'recent_activity'        => $recentActivity,
                'projects_needing_attention' => [],
            ],
        ]);
    }

    /**
     * GET /api/v1/notifications/counts
     * Returns notification badge counts for the sidebar.
     */
    public function notificationCounts(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'pending_requests' => 0,
                'sync_failures'    => 0,
                'unread_activity'  => 0,
            ],
        ]);
    }
}
