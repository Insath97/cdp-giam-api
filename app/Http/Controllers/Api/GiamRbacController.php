<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiamModule;
use App\Models\GiamPermissionGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GiamRbacController extends Controller
{
    public function __construct(
        protected AuditLoggerService $auditLogger
    ) {}

    /**
     * Get complete GIAM Internal RBAC hierarchy:
     * Module -> Permission Group -> Permission
     */
    public function hierarchy(): JsonResponse
    {
        $modules = GiamModule::with(['permissionGroups.permissions'])
            ->orderBy('order_index')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $modules,
        ]);
    }

    /**
     * Get all GIAM internal roles and their derived modules/groups/permissions.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::where('guard_name', 'web')
            ->with(['permissions.permissionGroup.module'])
            ->get();

        $data = $roles->map(function ($role) {
            $permissions = $role->permissions;

            // Group permissions by module and permission group
            $modulesMap = [];
            foreach ($permissions as $perm) {
                $group = $perm->permissionGroup;
                $module = $group?->module;

                if ($module) {
                    if (! isset($modulesMap[$module->id])) {
                        $modulesMap[$module->id] = [
                            'id' => $module->id,
                            'code' => $module->code,
                            'name' => $module->name,
                            'description' => $module->description,
                            'icon' => $module->icon,
                            'groups' => [],
                        ];
                    }

                    if ($group && ! isset($modulesMap[$module->id]['groups'][$group->id])) {
                        $modulesMap[$module->id]['groups'][$group->id] = [
                            'id' => $group->id,
                            'code' => $group->code,
                            'name' => $group->name,
                            'permissions' => [],
                        ];
                    }

                    if ($group) {
                        $modulesMap[$module->id]['groups'][$group->id]['permissions'][] = [
                            'id' => $perm->id,
                            'name' => $perm->name,
                            'description' => $perm->description,
                        ];
                    }
                }
            }

            // Re-index groups and modules as simple arrays
            $resolvedModules = array_values(array_map(function ($mod) {
                $mod['groups'] = array_values($mod['groups']);
                return $mod;
            }, $modulesMap));

            return [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system_reserved' => (bool) $role->is_system_reserved,
                'permission_names' => $permissions->pluck('name')->toArray(),
                'permission_ids' => $permissions->pluck('id')->toArray(),
                'modules_count' => count($resolvedModules),
                'permissions_count' => $permissions->count(),
                'principals_count' => User::role($role->name)->count(),
                'modules' => $resolvedModules,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Get single GIAM internal role details.
     */
    public function showRole(int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')
            ->where('id', $id)
            ->with(['permissions.permissionGroup.module'])
            ->first();

        if (! $role) {
            return response()->json([
                'status' => 'error',
                'message' => 'GIAM internal role not found',
            ], 404);
        }

        $permissions = $role->permissions;
        $modulesMap = [];
        foreach ($permissions as $perm) {
            $group = $perm->permissionGroup;
            $module = $group?->module;

            if ($module) {
                if (! isset($modulesMap[$module->id])) {
                    $modulesMap[$module->id] = [
                        'id' => $module->id,
                        'code' => $module->code,
                        'name' => $module->name,
                        'description' => $module->description,
                        'icon' => $module->icon,
                        'groups' => [],
                    ];
                }

                if ($group && ! isset($modulesMap[$module->id]['groups'][$group->id])) {
                    $modulesMap[$module->id]['groups'][$group->id] = [
                        'id' => $group->id,
                        'code' => $group->code,
                        'name' => $group->name,
                        'permissions' => [],
                    ];
                }

                if ($group) {
                    $modulesMap[$module->id]['groups'][$group->id]['permissions'][] = [
                        'id' => $perm->id,
                        'name' => $perm->name,
                        'description' => $perm->description,
                    ];
                }
            }
        }

        $resolvedModules = array_values(array_map(function ($mod) {
            $mod['groups'] = array_values($mod['groups']);
            return $mod;
        }, $modulesMap));

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system_reserved' => (bool) $role->is_system_reserved,
                'permission_names' => $permissions->pluck('name')->toArray(),
                'permission_ids' => $permissions->pluck('id')->toArray(),
                'modules' => $resolvedModules,
            ],
        ]);
    }

    /**
     * Create a new custom GIAM internal role.
     */
    public function storeRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'description' => $validated['description'] ?? null,
            'is_system_reserved' => false,
        ]);

        $this->auditLogger->log(
            action: 'ROLE_CREATED',
            entityType: 'Role',
            entityId: (string) $role->id,
            beforeData: null,
            afterData: [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system_reserved' => false,
            ],
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Role created successfully.',
            'data' => $role,
        ], 201);
    }

    /**
     * Update an existing GIAM internal role.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50', "unique:roles,name,{$id}"],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if ($role->is_system_reserved && isset($validated['name']) && $validated['name'] !== $role->name) {
            throw new HttpException(409, 'System-reserved roles cannot be renamed.');
        }

        $before = $role->toArray();
        $role->update($validated);

        $this->auditLogger->log(
            action: 'ROLE_UPDATED',
            entityType: 'Role',
            entityId: (string) $role->id,
            beforeData: $before,
            afterData: $role->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Role updated successfully.',
            'data' => $role,
        ]);
    }

    /**
     * Delete a custom GIAM internal role.
     */
    public function destroyRole(int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')->findOrFail($id);

        if ($role->is_system_reserved) {
            throw new HttpException(409, 'System-reserved roles cannot be deleted.');
        }

        $assignedCount = User::role($role->name)->count();
        if ($assignedCount > 0) {
            throw new HttpException(409, "Cannot delete role [{$role->name}] because it is currently assigned to {$assignedCount} user(s).");
        }

        $before = $role->toArray();
        $role->syncPermissions([]);
        $role->delete();

        $this->auditLogger->log(
            action: 'ROLE_DELETED',
            entityType: 'Role',
            entityId: (string) $id,
            beforeData: $before,
            afterData: null,
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Role deleted successfully.',
        ]);
    }

    /**
     * Assign / sync permissions to a GIAM internal role.
     */
    public function syncRolePermissions(Request $request, int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')->findOrFail($id);

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $requestedNames = $validated['permissions'];

        // If role is Super Admin, ensure it retains essential administration capability
        if ($role->name === 'Super Admin') {
            if (! in_array('GIAM_ROLE_MANAGE', $requestedNames, true) || ! in_array('GIAM_ROLE_VIEW', $requestedNames, true)) {
                throw new HttpException(409, 'Super Admin must retain complete GIAM administrative access.');
            }
        }

        // Validate all permission names exist in GIAM permissions
        $validPermissions = Permission::where('guard_name', 'web')
            ->whereIn('name', $requestedNames)
            ->pluck('name')
            ->toArray();

        $invalid = array_diff($requestedNames, $validPermissions);
        if (! empty($invalid)) {
            throw new HttpException(422, 'Invalid permission names: ' . implode(', ', $invalid));
        }

        $beforePermissions = $role->permissions->pluck('name')->toArray();
        $role->syncPermissions($validPermissions);

        $this->auditLogger->log(
            action: 'ROLE_PERMISSIONS_CHANGED',
            entityType: 'Role',
            entityId: (string) $role->id,
            beforeData: ['permissions' => $beforePermissions],
            afterData: ['permissions' => $validPermissions],
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Role permissions updated successfully.',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $validPermissions,
            ],
        ]);
    }

    // ==========================================
    // MODULE MANAGEMENT
    // ==========================================

    public function modules(): JsonResponse
    {
        $modules = GiamModule::withCount('permissionGroups')->orderBy('order_index')->get();

        return response()->json([
            'status' => 'success',
            'data' => $modules,
        ]);
    }

    public function storeModule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:giam_modules,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'order_index' => ['nullable', 'integer'],
        ]);

        $validated['code'] = strtoupper($validated['code']);
        $validated['is_active'] = true;

        $module = GiamModule::create($validated);

        $this->auditLogger->log(
            action: 'MODULE_CREATED',
            entityType: 'GiamModule',
            entityId: (string) $module->id,
            beforeData: null,
            afterData: $module->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Module created successfully.',
            'data' => $module,
        ], 201);
    }

    public function updateModule(Request $request, int $id): JsonResponse
    {
        $module = GiamModule::findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', "unique:giam_modules,code,{$id}"],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:50'],
            'order_index' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $before = $module->toArray();
        $module->update($validated);

        $this->auditLogger->log(
            action: 'MODULE_UPDATED',
            entityType: 'GiamModule',
            entityId: (string) $module->id,
            beforeData: $before,
            afterData: $module->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Module updated successfully.',
            'data' => $module,
        ]);
    }

    public function destroyModule(int $id): JsonResponse
    {
        $module = GiamModule::findOrFail($id);

        if ($module->permissionGroups()->exists()) {
            throw new HttpException(409, 'Cannot delete module with attached permission groups. Remove or reassign permission groups first.');
        }

        $before = $module->toArray();
        $module->delete();

        $this->auditLogger->log(
            action: 'MODULE_DELETED',
            entityType: 'GiamModule',
            entityId: (string) $id,
            beforeData: $before,
            afterData: null,
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Module deleted successfully.',
        ]);
    }

    // ==========================================
    // PERMISSION GROUP MANAGEMENT
    // ==========================================

    public function permissionGroups(): JsonResponse
    {
        $groups = GiamPermissionGroup::with('module')->withCount('permissions')->get();

        return response()->json([
            'status' => 'success',
            'data' => $groups,
        ]);
    }

    public function storePermissionGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module_id' => ['required', 'integer', 'exists:giam_modules,id'],
            'code' => ['required', 'string', 'max:50', 'unique:giam_permission_groups,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['code'] = strtoupper($validated['code']);

        $group = GiamPermissionGroup::create($validated);

        $this->auditLogger->log(
            action: 'PERMISSION_GROUP_CREATED',
            entityType: 'GiamPermissionGroup',
            entityId: (string) $group->id,
            beforeData: null,
            afterData: $group->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission group created successfully.',
            'data' => $group->load('module'),
        ], 201);
    }

    public function updatePermissionGroup(Request $request, int $id): JsonResponse
    {
        $group = GiamPermissionGroup::findOrFail($id);

        $validated = $request->validate([
            'module_id' => ['sometimes', 'required', 'integer', 'exists:giam_modules,id'],
            'code' => ['sometimes', 'required', 'string', 'max:50', "unique:giam_permission_groups,code,{$id}"],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $before = $group->toArray();
        $group->update($validated);

        $this->auditLogger->log(
            action: 'PERMISSION_GROUP_UPDATED',
            entityType: 'GiamPermissionGroup',
            entityId: (string) $group->id,
            beforeData: $before,
            afterData: $group->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission group updated successfully.',
            'data' => $group->load('module'),
        ]);
    }

    public function destroyPermissionGroup(int $id): JsonResponse
    {
        $group = GiamPermissionGroup::findOrFail($id);

        if ($group->permissions()->exists()) {
            throw new HttpException(409, 'Cannot delete permission group with attached permissions. Remove or reassign permissions first.');
        }

        $before = $group->toArray();
        $group->delete();

        $this->auditLogger->log(
            action: 'PERMISSION_GROUP_DELETED',
            entityType: 'GiamPermissionGroup',
            entityId: (string) $id,
            beforeData: $before,
            afterData: null,
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission group deleted successfully.',
        ]);
    }

    // ==========================================
    // PERMISSION MANAGEMENT
    // ==========================================

    public function permissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'web')
            ->with(['permissionGroup.module'])
            ->withCount('roles')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $permissions,
        ]);
    }

    public function storePermission(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:permissions,name'],
            'permission_group_id' => ['required', 'integer', 'exists:giam_permission_groups,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['name'] = strtoupper($validated['name']);
        $validated['guard_name'] = 'web';

        $permission = Permission::create($validated);

        $this->auditLogger->log(
            action: 'PERMISSION_CREATED',
            entityType: 'Permission',
            entityId: (string) $permission->id,
            beforeData: null,
            afterData: $permission->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission created successfully.',
            'data' => $permission->load('permissionGroup.module'),
        ], 201);
    }

    public function updatePermission(Request $request, int $id): JsonResponse
    {
        $permission = Permission::where('guard_name', 'web')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50', "unique:permissions,name,{$id}"],
            'permission_group_id' => ['sometimes', 'required', 'integer', 'exists:giam_permission_groups,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = strtoupper($validated['name']);
        }

        $before = $permission->toArray();
        $permission->update($validated);

        $this->auditLogger->log(
            action: 'PERMISSION_UPDATED',
            entityType: 'Permission',
            entityId: (string) $permission->id,
            beforeData: $before,
            afterData: $permission->toArray(),
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission updated successfully.',
            'data' => $permission->load('permissionGroup.module'),
        ]);
    }

    public function destroyPermission(int $id): JsonResponse
    {
        $permission = Permission::where('guard_name', 'web')->findOrFail($id);

        if ($permission->roles()->exists()) {
            throw new HttpException(409, "Cannot delete permission [{$permission->name}] because it is currently assigned to one or more roles. Detach from roles first.");
        }

        $before = $permission->toArray();
        $permission->delete();

        $this->auditLogger->log(
            action: 'PERMISSION_DELETED',
            entityType: 'Permission',
            entityId: (string) $id,
            beforeData: $before,
            afterData: null,
            status: 'SUCCESS'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permission deleted successfully.',
        ]);
    }
}
