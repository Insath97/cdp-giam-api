<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectPermission;
use App\Models\ProjectPermissionGroup;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Models\UserProjectRole;
use App\Services\Access\ProjectAccessAssignmentService;
use App\Services\RbacCatalog\CatalogSyncHandler;
use App\Services\RbacCatalog\ProjectRbacDiscoveryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class Step6ProjectCatalogReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $targetUser;
    protected Project $testProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $empAdmin = Employee::create([
            'employee_code' => 'EMP6001',
            'f_name' => 'Admin',
            'l_name' => 'User',
            'full_name' => 'Admin User',
            'name_with_initials' => 'A. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '960000001V',
            'date_of_birth' => '1996-01-01',
            'email' => 'admin.catalog@cdp.lk',
            'phone' => '+94112346001',
            'address_line_1' => 'Tower',
            'city' => 'Colombo',
            'phone_primary' => '+94770006001',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->adminUser = User::create([
            'employee_code' => 'EMP6001',
            'name' => 'Admin User',
            'username' => 'admin_catalog',
            'email' => 'admin.catalog@cdp.lk',
            'password' => 'password123',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->adminUser->syncRoles(['Super Admin']);

        $empTarget = Employee::create([
            'employee_code' => 'EMP6002',
            'f_name' => 'Target',
            'l_name' => 'Worker',
            'full_name' => 'Target Worker',
            'name_with_initials' => 'T. Worker',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '960000002V',
            'date_of_birth' => '1996-02-02',
            'email' => 'target.worker@cdp.lk',
            'phone' => '+94112346002',
            'address_line_1' => 'Lane',
            'city' => 'Colombo',
            'phone_primary' => '+94770006002',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->targetUser = User::create([
            'employee_code' => 'EMP6002',
            'name' => 'Target Worker',
            'username' => 'target_worker',
            'email' => 'target.worker@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->targetUser->syncRoles(['Staff']);

        $this->testProject = Project::create([
            'code' => 'test_project',
            'name' => 'Test Project',
            'description' => 'Test Project for catalog reconciliation',
            'base_url' => 'https://testproject.local',
            'status' => 'active',
            'version' => 1,
        ]);

        ProjectIntegration::create([
            'project_id' => $this->testProject->id,
            'api_base_url' => 'https://testproject.local/api',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_secret_key',
            'redirect_uris' => ['https://testproject.local/sso/callback'],
            'allowed_user_fields' => ['employee_code', 'email', 'full_name'],
            'sso_enabled' => true,
            'sync_enabled' => true,
        ]);
    }

    /**
     * Test 1, 2, 3: Project with zero permission groups can synchronize permissions successfully,
     * no synthetic "general" permission group is created, and permissions are stored with
     * project_permission_group_id = NULL.
     */
    public function test_project_with_zero_permission_groups_stores_null_group_id_and_no_synthetic_general(): void
    {
        $mockDiscovery = Mockery::mock(ProjectRbacDiscoveryService::class);
        $mockDiscovery->shouldReceive('fetchAccessDefinition')
            ->once()
            ->with(Mockery::on(fn ($p) => $p->id === $this->testProject->id))
            ->andReturn([
                'modules' => [],
                'roles' => [
                    ['id' => '101', 'code' => 'operator', 'name' => 'Operator', 'description' => 'Machine Operator'],
                ],
                'permissionGroups' => [], // ZERO permission groups returned
                'permissions' => [
                    ['id' => '201', 'code' => 'machine-start', 'name' => 'Machine Start', 'description' => 'Start the machine'],
                    ['id' => '202', 'code' => 'machine-stop', 'name' => 'Machine Stop', 'description' => 'Stop the machine'],
                ],
            ]);

        $this->app->instance(ProjectRbacDiscoveryService::class, $mockDiscovery);

        $handler = app(CatalogSyncHandler::class);
        $stats = $handler->sync($this->testProject, $this->adminUser);

        $this->assertEquals(1, $stats['roles_synced']);
        $this->assertEquals(0, $stats['groups_synced']);
        $this->assertEquals(2, $stats['permissions_synced']);

        // Assert NO synthetic "general" group was created
        $this->assertDatabaseMissing('project_permission_groups', [
            'project_id' => $this->testProject->id,
            'external_group_id' => 'general',
        ]);
        $this->assertEquals(0, ProjectPermissionGroup::where('project_id', $this->testProject->id)->count());

        // Assert both permissions are stored with project_permission_group_id = NULL
        $p1 = ProjectPermission::where('project_id', $this->testProject->id)
            ->where('external_permission_id', '201')
            ->firstOrFail();
        $this->assertNull($p1->project_permission_group_id);
        $this->assertTrue($p1->is_active);

        $p2 = ProjectPermission::where('project_id', $this->testProject->id)
            ->where('external_permission_id', '202')
            ->firstOrFail();
        $this->assertNull($p2->project_permission_group_id);
        $this->assertTrue($p2->is_active);
    }

    /**
     * Test 4, 5: Project with real permission groups synchronizes them and associates
     * permissions with their real synchronized group.
     */
    public function test_project_with_real_permission_groups_associates_correctly(): void
    {
        $mockDiscovery = Mockery::mock(ProjectRbacDiscoveryService::class);
        $mockDiscovery->shouldReceive('fetchAccessDefinition')
            ->once()
            ->with(Mockery::on(fn ($p) => $p->id === $this->testProject->id))
            ->andReturn([
                'modules' => [],
                'roles' => [],
                'permissionGroups' => [
                    ['id' => 'grp_ops', 'code' => 'operations', 'name' => 'Operations Group', 'description' => 'Ops permissions'],
                ],
                'permissions' => [
                    ['id' => 'perm_301', 'code' => 'ops-run', 'name' => 'Ops Run', 'groupId' => 'grp_ops'],
                    ['id' => 'perm_302', 'code' => 'global-audit', 'name' => 'Global Audit'], // no group
                ],
            ]);

        $this->app->instance(ProjectRbacDiscoveryService::class, $mockDiscovery);

        $handler = app(CatalogSyncHandler::class);
        $handler->sync($this->testProject, $this->adminUser);

        // Verify group was created
        $group = ProjectPermissionGroup::where('project_id', $this->testProject->id)
            ->where('external_group_id', 'grp_ops')
            ->firstOrFail();
        $this->assertTrue($group->is_active);

        // perm_301 has groupId = $group->id
        $permWithGroup = ProjectPermission::where('project_id', $this->testProject->id)
            ->where('external_permission_id', 'perm_301')
            ->firstOrFail();
        $this->assertEquals($group->id, $permWithGroup->project_permission_group_id);

        // perm_302 has groupId = null
        $permWithoutGroup = ProjectPermission::where('project_id', $this->testProject->id)
            ->where('external_permission_id', 'perm_302')
            ->firstOrFail();
        $this->assertNull($permWithoutGroup->project_permission_group_id);
    }

    /**
     * Test 6, 7: Missing downstream definitions are marked inactive, NOT deleted.
     */
    public function test_missing_downstream_definitions_are_marked_inactive_not_deleted(): void
    {
        // 1. Initial sync with Role A, Role B, and Perm X, Perm Y
        $mockDiscovery1 = Mockery::mock(ProjectRbacDiscoveryService::class);
        $mockDiscovery1->shouldReceive('fetchAccessDefinition')
            ->once()
            ->andReturn([
                'modules' => [],
                'roles' => [
                    ['id' => 'R1', 'code' => 'role_1', 'name' => 'Role One'],
                    ['id' => 'R2', 'code' => 'role_2', 'name' => 'Role Two'],
                ],
                'permissionGroups' => [],
                'permissions' => [
                    ['id' => 'P1', 'code' => 'perm_1', 'name' => 'Perm One'],
                    ['id' => 'P2', 'code' => 'perm_2', 'name' => 'Perm Two'],
                ],
            ]);
        $this->app->instance(ProjectRbacDiscoveryService::class, $mockDiscovery1);
        app(CatalogSyncHandler::class)->sync($this->testProject, $this->adminUser);

        $this->assertEquals(2, ProjectRole::where('project_id', $this->testProject->id)->where('is_active', true)->count());
        $this->assertEquals(2, ProjectPermission::where('project_id', $this->testProject->id)->where('is_active', true)->count());

        // 2. Second sync where R2 and P2 have been REMOVED downstream
        $mockDiscovery2 = Mockery::mock(ProjectRbacDiscoveryService::class);
        $mockDiscovery2->shouldReceive('fetchAccessDefinition')
            ->once()
            ->andReturn([
                'modules' => [],
                'roles' => [
                    ['id' => 'R1', 'code' => 'role_1', 'name' => 'Role One'],
                ],
                'permissionGroups' => [],
                'permissions' => [
                    ['id' => 'P1', 'code' => 'perm_1', 'name' => 'Perm One'],
                ],
            ]);
        $this->app->instance(ProjectRbacDiscoveryService::class, $mockDiscovery2);
        app(CatalogSyncHandler::class)->sync($this->testProject, $this->adminUser);

        // Verify R2 is still in DB, but is_active = false
        $r2 = ProjectRole::where('project_id', $this->testProject->id)
            ->where('external_role_id', 'R2')
            ->firstOrFail();
        $this->assertFalse($r2->is_active);

        // Verify P2 is still in DB, but is_active = false
        $p2 = ProjectPermission::where('project_id', $this->testProject->id)
            ->where('external_permission_id', 'P2')
            ->firstOrFail();
        $this->assertFalse($p2->is_active);

        // Verify R1 and P1 remain active
        $this->assertTrue(ProjectRole::where('project_id', $this->testProject->id)->where('external_role_id', 'R1')->firstOrFail()->is_active);
        $this->assertTrue(ProjectPermission::where('project_id', $this->testProject->id)->where('external_permission_id', 'P1')->firstOrFail()->is_active);
    }

    /**
     * Test 8, 9: Inactive role and inactive permission cannot be newly assigned (HTTP 422).
     */
    public function test_inactive_role_and_permission_rejected_by_assignment_service(): void
    {
        $roleInactive = ProjectRole::create([
            'project_id' => $this->testProject->id,
            'external_role_id' => 'INACTIVE_ROLE',
            'code' => 'inactive_role',
            'name' => 'Inactive Role',
            'is_active' => false,
        ]);

        $permInactive = ProjectPermission::create([
            'project_id' => $this->testProject->id,
            'external_permission_id' => 'INACTIVE_PERM',
            'code' => 'inactive_perm',
            'name' => 'Inactive Perm',
            'is_active' => false,
        ]);

        $roleActive = ProjectRole::create([
            'project_id' => $this->testProject->id,
            'external_role_id' => 'ACTIVE_ROLE',
            'code' => 'active_role',
            'name' => 'Active Role',
            'is_active' => true,
        ]);

        $assignmentService = app(ProjectAccessAssignmentService::class);

        // Attempt assigning inactive role -> throws 422
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $assignmentService->assignAccess(
            user: $this->targetUser,
            projectId: $this->testProject->id,
            roleIds: [$roleInactive->id],
            permissionIds: [],
            actor: $this->adminUser
        );
    }

    /**
     * Test 10: Assignment catalog query excludes inactive roles when active_only is set.
     */
    public function test_catalog_show_endpoint_filters_inactive_roles_with_active_only_flag(): void
    {
        $roleActive = ProjectRole::create([
            'project_id' => $this->testProject->id,
            'external_role_id' => 'R_ACT',
            'code' => 'active_r',
            'name' => 'Active R',
            'is_active' => true,
        ]);

        $roleInactive = ProjectRole::create([
            'project_id' => $this->testProject->id,
            'external_role_id' => 'R_INACT',
            'code' => 'inactive_r',
            'name' => 'Inactive R',
            'is_active' => false,
        ]);

        // Default call without active_only preserves reference history
        $resDefault = $this->actingAs($this->adminUser)->getJson("/api/v1/projects/{$this->testProject->id}/catalog");
        $resDefault->assertOk();
        $this->assertCount(2, $resDefault->json('data.roles'));
        // assignable_roles always provides only active roles
        $this->assertCount(1, $resDefault->json('data.assignable_roles'));
        $this->assertEquals('active_r', $resDefault->json('data.assignable_roles.0.code'));

        // Query with active_only=true filters both roles and assignable_roles
        $resFiltered = $this->actingAs($this->adminUser)->getJson("/api/v1/projects/{$this->testProject->id}/catalog?active_only=true");
        $resFiltered->assertOk();
        $this->assertCount(1, $resFiltered->json('data.roles'));
        $this->assertEquals('active_r', $resFiltered->json('data.roles.0.code'));
    }

    /**
     * Test 11: Cross-project role isolation still authoritatively rejected.
     */
    public function test_cross_project_role_assignment_is_rejected(): void
    {
        $projectOther = Project::create([
            'code' => 'other_proj',
            'name' => 'Other Proj',
            'base_url' => 'https://other.local',
            'status' => 'active',
            'version' => 1,
        ]);
        $otherRole = ProjectRole::create([
            'project_id' => $projectOther->id,
            'external_role_id' => 'OTHER_ROLE',
            'code' => 'other_role',
            'name' => 'Other Role',
            'is_active' => true,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ProjectAccessAssignmentService::class)->assignAccess(
            user: $this->targetUser,
            projectId: $this->testProject->id,
            roleIds: [$otherRole->id],
            permissionIds: [],
            actor: $this->adminUser
        );
    }

    /**
     * Test 12: Existing assignments are NOT automatically deleted when catalog definition becomes inactive.
     */
    public function test_existing_assignments_preserved_when_catalog_role_deactivated(): void
    {
        $role = ProjectRole::create([
            'project_id' => $this->testProject->id,
            'external_role_id' => 'ROLE_TO_DEACTIVATE',
            'code' => 'r_deact',
            'name' => 'Role To Deactivate',
            'is_active' => true,
        ]);

        $access = UserProjectAccess::create([
            'user_id' => $this->targetUser->id,
            'project_id' => $this->testProject->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);

        $userProjRole = UserProjectRole::create([
            'user_project_access_id' => $access->id,
            'project_role_id' => $role->id,
            'external_role_id' => $role->external_role_id,
        ]);

        // Downstream sync runs and deactivates the role because it's no longer returned
        $mockDiscovery = Mockery::mock(ProjectRbacDiscoveryService::class);
        $mockDiscovery->shouldReceive('fetchAccessDefinition')
            ->once()
            ->andReturn([
                'modules' => [],
                'roles' => [], // Empty roles list
                'permissionGroups' => [],
                'permissions' => [],
            ]);
        $this->app->instance(ProjectRbacDiscoveryService::class, $mockDiscovery);
        app(CatalogSyncHandler::class)->sync($this->testProject, $this->adminUser);

        // Role definition is marked inactive
        $this->assertFalse($role->fresh()->is_active);

        // UserProjectAccess row STILL EXISTS and is ACTIVE
        $this->assertDatabaseHas('user_project_access', [
            'id' => $access->id,
            'status' => 'ACTIVE',
        ]);

        // UserProjectRole row STILL EXISTS
        $this->assertDatabaseHas('user_project_roles', [
            'id' => $userProjRole->id,
            'user_project_access_id' => $access->id,
            'project_role_id' => $role->id,
        ]);
    }
}
