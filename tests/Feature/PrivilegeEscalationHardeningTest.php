<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PrivilegeEscalationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function createPrincipalWithPermissions(string $username, array $permissions): User
    {
        $code = 'EMP_' . strtoupper(substr(md5($username), 0, 8));
        Employee::create([
            'employee_code' => $code,
            'f_name' => 'Custom',
            'l_name' => 'Operator',
            'full_name' => 'Custom Operator',
            'name_with_initials' => 'C. Operator',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => substr(md5($code), 0, 9) . 'V',
            'date_of_birth' => '1992-05-15',
            'email' => "{$username}@example.com",
            'phone' => '+94771234567',
            'address_line_1' => 'Operations Center',
            'city' => 'Colombo',
            'phone_primary' => '+94771234567',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => $code,
            'name' => 'Custom Operator',
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => 'Password@123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);

        $roleName = 'CustomRole_' . substr(md5($username), 0, 6);
        $role = Role::create([
            'name' => $roleName,
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        $role->syncPermissions($permissions);
        $user->syncRoles([$roleName]);

        return $user;
    }

    /**
     * Proves: Non-root actor with GIAM_ROLE_MANAGE cannot grant unheld permissions to a custom role.
     */
    public function test_non_root_actor_cannot_grant_unheld_permissions_to_custom_role(): void
    {
        $actor = $this->createPrincipalWithPermissions('manager_limited', [
            'GIAM_ROLE_MANAGE',
            'GIAM_ROLE_VIEW',
            'USER_VIEW',
        ]);

        $role = Role::create([
            'name' => 'TargetCustomRole',
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        $res = $this->actingAs($actor)->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
            'permissions' => ['USER_VIEW', 'USER_DEACTIVATE'],
        ]);

        $res->assertStatus(403);
    }

    /**
     * Proves: Non-root actor with USER_CREATE cannot assign a role containing permissions beyond their authority.
     */
    public function test_non_root_actor_cannot_assign_role_containing_unheld_permissions_via_user_creation(): void
    {
        $actor = $this->createPrincipalWithPermissions('creator_limited', ['USER_CREATE', 'USER_VIEW']);

        Employee::create([
            'employee_code' => 'EMP_ESC001',
            'f_name' => 'Esc',
            'l_name' => 'Target',
            'full_name' => 'Esc Target',
            'name_with_initials' => 'E. Target',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '199812345678',
            'date_of_birth' => '1998-01-01',
            'email' => 'esc@example.com',
            'phone' => '+94773334455',
            'address_line_1' => 'Street',
            'city' => 'Colombo',
            'phone_primary' => '+94773334455',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $res = $this->actingAs($actor)->postJson('/api/v1/users', [
            'employee_code' => 'EMP_ESC001',
            'name' => 'Escalation Target',
            'username' => 'esc_target',
            'email' => 'esc@example.com',
            'roles' => ['Admin'],
        ]);

        $res->assertStatus(403);
    }

    /**
     * Proves: Non-root actor cannot assign a role containing permissions beyond their authority via User Update.
     */
    public function test_non_root_actor_cannot_assign_role_containing_unheld_permissions_via_user_update(): void
    {
        $actor = $this->createPrincipalWithPermissions('updater_limited', ['USER_UPDATE', 'USER_VIEW']);

        $targetUser = $this->createPrincipalWithPermissions('update_target_user', ['USER_VIEW']);

        $res = $this->actingAs($actor)->putJson("/api/v1/users/{$targetUser->id}", [
            'version' => $targetUser->version,
            'roles' => ['Admin'],
        ]);

        $res->assertStatus(403);
    }

    /**
     * Proves: Non-root actor cannot assign a role beyond their authority via Bulk Import.
     */
    public function test_non_root_actor_cannot_assign_role_beyond_authority_via_bulk_import(): void
    {
        $actor = $this->createPrincipalWithPermissions('importer_limited', ['USER_CREATE', 'USER_VIEW']);

        Employee::create([
            'employee_code' => 'EMP_CSV01',
            'f_name' => 'Csv',
            'l_name' => 'User',
            'full_name' => 'Csv User',
            'name_with_initials' => 'C. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '199812345679',
            'date_of_birth' => '1998-01-01',
            'email' => 'csvuser@example.com',
            'phone' => '+94773334456',
            'address_line_1' => 'Street',
            'city' => 'Colombo',
            'phone_primary' => '+94773334456',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $csvContent = "employee_code,name,username,email,user_type,is_active,can_login,role\n" .
                      "EMP_CSV01,Csv User,csvuser,csvuser@example.com,staff,1,1,Admin\n";

        $file = UploadedFile::fake()->createWithContent('users.csv', $csvContent);

        $res = $this->actingAs($actor)->postJson('/api/v1/users/bulk-import', [
            'file' => $file,
        ]);

        $res->assertStatus(200);
        $data = $res->json();
        $this->assertEquals(0, $data['summary']['successful']);
        $this->assertEquals(1, $data['summary']['failed']);
        $this->assertStringContainsString('Privilege escalation rejected', $data['errors'][0]['reason']);
    }

    /**
     * Proves: Non-root actor cannot assign the Super Admin role under any circumstance.
     */
    public function test_non_root_actor_cannot_assign_super_admin_role(): void
    {
        $actor = $this->createPrincipalWithPermissions('near_root', [
            'USER_VIEW', 'USER_CREATE', 'USER_UPDATE', 'USER_DEACTIVATE',
            'EMPLOYEE_VIEW', 'EMPLOYEE_CREATE', 'EMPLOYEE_UPDATE',
            'PROJECT_VIEW', 'PROJECT_MANAGE', 'ACCESS_VIEW', 'ACCESS_ASSIGN', 'ACCESS_REVOKE',
            'REPORT_VIEW', 'REPORT_EXPORT', 'AUDIT_VIEW', 'GIAM_ROLE_VIEW',
        ]);

        Employee::create([
            'employee_code' => 'EMP_SA001',
            'f_name' => 'Target',
            'l_name' => 'SA',
            'full_name' => 'Target SA',
            'name_with_initials' => 'T. SA',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '199812345680',
            'date_of_birth' => '1998-01-01',
            'email' => 'targetsa@example.com',
            'phone' => '+94773334457',
            'address_line_1' => 'Street',
            'city' => 'Colombo',
            'phone_primary' => '+94773334457',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $res = $this->actingAs($actor)->postJson('/api/v1/users', [
            'employee_code' => 'EMP_SA001',
            'name' => 'Target SA',
            'username' => 'targetsa',
            'email' => 'targetsa@example.com',
            'roles' => ['Super Admin'],
        ]);

        $res->assertStatus(403);
    }

    /**
     * Proves: Root actor (Super Admin) retains full grant authority across the entire 17-permission catalog.
     */
    public function test_root_super_admin_retains_full_grant_authority(): void
    {
        $superAdmin = User::where('username', 'user01')->firstOrFail();

        $role = Role::create([
            'name' => 'SuperCustomRole',
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        $allPerms = [
            'EMPLOYEE_VIEW', 'EMPLOYEE_CREATE', 'EMPLOYEE_UPDATE',
            'USER_VIEW', 'USER_CREATE', 'USER_UPDATE', 'USER_DEACTIVATE',
            'PROJECT_VIEW', 'PROJECT_MANAGE',
            'ACCESS_VIEW', 'ACCESS_ASSIGN', 'ACCESS_REVOKE',
            'REPORT_VIEW', 'REPORT_EXPORT',
            'AUDIT_VIEW',
            'GIAM_ROLE_VIEW', 'GIAM_ROLE_MANAGE',
        ];

        $res = $this->actingAs($superAdmin)->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
            'permissions' => $allPerms,
        ]);

        $res->assertStatus(200);
        $this->assertCount(17, $role->fresh()->permissions);
    }

    /**
     * Proves: Multi-role effective permissions union is correctly calculated for grant authority.
     */
    public function test_multi_role_effective_permissions_union_is_correctly_calculated(): void
    {
        $actor = $this->createPrincipalWithPermissions('multi_role_manager', ['GIAM_ROLE_MANAGE', 'GIAM_ROLE_VIEW']);

        $role2 = Role::create([
            'name' => 'SecondRole_' . uniqid(),
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);
        $role2->syncPermissions(['USER_VIEW', 'EMPLOYEE_VIEW']);
        $actor->assignRole($role2->name);

        $targetRole = Role::create([
            'name' => 'UnionTarget_' . uniqid(),
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        $res = $this->actingAs($actor)->putJson("/api/v1/rbac/roles/{$targetRole->id}/permissions", [
            'permissions' => ['USER_VIEW', 'EMPLOYEE_VIEW'],
        ]);
        $res->assertStatus(200);

        $resFail = $this->actingAs($actor)->putJson("/api/v1/rbac/roles/{$targetRole->id}/permissions", [
            'permissions' => ['USER_VIEW', 'ACCESS_REVOKE'],
        ]);
        $resFail->assertStatus(403);
    }
}
