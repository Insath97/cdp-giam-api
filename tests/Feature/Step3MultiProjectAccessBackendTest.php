<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectPermission;
use App\Models\ProjectPermissionGroup;
use App\Models\ProjectRole;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Step3MultiProjectAccessBackendTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $staffUser;
    protected User $targetUser;
    protected Project $centrix;
    protected Project $hrms;
    protected ProjectRole $centrixAdminRole;
    protected ProjectRole $centrixOperatorRole;
    protected ProjectRole $centrixInactiveRole;
    protected ProjectRole $hrmsManagerRole;
    protected ProjectPermission $centrixPerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();
        $this->centrix = Project::where('code', 'centrix')->first();
        $this->hrms = Project::where('code', 'hrms')->first();

        // Target employee & user
        Employee::create([
            'employee_code' => 'EMP7001',
            'f_name' => 'Bob',
            'l_name' => 'Ross',
            'full_name' => 'Bob Ross',
            'name_with_initials' => 'B. Ross',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '971112223V',
            'date_of_birth' => '1997-01-01',
            'email' => 'bob@cdp.lk',
            'phone' => '+94112345678',
            'address_line_1' => 'Painter Lane',
            'city' => 'Colombo',
            'phone_primary' => '+94770001122',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->targetUser = User::create([
            'employee_code' => 'EMP7001',
            'name' => 'Bob Ross',
            'username' => 'bob7001',
            'email' => 'bob@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        // Staff employee & user without ACCESS_VIEW / ACCESS_ASSIGN
        Employee::create([
            'employee_code' => 'EMP7002',
            'f_name' => 'Staff',
            'l_name' => 'Plain',
            'full_name' => 'Staff Plain',
            'name_with_initials' => 'S. Plain',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '971112224V',
            'date_of_birth' => '1997-02-02',
            'email' => 'staff@cdp.lk',
            'phone' => '+94112345679',
            'address_line_1' => 'Staff Way',
            'city' => 'Colombo',
            'phone_primary' => '+94770001123',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->staffUser = User::create([
            'employee_code' => 'EMP7002',
            'name' => 'Staff Plain',
            'username' => 'staff_plain',
            'email' => 'staff@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        // Centrix roles
        $this->centrixAdminRole = ProjectRole::create([
            'project_id' => $this->centrix->id,
            'external_role_id' => '1',
            'code' => 'super_admin',
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        $this->centrixOperatorRole = ProjectRole::create([
            'project_id' => $this->centrix->id,
            'external_role_id' => '2',
            'code' => 'operator',
            'name' => 'Operator',
            'is_active' => true,
        ]);

        $this->centrixInactiveRole = ProjectRole::create([
            'project_id' => $this->centrix->id,
            'external_role_id' => '3',
            'code' => 'legacy_role',
            'name' => 'Legacy Role',
            'is_active' => false,
        ]);

        // Centrix permission
        $centrixGroup = ProjectPermissionGroup::create([
            'project_id' => $this->centrix->id,
            'external_group_id' => 'operations',
            'code' => 'operations',
            'name' => 'Operations Group',
            'is_active' => true,
        ]);

        $this->centrixPerm = ProjectPermission::create([
            'project_id' => $this->centrix->id,
            'project_permission_group_id' => $centrixGroup->id,
            'external_permission_id' => 'view_freight',
            'code' => 'view_freight',
            'name' => 'View Freight Records',
            'is_active' => true,
        ]);

        // HRMS role
        $this->hrmsManagerRole = ProjectRole::create([
            'project_id' => $this->hrms->id,
            'external_role_id' => 'hrms_mgr',
            'code' => 'hrms_manager',
            'name' => 'HRMS Manager',
            'is_active' => true,
        ]);
    }

    /**
     * Test A: User with zero project assignments returns empty paginated list.
     */
    public function test_user_with_zero_project_assignments(): void
    {
        $response = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/users/{$this->targetUser->id}/project-access");

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
        ]);
        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(0, $response->json('meta.total'));
    }

    /**
     * Test B: User with one project assignment returns summary without base_url or API secrets.
     */
    public function test_user_with_one_project_assignment_returns_clean_summary(): void
    {
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ])
            ->assertOk();

        $response = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/users/{$this->targetUser->id}/project-access");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        $item = $response->json('data.0');
        $this->assertEquals($this->centrix->id, $item['project']['id']);
        $this->assertEquals('centrix', $item['project']['code']);
        $this->assertEquals('CENTRIX Logistics', $item['project']['name']);
        $this->assertEquals('PENDING', $item['status']);
        $this->assertCount(1, $item['roles']);
        $this->assertEquals('1', $item['roles'][0]['external_role_id']);
        $this->assertEquals('Super Admin', $item['roles'][0]['name']);
        $this->assertEquals(1, $item['assigned_roles_count']);

        // Assert NO base_url or api_base_url is leaked
        $this->assertArrayNotHasKey('base_url', $item['project']);
        $this->assertArrayNotHasKey('api_base_url', $item['project']);
        $this->assertArrayNotHasKey('permissions', $item);
    }

    /**
     * Test C: User with multiple assignments across independent projects.
     */
    public function test_user_with_multiple_project_assignments(): void
    {
        // Assign Centrix
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ])
            ->assertOk();

        // Assign HRMS
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->hrms->id,
                'role_ids' => [$this->hrmsManagerRole->id],
            ])
            ->assertOk();

        $response = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/users/{$this->targetUser->id}/project-access");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.total'));

        // Verify distinct roles per project
        $centrixItem = collect($response->json('data'))->firstWhere('project.code', 'centrix');
        $hrmsItem = collect($response->json('data'))->firstWhere('project.code', 'hrms');

        $this->assertNotNull($centrixItem);
        $this->assertNotNull($hrmsItem);
        $this->assertEquals('1', $centrixItem['roles'][0]['external_role_id']);
        $this->assertEquals('hrms_mgr', $hrmsItem['roles'][0]['external_role_id']);
    }

    /**
     * Test D: Re-granting access to an existing access record (handles UNIQUE(user_id, project_id) gracefully).
     */
    public function test_regranting_access_reuses_existing_row_without_duplicate_error(): void
    {
        // 1. Initial assign
        $res1 = $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ])
            ->assertOk();

        $accessId = $res1->json('data.id');

        // 2. Revoke access
        $this->actingAs($this->superAdmin, 'web')
            ->deleteJson("/api/v1/users/{$this->targetUser->id}/project-access/{$this->centrix->id}", [
                'reason' => 'Temporary suspension',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_project_access', [
            'id' => $accessId,
            'status' => 'REVOKED',
        ]);

        // 3. Re-grant access
        $res3 = $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixOperatorRole->id],
                'version' => 2, // version was incremented on revoke
            ])
            ->assertOk();

        // Verify EXACT same database row ID is reused
        $this->assertEquals($accessId, $res3->json('data.id'));
        $this->assertEquals('PENDING', $res3->json('data.status'));
        $this->assertEquals('operator', $res3->json('data.roles.0.code'));

        // Verify exactly ONE record exists for (user_id, project_id)
        $this->assertEquals(1, UserProjectAccess::where('user_id', $this->targetUser->id)->where('project_id', $this->centrix->id)->count());
    }

    /**
     * Test E: Role from Project A cannot be assigned to Project B (Cross-project rejected).
     */
    public function test_cross_project_role_assignment_is_authoritatively_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->hrmsManagerRole->id], // HRMS role passed to CENTRIX
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Cross-project isolation violation', $response->json('message'));
    }

    /**
     * Test E2: Inactive project role is rejected.
     */
    public function test_inactive_role_assignment_is_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixInactiveRole->id],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Cannot assign inactive role', $response->json('message'));
    }

    /**
     * Test F: One project role update does not change other projects.
     */
    public function test_updating_role_on_one_project_does_not_affect_other_projects(): void
    {
        // Assign Centrix (Super Admin) and HRMS (Manager)
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ])
            ->assertOk();

        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->hrms->id,
                'role_ids' => [$this->hrmsManagerRole->id],
            ])
            ->assertOk();

        $hrmsAccessBefore = UserProjectAccess::with('roles')
            ->where('user_id', $this->targetUser->id)
            ->where('project_id', $this->hrms->id)
            ->first();

        // Update Centrix role from Super Admin to Operator via PATCH
        $patchResponse = $this->actingAs($this->superAdmin, 'web')
            ->patchJson("/api/v1/users/{$this->targetUser->id}/project-access/{$this->centrix->id}", [
                'role_ids' => [$this->centrixOperatorRole->id],
                'version' => 1,
            ]);

        $patchResponse->assertOk();
        $this->assertEquals('operator', $patchResponse->json('data.roles.0.code'));

        // HRMS must be 100% UNCHANGED
        $hrmsAccessAfter = UserProjectAccess::with('roles')
            ->where('user_id', $this->targetUser->id)
            ->where('project_id', $this->hrms->id)
            ->first();

        $this->assertEquals($hrmsAccessBefore->version, $hrmsAccessAfter->version);
        $this->assertEquals($hrmsAccessBefore->roles->pluck('id')->toArray(), $hrmsAccessAfter->roles->pluck('id')->toArray());
        $this->assertEquals('hrms_manager', $hrmsAccessAfter->roles->first()->code);
    }

    /**
     * Test G: Revoking one project does not change other projects or user login.
     */
    public function test_revoking_one_project_leaves_other_projects_and_user_intact(): void
    {
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ]);

        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->hrms->id,
                'role_ids' => [$this->hrmsManagerRole->id],
            ]);

        // Revoke Centrix
        $this->actingAs($this->superAdmin, 'web')
            ->deleteJson("/api/v1/users/{$this->targetUser->id}/project-access/{$this->centrix->id}", [
                'reason' => 'Offboarding from logistics',
            ])
            ->assertOk();

        // Check Centrix is REVOKED
        $centrixAccess = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->first();
        $this->assertEquals('REVOKED', $centrixAccess->status);

        // Check HRMS is still intact (PENDING/ACTIVE)
        $hrmsAccess = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->hrms->id)
            ->first();
        $this->assertNotEquals('REVOKED', $hrmsAccess->status);

        // Check user identity & login remains active
        $this->targetUser->refresh();
        $this->assertTrue($this->targetUser->is_active);
        $this->assertTrue($this->targetUser->can_login);
    }

    /**
     * Test H: GIAM internal roles remain strictly independent.
     */
    public function test_giam_internal_roles_remain_independent_from_project_roles(): void
    {
        // Target user is Staff in GIAM
        $this->assertEquals('staff', $this->targetUser->user_type);
        $this->assertFalse($this->targetUser->hasRole('Super Admin'));

        // Assign Centrix Super Admin role
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ])
            ->assertOk();

        // Target user in GIAM is STILL staff and NOT a GIAM Super Admin
        $this->targetUser->refresh();
        $this->assertEquals('staff', $this->targetUser->user_type);
        $this->assertFalse($this->targetUser->hasRole('Super Admin'));

        // Target user cannot access admin endpoints in GIAM
        $forbiddenResponse = $this->actingAs($this->targetUser, 'web')
            ->getJson('/api/v1/users');
        $forbiddenResponse->assertStatus(403);
    }

    /**
     * Test I: Pagination parameters are respected (default 15, max 100).
     */
    public function test_pagination_parameters_and_metadata(): void
    {
        // Request per_page=1
        $response = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/users/{$this->targetUser->id}/project-access?per_page=1");

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.per_page'));

        // Request per_page exceeding max (e.g. 500) gets clamped to 100
        $responseMax = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/users/{$this->targetUser->id}/project-access?per_page=500");

        $responseMax->assertOk();
        $this->assertEquals(100, $responseMax->json('meta.per_page'));
    }

    /**
     * Test J & K: Single project detail endpoint returns structured access without base_url.
     */
    public function test_project_access_detail_endpoint_distinguishes_roles_and_overrides(): void
    {
        // Assign Centrix role + explicit direct permission override
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
                'permission_ids' => [$this->centrixPerm->id],
            ])
            ->assertOk();

        $response = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/users/{$this->targetUser->id}/project-access/{$this->centrix->id}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals($this->centrix->id, $data['project_id']);
        $this->assertEquals('centrix', $data['project']['code']);
        $this->assertArrayNotHasKey('base_url', $data['project']);
        $this->assertArrayNotHasKey('api_base_url', $data['project']);

        // Explicit distinction between roles and direct permission overrides
        $this->assertCount(1, $data['roles']);
        $this->assertEquals('1', $data['roles'][0]['external_role_id']);
        $this->assertEquals('Super Admin', $data['roles'][0]['name']);

        $this->assertCount(1, $data['direct_permissions']);
        $this->assertEquals('view_freight', $data['direct_permissions'][0]['external_permission_id']);
        $this->assertTrue($data['direct_permissions'][0]['is_granted']);

        $this->assertEquals(1, $data['assigned_roles_count']);
        $this->assertEquals(1, $data['direct_permission_overrides_count']);
    }

    /**
     * Test L & M: myProjects returns only ACTIVE projects and does not misrepresent catalog permissions.
     */
    public function test_my_projects_endpoint_semantics(): void
    {
        // Assign Centrix
        $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
                'project_id' => $this->centrix->id,
                'role_ids' => [$this->centrixAdminRole->id],
            ]);

        // Mark access ACTIVE
        UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->update(['status' => 'ACTIVE']);

        $response = $this->actingAs($this->targetUser, 'web')
            ->getJson('/api/v1/auth/my-projects');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        $proj = $response->json('data.0');
        $this->assertEquals('centrix', $proj['code']);
        $this->assertEquals(1, $proj['assigned_roles_count']);
        $this->assertEquals(0, $proj['direct_permission_overrides_count']);
        $this->assertEquals(0, $proj['permissions_count']); // Must NOT fall back to catalog count
        $this->assertEquals([], $proj['permission_groups']); // Does not claim catalog groups as user access
    }
}
