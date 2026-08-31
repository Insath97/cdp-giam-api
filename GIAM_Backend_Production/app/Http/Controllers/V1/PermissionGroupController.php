<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePermissionGroupRequest;
use App\Http\Requests\UpdatePermissionGroupRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Models\PermissionGroup;
use Spatie\Permission\Models\Permission;
use App\Traits\ActivityLogTrait;

class PermissionGroupController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:Permission Group Index', only: ['index', 'show', 'list']),
            new Middleware('permission:Permission Group Create', only: ['store']),
            new Middleware('permission:Permission Group Update', only: ['update']),
            new Middleware('permission:Permission Group Delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = PermissionGroup::with('permissions');

            if ($request->has('application_id')) {
                $query->where('application_id', $request->application_id);
            }

            if ($request->has('search') && $request->search != '') {
                $search = $request->search;
                $query->where('name', 'LIKE', "%{$search}%");
            }

            $query->orderBy('name', 'asc');
            $groups = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission groups retrieved successfully',
                'data' => $groups
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permission groups',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function store(CreatePermissionGroupRequest $request)
    {
        try {
            $data = $request->validated();
            // Phase L - Production Hardening: Enforce GIAM Internal Scope
            if (isset($data['application_id']) && $data['application_id'] !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot create project-owned permission groups via GIAM internal API.'
                ], 403);
            }

            $group = PermissionGroup::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'application_id' => null,
                'source' => 'native',
            ]);

            if (isset($data['permissions']) && count($data['permissions']) > 0) {
                $group->permissions()->sync($data['permissions']);
            }

            $this->logActivity('CREATE', 'Permission Group', "Created permission group: {$group->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission group created successfully',
                'data' => $group->load('permissions')
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create permission group',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $group = PermissionGroup::with('permissions')->find($id);

            if (!$group) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission group not found',
                    'data' => []
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Permission group retrieved successfully',
                'data' => $group
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permission group',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function update(UpdatePermissionGroupRequest $request, string $id)
    {
        try {
            $data = $request->validated();
            $group = PermissionGroup::find($id);

            if (!$group) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission group not found',
                    'data' => []
                ], 404);
            }

            // Phase L - Production Hardening: Enforce GIAM Internal Scope
            if ($group->application_id !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot modify project-owned permission groups via GIAM internal API.'
                ], 403);
            }

            if (isset($data['name'])) {
                $group->update(['name' => $data['name']]);
            }

            if (array_key_exists('description', $data)) {
                $group->update(['description' => $data['description']]);
            }

            if (isset($data['permissions'])) {
                $group->permissions()->sync($data['permissions']);
            }

            $group->load('permissions');
            $this->logActivity('UPDATE', 'Permission Group', "Updated permission group: {$group->name}", $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission group updated successfully',
                'data' => $group
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update permission group',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $group = PermissionGroup::find($id);

            if (!$group) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Permission group not found',
                    'data' => []
                ], 404);
            }

            if ($group->application_id !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete project-owned permission groups via GIAM internal API.'
                ], 403);
            }

            $groupName = $group->name;
            $group->delete();

            $this->logActivity('DELETE', 'Permission Group', "Deleted permission group: {$groupName}");

            return response()->json([
                'status' => 'success',
                'message' => 'Permission group deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete permission group',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function list(Request $request)
    {
        try {
            $query = PermissionGroup::query();

            if ($request->has('application_id')) {
                $query->where('application_id', $request->application_id);
            } else {
                $query->whereNull('application_id');
            }

            $groups = $query->select('id', 'name')->orderBy('name', 'asc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission groups list retrieved successfully',
                'data' => $groups
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permission groups list',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
