<?php

namespace App\Services\Report;

use App\Models\UserProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AccessReportQueryService
{
    /**
     * Build the base query for user project access reporting with all filter dimensions.
     */
    public function buildQuery(Request|array $filters): Builder
    {
        $get = fn (string $key) => $filters instanceof Request ? $filters->input($key) : ($filters[$key] ?? null);
        $has = fn (string $key) => $filters instanceof Request ? $filters->filled($key) : ! empty($filters[$key]);

        $query = UserProjectAccess::with([
            'user.employee.department',
            'user.employee.designation',
            'user.employee.province',
            'user.employee.zone',
            'user.employee.region',
            'user.employee.branch',
            'project',
            'roles',
            'permissions',
        ]);

        if ($has('project_id')) {
            $query->where('project_id', (int) $get('project_id'));
        }

        if ($has('status')) {
            $query->where('status', strtoupper($get('status')));
        }

        if ($has('role_id')) {
            $roleId = (int) $get('role_id');
            $query->whereHas('roles', fn ($q) => $q->where('project_roles.id', $roleId));
        }

        if ($has('role_code')) {
            $roleCode = $get('role_code');
            $query->whereHas('roles', fn ($q) => $q->where('project_roles.code', $roleCode));
        }

        if ($has('department_code')) {
            $dep = $get('department_code');
            $query->whereHas('user.employee', fn ($q) => $q->where('department_code', $dep));
        }

        if ($has('designation_code')) {
            $des = $get('designation_code');
            $query->whereHas('user.employee', fn ($q) => $q->where('designation_code', $des));
        }

        if ($has('province_code')) {
            $prov = $get('province_code');
            $query->whereHas('user.employee', fn ($q) => $q->where('province_code', $prov));
        }

        if ($has('zonal_code')) {
            $zone = $get('zonal_code');
            $query->whereHas('user.employee', fn ($q) => $q->where('zonal_code', $zone));
        }

        if ($has('region_code')) {
            $reg = $get('region_code');
            $query->whereHas('user.employee', fn ($q) => $q->where('region_code', $reg));
        }

        if ($has('branch_code')) {
            $branch = $get('branch_code');
            $query->whereHas('user.employee', fn ($q) => $q->where('branch_code', $branch));
        }

        if ($has('search')) {
            $search = $get('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('username', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%")
                       ->orWhere('employee_code', 'like', "%{$search}%");
                })->orWhereHas('project', function ($pq) use ($search) {
                    $pq->where('code', 'like', "%{$search}%")
                       ->orWhere('name', 'like', "%{$search}%");
                });
            });
        }

        if ($has('from_date')) {
            $query->where('created_at', '>=', $get('from_date'));
        }

        if ($has('to_date')) {
            $query->where('created_at', '<=', $get('to_date'));
        }

        return $query->orderBy('project_id')->orderBy('user_id');
    }
}
