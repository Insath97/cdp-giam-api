<?php

namespace Database\Seeders;

use App\Models\GiamModule;
use App\Models\GiamPermissionGroup;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class GiamRbacSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. GIAM Internal Modules
        $modules = [
            'USER_EMPLOYEE_MGMT' => GiamModule::updateOrCreate(
                ['code' => 'USER_EMPLOYEE_MGMT'],
                [
                    'name' => 'User & Employee Management',
                    'description' => 'Master employee data, identity lifecycle, and account provisioning',
                    'icon' => 'Users',
                    'order_index' => 1,
                    'is_active' => true,
                ]
            ),
            'PROJECT_REGISTRY' => GiamModule::updateOrCreate(
                ['code' => 'PROJECT_REGISTRY'],
                [
                    'name' => 'Project Registry & Integrations',
                    'description' => 'Registry of interconnected downstream applications, API keys, and health checks',
                    'icon' => 'FolderKanban',
                    'order_index' => 2,
                    'is_active' => true,
                ]
            ),
            'ACCESS_CONTROL' => GiamModule::updateOrCreate(
                ['code' => 'ACCESS_CONTROL'],
                [
                    'name' => 'Project Access Management',
                    'description' => 'Assignment and revocation of downstream project roles and permissions',
                    'icon' => 'KeyRound',
                    'order_index' => 3,
                    'is_active' => true,
                ]
            ),
            'REPORTS_ANALYTICS' => GiamModule::updateOrCreate(
                ['code' => 'REPORTS_ANALYTICS'],
                [
                    'name' => 'Reports & Analytics',
                    'description' => 'Centralized identity and cross-project access reporting with CSV/PDF exports',
                    'icon' => 'FileSpreadsheet',
                    'order_index' => 4,
                    'is_active' => true,
                ]
            ),
            'AUDIT_SECURITY' => GiamModule::updateOrCreate(
                ['code' => 'AUDIT_SECURITY'],
                [
                    'name' => 'Security & Audit Logs',
                    'description' => 'Immutable audit trails for authentication, provisioning, and access mutations',
                    'icon' => 'ShieldCheck',
                    'order_index' => 5,
                    'is_active' => true,
                ]
            ),
            'GIAM_RBAC' => GiamModule::updateOrCreate(
                ['code' => 'GIAM_RBAC'],
                [
                    'name' => 'GIAM RBAC Administration',
                    'description' => 'Internal GIAM roles, module mapping, and permission management',
                    'icon' => 'Lock',
                    'order_index' => 6,
                    'is_active' => true,
                ]
            ),
        ];

        // 2. GIAM Permission Groups (linked to Modules)
        $groups = [
            'EMPLOYEE_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'EMPLOYEE_MGMT'],
                [
                    'module_id' => $modules['USER_EMPLOYEE_MGMT']->id,
                    'name' => 'Employee Master Data',
                    'description' => 'Permissions related to employee demographic and organizational records',
                ]
            ),
            'USER_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'USER_MGMT'],
                [
                    'module_id' => $modules['USER_EMPLOYEE_MGMT']->id,
                    'name' => 'User Account & Authentication',
                    'description' => 'Permissions related to central GIAM login account creation and credentials',
                ]
            ),
            'PROJECT_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'PROJECT_MGMT'],
                [
                    'module_id' => $modules['PROJECT_REGISTRY']->id,
                    'name' => 'Project Registry & Integration',
                    'description' => 'Permissions related to connected projects and integration settings',
                ]
            ),
            'ACCESS_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'ACCESS_MGMT'],
                [
                    'module_id' => $modules['ACCESS_CONTROL']->id,
                    'name' => 'Project Access Assignment',
                    'description' => 'Permissions related to assigning and revoking project access',
                ]
            ),
            'REPORT_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'REPORT_MGMT'],
                [
                    'module_id' => $modules['REPORTS_ANALYTICS']->id,
                    'name' => 'Reports & Analytics',
                    'description' => 'Permissions related to viewing and exporting access reports',
                ]
            ),
            'AUDIT_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'AUDIT_MGMT'],
                [
                    'module_id' => $modules['AUDIT_SECURITY']->id,
                    'name' => 'Audit & Security Logs',
                    'description' => 'Permissions related to viewing security audit trails',
                ]
            ),
            'RBAC_MGMT' => GiamPermissionGroup::updateOrCreate(
                ['code' => 'RBAC_MGMT'],
                [
                    'module_id' => $modules['GIAM_RBAC']->id,
                    'name' => 'GIAM Internal RBAC',
                    'description' => 'Permissions related to viewing and managing internal GIAM roles and privileges',
                ]
            ),
        ];

        // 3. GIAM Permissions Matrix (linked to Permission Groups)
        $permissionsByGroup = [
            'EMPLOYEE_MGMT' => [
                ['name' => 'EMPLOYEE_VIEW', 'description' => 'View employee master records and organizational hierarchy'],
                ['name' => 'EMPLOYEE_CREATE', 'description' => 'Create new employee master records and business access requests'],
                ['name' => 'EMPLOYEE_UPDATE', 'description' => 'Update existing employee master records'],
            ],
            'USER_MGMT' => [
                ['name' => 'USER_VIEW', 'description' => 'View user accounts and profiles'],
                ['name' => 'USER_CREATE', 'description' => 'Create central GIAM login accounts'],
                ['name' => 'USER_UPDATE', 'description' => 'Update existing user accounts and credentials'],
                ['name' => 'USER_DEACTIVATE', 'description' => 'Deactivate user login accounts'],
            ],
            'PROJECT_MGMT' => [
                ['name' => 'PROJECT_VIEW', 'description' => 'View project registry and integration details'],
                ['name' => 'PROJECT_MANAGE', 'description' => 'Register and configure connected projects'],
            ],
            'ACCESS_MGMT' => [
                ['name' => 'ACCESS_VIEW', 'description' => 'View assigned project roles and permissions'],
                ['name' => 'ACCESS_ASSIGN', 'description' => 'Grant project roles and permissions to users'],
                ['name' => 'ACCESS_REVOKE', 'description' => 'Revoke project access from users'],
            ],
            'REPORT_MGMT' => [
                ['name' => 'REPORT_VIEW', 'description' => 'View access and compliance reports'],
                ['name' => 'REPORT_EXPORT', 'description' => 'Export reports to CSV and PDF formats'],
            ],
            'AUDIT_MGMT' => [
                ['name' => 'AUDIT_VIEW', 'description' => 'View immutable security audit trails'],
            ],
            'RBAC_MGMT' => [
                ['name' => 'GIAM_ROLE_VIEW', 'description' => 'View GIAM internal roles, modules, and permissions'],
                ['name' => 'GIAM_ROLE_MANAGE', 'description' => 'Manage GIAM internal roles and permission assignments'],
            ],
        ];

        foreach ($permissionsByGroup as $groupCode => $perms) {
            $groupId = $groups[$groupCode]->id;
            foreach ($perms as $perm) {
                Permission::updateOrCreate(
                    ['name' => $perm['name'], 'guard_name' => 'web'],
                    [
                        'permission_group_id' => $groupId,
                        'description' => $perm['description'],
                    ]
                );
            }
        }

        // 4. GIAM Roles Configuration
        $superAdminRole = Role::updateOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            ['description' => 'Unrestricted access to all GIAM internal capabilities and administrative modules', 'is_system_reserved' => true]
        );
        $superAdminRole->syncPermissions(Permission::all());

        $adminRole = Role::updateOrCreate(
            ['name' => 'Admin', 'guard_name' => 'web'],
            ['description' => 'Administrator with user lifecycle, project access, and reporting capabilities', 'is_system_reserved' => true]
        );
        $adminRole->syncPermissions([
            'EMPLOYEE_VIEW', 'EMPLOYEE_CREATE', 'EMPLOYEE_UPDATE',
            'USER_VIEW', 'USER_CREATE', 'USER_UPDATE',
            'PROJECT_VIEW',
            'ACCESS_VIEW', 'ACCESS_ASSIGN',
            'REPORT_VIEW', 'REPORT_EXPORT',
            'AUDIT_VIEW',
            'GIAM_ROLE_VIEW',
        ]);

        $hrRole = Role::updateOrCreate(
            ['name' => 'HR', 'guard_name' => 'web'],
            ['description' => 'Human Resources personnel responsible for employee master data onboarding and business access requests', 'is_system_reserved' => true]
        );
        $hrRole->syncPermissions([
            'EMPLOYEE_VIEW', 'EMPLOYEE_CREATE', 'EMPLOYEE_UPDATE',
            'USER_VIEW',
            'REPORT_VIEW',
        ]);

        Role::updateOrCreate(
            ['name' => 'Staff', 'guard_name' => 'web'],
            ['description' => 'Standard organization staff user with project launchpad access only', 'is_system_reserved' => true]
        );
        // Staff has no GIAM administrative permissions
    }
}
