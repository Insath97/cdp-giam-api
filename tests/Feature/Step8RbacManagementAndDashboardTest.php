<?php

namespace Tests\Feature;

use App\Mail\NewAccountCredentialsMail;
use App\Models\Employee;
use App\Models\GiamModule;
use App\Models\GiamPermissionGroup;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Step8RbacManagementAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $staffUser;
    protected User $hrUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->superAdmin = User::where('username', 'user01')->firstOrFail();

        // Create a standard Staff user with no admin permissions
        $this->staffUser = $this->createPrincipal('staff_only_user', 'staff_user@example.com', 'Staff');

        // Create an HR user
        $this->hrUser = $this->createPrincipal('hr_manager_user', 'hr_manager@example.com', 'HR');
    }

    protected function createPrincipal(string $username, string $email, string $roleName): User
    {
        $code = 'EMP_' . strtoupper(substr(md5($username), 0, 8));
        Employee::create([
            'employee_code' => $code,
            'f_name' => 'First',
            'l_name' => 'Last',
            'full_name' => 'First Last',
            'name_with_initials' => 'F. Last',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => substr(md5($code), 0, 9) . 'V',
            'date_of_birth' => '1990-01-01',
            'email' => $email,
            'phone' => '+94770000000',
            'address_line_1' => 'HQ',
            'city' => 'Colombo',
            'phone_primary' => '+94770000000',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => $code,
            'name' => 'First Last',
            'username' => $username,
            'email' => $email,
            'password' => bcrypt('password123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $user->syncRoles([$roleName]);
        return $user;
    }

    /** 1. GIAM_ROLE_VIEW can read RBAC */
    public function test_giam_role_view_can_read_rbac(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/rbac/roles');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertNotEmpty($response->json('data'));
    }

    /** 2. No GIAM_ROLE_VIEW -> 403 */
    public function test_no_giam_role_view_returns_403(): void
    {
        $response = $this->actingAs($this->staffUser)
            ->getJson('/api/v1/rbac/roles');

        $response->assertStatus(403);
    }

    /** 3. GIAM_ROLE_MANAGE can create custom role */
    public function test_giam_role_manage_can_create_custom_role(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rbac/roles', [
                'name' => 'Auditor',
                'description' => 'Security compliance and audit viewer',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Auditor');

        $this->assertDatabaseHas('roles', [
            'name' => 'Auditor',
            'is_system_reserved' => false,
        ]);
    }

    /** 4 & 5. Can assign and remove permissions from role */
    public function test_can_assign_and_remove_permissions_from_custom_role(): void
    {
        $role = Role::create([
            'name' => 'Custom Reviewer',
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        // Assign permissions
        $assignRes = $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => ['USER_VIEW', 'AUDIT_VIEW'],
            ]);

        $assignRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertTrue($role->fresh()->hasPermissionTo('USER_VIEW'));
        $this->assertTrue($role->fresh()->hasPermissionTo('AUDIT_VIEW'));

        // Remove one permission
        $removeRes = $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => ['USER_VIEW'],
            ]);

        $removeRes->assertStatus(200);
        $this->assertTrue($role->fresh()->hasPermissionTo('USER_VIEW'));
        $this->assertFalse($role->fresh()->hasPermissionTo('AUDIT_VIEW'));
    }

    /** 6. System role cannot be deleted */
    public function test_system_role_cannot_be_deleted(): void
    {
        $systemRole = Role::where('name', 'Super Admin')->firstOrFail();

        $response = $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/rbac/roles/{$systemRole->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id]);
    }

    /** 7. Custom safe role can be deleted */
    public function test_custom_safe_role_can_be_deleted(): void
    {
        $customRole = Role::create([
            'name' => 'Temporary Reviewer',
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/rbac/roles/{$customRole->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $customRole->id]);
    }

    /** 8 & 9. Module creation and update works */
    public function test_module_creation_and_update(): void
    {
        // Create
        $createRes = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rbac/modules', [
                'code' => 'CUSTOM_MODULE',
                'name' => 'Custom Module',
                'description' => 'A custom module for testing',
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.code', 'CUSTOM_MODULE');

        $moduleId = $createRes->json('data.id');

        // Update
        $updateRes = $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/rbac/modules/{$moduleId}", [
                'name' => 'Renamed Custom Module',
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed Custom Module');
    }

    /** 10. Unsafe module deletion rejected (409 Conflict) */
    public function test_unsafe_module_deletion_rejected_with_409(): void
    {
        $module = GiamModule::where('code', 'USER_EMPLOYEE_MGMT')->firstOrFail();

        $response = $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/rbac/modules/{$module->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('giam_modules', ['id' => $module->id]);
    }

    /** 11. Permission group creation works */
    public function test_permission_group_creation(): void
    {
        $module = GiamModule::firstOrFail();

        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rbac/permission-groups', [
                'module_id' => $module->id,
                'code' => 'CUSTOM_GROUP',
                'name' => 'Custom Group',
                'description' => 'Test group',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'CUSTOM_GROUP');
    }

    /** 12. Permission creation works */
    public function test_permission_creation(): void
    {
        $group = GiamPermissionGroup::firstOrFail();

        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rbac/permissions', [
                'name' => 'CUSTOM_SPECIAL_VIEW',
                'permission_group_id' => $group->id,
                'description' => 'Custom permission description',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'CUSTOM_SPECIAL_VIEW');
    }

    /** 13. Unsafe permission deletion rejected */
    public function test_unsafe_permission_deletion_rejected_with_409(): void
    {
        $perm = Permission::where('name', 'GIAM_ROLE_VIEW')->firstOrFail();

        // Already assigned to Super Admin
        $response = $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/rbac/permissions/{$perm->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('permissions', ['id' => $perm->id]);
    }

    /** 14. Role assignment changes actual authorization */
    public function test_role_assignment_changes_actual_authorization(): void
    {
        $user = $this->createPrincipal('test_auth_user', 'test_auth@example.com', 'Staff');

        // Cannot view RBAC
        $this->actingAs($user)->getJson('/api/v1/rbac/roles')->assertStatus(403);

        // Assign Admin role
        $user->syncRoles(['Admin']);

        // Now can view RBAC
        $this->actingAs($user->fresh())->getJson('/api/v1/rbac/roles')->assertStatus(200);
    }

    /** 15. Project RBAC remains unaffected */
    public function test_project_rbac_remains_unaffected_by_giam_rbac_mutations(): void
    {
        $project = Project::firstOrFail();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        // Creating a GIAM role does not modify project tables
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rbac/roles', ['name' => 'Inspector'])
            ->assertStatus(201);

        // Verify project roles table has zero rows containing 'Inspector'
        $this->assertDatabaseMissing('project_roles', ['name' => 'Inspector']);
    }

    /** 16 & 17. GIAM default hierarchy returned from backend DB & No Technical Engineer */
    public function test_giam_hierarchy_returns_real_db_records_and_no_technical_engineer(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/rbac/hierarchy');

        $response->assertStatus(200);
        $modules = collect($response->json('data'));

        $this->assertTrue($modules->contains('code', 'USER_EMPLOYEE_MGMT'));
        $this->assertTrue($modules->contains('code', 'PROJECT_REGISTRY'));
        $this->assertTrue($modules->contains('code', 'ACCESS_CONTROL'));
        $this->assertTrue($modules->contains('code', 'GIAM_RBAC'));

        // Check default roles do not include 'Technical Engineer'
        $rolesRes = $this->actingAs($this->superAdmin)->getJson('/api/v1/rbac/roles');
        $roleNames = collect($rolesRes->json('data'))->pluck('name')->toArray();

        $this->assertContains('Super Admin', $roleNames);
        $this->assertContains('Admin', $roleNames);
        $this->assertContains('HR', $roleNames);
        $this->assertContains('Staff', $roleNames);
        $this->assertNotContains('Technical Engineer', $roleNames);
    }

    /** 18 & 19. Staff cannot see admin functions; HR sees only permitted functions */
    public function test_permission_matrix_gating(): void
    {
        // Staff user cannot view audit or reports
        $this->actingAs($this->staffUser)->getJson('/api/v1/audit-logs')->assertStatus(403);
        $this->actingAs($this->staffUser)->getJson('/api/v1/reports/access')->assertStatus(403);

        // HR user has EMPLOYEE_CREATE but not GIAM_ROLE_MANAGE
        $this->actingAs($this->hrUser)->getJson('/api/v1/rbac/roles')->assertStatus(403);
    }

    /** 22. Mail dispatch occurs on GIAM Principal creation */
    public function test_mail_dispatch_occurs_on_giam_principal_creation(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'smtp_user']);
        Mail::fake();

        $employee = Employee::create([
            'employee_code' => 'EMP_TEST_88',
            'f_name' => 'Mail',
            'l_name' => 'Test',
            'full_name' => 'Mail Test',
            'name_with_initials' => 'M. Test',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '888888888V',
            'date_of_birth' => '1990-01-01',
            'email' => 'mailtest@example.com',
            'phone' => '+94770000088',
            'address_line_1' => 'Street 1',
            'city' => 'Colombo',
            'phone_primary' => '+94770000088',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        // Creating GIAM Principal
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/users', [
                'employee_code' => 'EMP_TEST_88',
                'name' => 'Mail Test',
                'email' => 'mailtest@example.com',
                'username' => 'principal_88',
                'roles' => ['Staff'],
            ]);

        $response->assertStatus(201);
        $createdUser = User::where('username', 'principal_88')->firstOrFail();
        $this->assertTrue((bool) $createdUser->must_change_password);

        Mail::assertSent(NewAccountCredentialsMail::class, function ($mail) {
            return $mail->hasTo('mailtest@example.com');
        });
    }

    /** 23. HR employee-only submission does NOT send credentials mail */
    public function test_hr_employee_only_submission_does_not_send_credentials_mail(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->hrUser)
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP_77777',
                'f_name' => 'Employee',
                'l_name' => 'Only',
                'full_name' => 'Employee Only',
                'name_with_initials' => 'E. Only',
                'employee_type' => 'permanent',
                'id_type' => 'nic',
                'id_number' => '777777777V',
                'date_of_birth' => '1992-02-02',
                'email' => 'employeeonly@example.com',
                'phone' => '+94771111177',
                'phone_primary' => '+94771111177',
                'address_line_1' => 'Street 2',
                'city' => 'Kandy',
                'start_date' => '2026-01-01',
                'province_code' => 'WP',
                'zonal_code' => 'Z01',
                'region_code' => 'R01',
                'department_code' => 'DEP01',
                'designation_code' => 'DES01',
            ]);

        $response->assertStatus(201);
        Mail::assertNothingSent();
    }
}
