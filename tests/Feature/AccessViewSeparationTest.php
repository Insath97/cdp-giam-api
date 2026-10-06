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

class AccessViewSeparationTest extends TestCase
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
            'f_name' => 'Access',
            'l_name' => 'Tester',
            'full_name' => 'Access Tester',
            'name_with_initials' => 'A. Tester',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => substr(md5($code), 0, 9) . 'V',
            'date_of_birth' => '1993-08-10',
            'email' => "{$username}@example.com",
            'phone' => '+94776667788',
            'address_line_1' => 'HQ',
            'city' => 'Colombo',
            'phone_primary' => '+94776667788',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => $code,
            'name' => 'Access Tester',
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => 'Password@123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $roleName = 'AccessRole_' . substr(md5($username), 0, 6);
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
     * Proves: ACCESS_VIEW allows reading user project access entitlements via GET /api/v1/users/{userId}/project-access.
     */
    public function test_access_view_allows_viewing_user_project_accesses(): void
    {
        $actor = $this->createPrincipalWithPermissions('access_viewer', ['ACCESS_VIEW']);
        $targetUser = $this->createPrincipalWithPermissions('access_target_user', ['USER_VIEW']);

        $res = $this->actingAs($actor)->getJson("/api/v1/users/{$targetUser->id}/project-access");
        $res->assertStatus(200);
    }

    /**
     * Proves: USER_VIEW alone returns 403 on the dedicated project-access entitlement endpoint.
     */
    public function test_user_view_alone_returns_403_on_project_access_endpoint(): void
    {
        $actor = $this->createPrincipalWithPermissions('user_viewer_only', ['USER_VIEW']);
        $targetUser = $this->createPrincipalWithPermissions('access_target_user_2', ['USER_VIEW']);

        $res = $this->actingAs($actor)->getJson("/api/v1/users/{$targetUser->id}/project-access");
        $res->assertStatus(403);
    }

    /**
     * Proves: ACCESS_VIEW alone returns 403 on the user directory index (no user account profile leak).
     */
    public function test_access_view_alone_returns_403_on_users_index_endpoint(): void
    {
        $actor = $this->createPrincipalWithPermissions('entitlement_auditor', ['ACCESS_VIEW']);

        $res = $this->actingAs($actor)->getJson('/api/v1/users');
        $res->assertStatus(403);
    }

    /**
     * Proves: Access request resolution requires ACCESS_ASSIGN regardless of role name.
     */
    public function test_access_request_resolution_requires_access_assign_regardless_of_role_name(): void
    {
        $actor = $this->createPrincipalWithPermissions('view_only_access', ['ACCESS_VIEW']);

        $emp = Employee::first();
        $project = Project::first();
        $projectRole = ProjectRole::create([
            'project_id' => $project->id,
            'name' => 'Test Role',
            'code' => 'test_role_' . uniqid(),
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

        $res->assertStatus(403);
    }
}
