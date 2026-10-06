<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectAccessRequest;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionBasedAuthorizationTest extends TestCase
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
     * Proves: A custom role with USER_CREATE can create users without being named Admin.
     */
    public function test_custom_role_with_user_create_can_create_users_without_being_named_admin(): void
    {
        // Custom role holding USER_CREATE and USER_VIEW
        $actor = $this->createPrincipalWithPermissions('creator_custom', ['USER_CREATE', 'USER_VIEW']);

        // Staff role has 0 permissions, so creator can grant it
        Employee::create([
            'employee_code' => 'EMP_TGT001',
            'f_name' => 'Target',
            'l_name' => 'User',
            'full_name' => 'Target User',
            'name_with_initials' => 'T. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '199512345678',
            'date_of_birth' => '1995-01-01',
            'email' => 'target@example.com',
            'phone' => '+94771112233',
            'address_line_1' => 'Street 1',
            'city' => 'Colombo',
            'phone_primary' => '+94771112233',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $res = $this->actingAs($actor)->postJson('/api/v1/users', [
            'employee_code' => 'EMP_TGT001',
            'name' => 'Target User',
            'username' => 'targetuser',
            'email' => 'target@example.com',
            'roles' => ['Staff'],
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('users', ['username' => 'targetuser']);
    }

    /**
     * Proves: A custom role without USER_CREATE receives 403 Forbidden.
     */
    public function test_custom_role_without_user_create_receives_403(): void
    {
        $actor = $this->createPrincipalWithPermissions('viewer_only', ['USER_VIEW']);

        $res = $this->actingAs($actor)->postJson('/api/v1/users', [
            'employee_code' => 'EMP_TGT002',
            'name' => 'Target Two',
            'username' => 'targettwo',
            'email' => 'target2@example.com',
            'roles' => ['Staff'],
        ]);

        $res->assertStatus(403);
    }

    /**
     * Proves: A custom role with ACCESS_ASSIGN can resolve project access requests without being named Admin.
     */
    public function test_custom_role_with_access_assign_can_resolve_project_access_requests_without_being_admin(): void
    {
        $actor = $this->createPrincipalWithPermissions('access_assigner', ['ACCESS_ASSIGN', 'ACCESS_VIEW']);

        $emp = Employee::first();
        $project = Project::first();
        $projectRole = ProjectRole::create([
            'project_id' => $project->id,
            'name' => 'Standard Developer',
            'code' => 'std_dev_' . uniqid(),
            'external_role_id' => '10',
            'is_active' => true,
        ]);

        $request = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => $project->name,
            'nature_of_role' => 'Standard User',
            'status' => 'PENDING',
            'submitted_by_user_id' => $actor->id,
        ]);

        $res = $this->actingAs($actor)->postJson("/api/v1/project-access-requests/{$request->id}/resolve", [
            'project_id' => $project->id,
            'role_ids' => [$projectRole->id],
        ]);

        $res->assertStatus(200);
        $this->assertEquals('FULFILLED', $request->fresh()->status);
    }

    /**
     * Proves: System-reserved roles cannot be renamed or deleted.
     */
    public function test_system_reserved_roles_cannot_be_renamed_or_deleted(): void
    {
        $superAdmin = User::where('username', 'user01')->firstOrFail();
        $adminRole = Role::where('name', 'Admin')->firstOrFail();

        // Attempt rename
        $renameRes = $this->actingAs($superAdmin)->putJson("/api/v1/rbac/roles/{$adminRole->id}", [
            'name' => 'Renamed Admin',
        ]);
        $renameRes->assertStatus(409);

        // Attempt delete
        $deleteRes = $this->actingAs($superAdmin)->deleteJson("/api/v1/rbac/roles/{$adminRole->id}");
        $deleteRes->assertStatus(409);
    }

    /**
     * Proves: Ordinary permission middleware does not depend on role-name Super Admin bypass.
     */
    public function test_ordinary_permission_middleware_evaluates_permissions_purely(): void
    {
        $actor = $this->createPrincipalWithPermissions('audit_inspector', ['AUDIT_VIEW']);

        $okRes = $this->actingAs($actor)->getJson('/api/v1/audit-logs');
        $okRes->assertStatus(200);

        // Access an endpoint for which this actor has no permission
        $forbiddenRes = $this->actingAs($actor)->getJson('/api/v1/reports/access');
        $forbiddenRes->assertStatus(403);
    }
}
