<?php

namespace App\Services\Report;

use App\Models\UserProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Query builder service compiling multi-dimensional filters for access reports.
 */
class AccessReportQueryService
{
    /**
     * Build the base query for user project access reporting with all filter dimensions.
     *
     * @param Request|array<string, mixed> $filters
     * @return Builder
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

        if ($has('role_id') || $has('role_code')) {
            $query->whereHas('roles', function ($q) use ($has, $get) {
                if ($has('role_id')) {
                    $q->where('project_roles.id', (int) $get('role_id'));
                }
                if ($has('role_code')) {
                    $q->where('project_roles.code', $get('role_code'));
                }
            });
        }

        $orgFilters = array_filter([
            'department_code' => $has('department_code') ? $get('department_code') : null,
            'designation_code' => $has('designation_code') ? $get('designation_code') : null,
            'province_code' => $has('province_code') ? $get('province_code') : null,
            'zonal_code' => $has('zonal_code') ? $get('zonal_code') : null,
            'region_code' => $has('region_code') ? $get('region_code') : null,
            'branch_code' => $has('branch_code') ? $get('branch_code') : null,
        ]);

        if (! empty($orgFilters)) {
            $query->whereHas('user.employee', function ($q) use ($orgFilters) {
                foreach ($orgFilters as $column => $value) {
                    $q->where($column, $value);
                }
            });
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
