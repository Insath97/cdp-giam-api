<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectPermission;
use App\Models\ProjectRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Goal5ProjectRbacCatalogDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Project $hrms;
    protected Project $centrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();
        $this->hrms = Project::where('code', 'hrms')->first();
        $this->centrix = Project::where('code', 'centrix')->first();
    }

    /**
     * Test 1: RBAC catalog discovery for project with modules (HRMS).
     */
    public function test_catalog_discovery_with_modules(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Mock Section 14 standardized response
        Http::fake([
            'http://localhost:8001/api/giam/integration/access-definition' => Http::response([
                'project' => ['id' => 'hrms', 'name' => 'HRMS'],
                'modules' => [
                    ['id' => 'employee-module', 'name' => 'Employee Management', 'description' => 'Employee master records'],
                    ['id' => 'leave-module', 'name' => 'Leave Management', 'description' => 'Leave and attendance'],
                ],
                'roles' => [
                    ['id' => 'hrms-manager', 'name' => 'HRMS Manager', 'description' => 'Full managerial access'],
                    ['id' => 'hrms-viewer', 'name' => 'HRMS Viewer', 'description' => 'Read-only viewer'],
                ],
                'permissionGroups' => [
                    ['id' => 'employee-ops', 'name' => 'Employee Operations', 'description' => 'Staff operational permissions'],
                ],
                'permissions' => [
                    ['id' => 'employee-view', 'name' => 'Employee View', 'groupId' => 'employee-ops', 'description' => 'View employees'],
                    ['id' => 'employee-update', 'name' => 'Employee Update', 'groupId' => 'employee-ops', 'description' => 'Update employees'],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'project_code' => 'hrms',
                    'modules_synced' => 2,
                    'roles_synced' => 2,
                    'groups_synced' => 1,
                    'permissions_synced' => 2,
                ],
            ]);

        // Verify in DB
        $this->assertDatabaseHas('project_modules', [
            'project_id' => $this->hrms->id,
            'external_module_id' => 'employee-module',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('project_roles', [
            'project_id' => $this->hrms->id,
            'external_role_id' => 'hrms-manager',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('project_permissions', [
            'project_id' => $this->hrms->id,
            'external_permission_id' => 'employee-view',
        ]);

        // Fetch catalog representation
        $catalogResp = $this->getJson("/api/v1/projects/{$this->hrms->id}/catalog");
        $catalogResp->assertStatus(200)
            ->assertJson([
                'data' => [
                    'project_code' => 'hrms',
                ],
            ]);
        $this->assertCount(2, $catalogResp->json('data.modules'));
        $this->assertCount(2, $catalogResp->json('data.roles'));
    }

    /**
     * Test 2: RBAC catalog discovery for project without modules (CENTRIX).
     */
    public function test_catalog_discovery_without_modules(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Mock Section 15 response (empty/no modules)
        Http::fake([
            'http://localhost:8002/api/giam/integration/access-definition' => Http::response([
                'project' => ['id' => 'centrix', 'name' => 'CENTRIX CRM'],
                'modules' => [],
                'roles' => [
                    ['id' => 'sales-rep', 'name' => 'Sales Representative'],
                    ['id' => 'sales-lead', 'name' => 'Sales Team Lead'],
                ],
                'permissionGroups' => [
                    ['id' => 'deals', 'name' => 'Deals Management'],
                ],
                'permissions' => [
                    ['id' => 'deals-view', 'name' => 'View Deals', 'groupId' => 'deals'],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/projects/{$this->centrix->id}/sync-catalog");
        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'modules_synced' => 0,
                    'roles_synced' => 2,
                    'permissions_synced' => 1,
                ],
            ]);

        $this->assertEquals(0, ProjectModule::where('project_id', $this->centrix->id)->count());
        $this->assertEquals(2, ProjectRole::where('project_id', $this->centrix->id)->count());
    }

    /**
     * Test 3: Multiple projects with identical role names remain isolated by project_id.
     */
    public function test_cross_project_role_and_permission_isolation(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Both projects return a role with identical ID 'admin'
        Http::fake([
            'http://localhost:8001/api/giam/integration/access-definition' => Http::response([
                'modules' => [],
                'roles' => [['id' => 'admin', 'name' => 'HRMS Administrator']],
                'permissionGroups' => [],
                'permissions' => [['id' => 'hrms-admin-all', 'name' => 'HRMS Admin All']],
            ], 200),
            'http://localhost:8002/api/giam/integration/access-definition' => Http::response([
                'modules' => [],
                'roles' => [['id' => 'admin', 'name' => 'CENTRIX Administrator']],
                'permissionGroups' => [],
                'permissions' => [['id' => 'centrix-admin-all', 'name' => 'Centrix Admin All']],
            ], 200),
        ]);

        $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog")->assertStatus(200);
        $this->postJson("/api/v1/projects/{$this->centrix->id}/sync-catalog")->assertStatus(200);

        // Verify HRMS admin role is isolated to HRMS
        $hrmsRole = ProjectRole::where('project_id', $this->hrms->id)->where('external_role_id', 'admin')->first();
        $this->assertNotNull($hrmsRole);
        $this->assertEquals('HRMS Administrator', $hrmsRole->name);

        // Verify CENTRIX admin role is isolated to CENTRIX
        $centrixRole = ProjectRole::where('project_id', $this->centrix->id)->where('external_role_id', 'admin')->first();
        $this->assertNotNull($centrixRole);
        $this->assertEquals('CENTRIX Administrator', $centrixRole->name);

        $this->assertNotEquals($hrmsRole->id, $centrixRole->id);
    }

    /**
     * Test 4: Downstream outages do not affect existing cached catalog assignments.
     */
    public function test_downstream_outage_preserves_existing_cached_catalog(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // 1. Mock sequence: successful sync first, then 503 outage
        Http::fake([
            'http://localhost:8001/api/giam/integration/access-definition' => Http::sequence()
                ->push([
                    'modules' => [],
                    'roles' => [['id' => 'officer', 'name' => 'HR Officer']],
                    'permissionGroups' => [],
                    'permissions' => [['id' => 'view-profile', 'name' => 'View Profile']],
                ], 200)
                ->push(['error' => 'Maintenance'], 503),
        ]);

        $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog")->assertStatus(200);
        $this->assertEquals(1, ProjectRole::where('project_id', $this->hrms->id)->count());

        // 2. Downstream second request hits 503 Service Unavailable
        $failResp = $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog");
        $failResp->assertStatus(500); // Handled cleanly without corrupting DB

        // Cached catalog still intact!
        $this->assertDatabaseHas('project_roles', [
            'project_id' => $this->hrms->id,
            'external_role_id' => 'officer',
            'is_active' => 1,
        ]);
    }

    /**
     * Test 5: Obsolete roles deleted remotely are marked is_active = 0, not deleted.
     */
    public function test_obsolete_roles_marked_inactive_not_deleted(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // 1. Initial sync with role A and role B
        Http::fake([
            'http://localhost:8001/api/giam/integration/access-definition' => Http::sequence()
                ->push([
                    'modules' => [],
                    'roles' => [
                        ['id' => 'role_a', 'name' => 'Role A'],
                        ['id' => 'role_b', 'name' => 'Role B'],
                    ],
                    'permissionGroups' => [],
                    'permissions' => [],
                ], 200)
                ->push([
                    'modules' => [],
                    'roles' => [
                        ['id' => 'role_a', 'name' => 'Role A'], // role_b was deleted downstream!
                    ],
                    'permissionGroups' => [],
                    'permissions' => [],
                ], 200),
        ]);

        $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog")->assertStatus(200);
        $this->assertDatabaseHas('project_roles', ['project_id' => $this->hrms->id, 'external_role_id' => 'role_b', 'is_active' => 1]);

        // 2. Subsequent sync without role_b
        $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog")->assertStatus(200);

        // role_b is preserved in DB but marked inactive (is_active = 0)
        $this->assertDatabaseHas('project_roles', [
            'project_id' => $this->hrms->id,
            'external_role_id' => 'role_b',
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('project_roles', [
            'project_id' => $this->hrms->id,
            'external_role_id' => 'role_a',
            'is_active' => 1,
        ]);
    }

    /**
     * Test 6: POST /api/v1/projects/{id}/sync-catalog requires PROJECT_MANAGE permission.
     */
    public function test_sync_catalog_requires_project_manage_permission(): void
    {
        $staffEmployee = Employee::create([
            'employee_code' => 'EMP_STAFF_501',
            'f_name' => 'Regular',
            'l_name' => 'Staff',
            'full_name' => 'Regular Staff 501',
            'name_with_initials' => 'R. Staff',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '978889991V',
            'date_of_birth' => '1997-01-01',
            'email' => 'staff501@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Staff Road',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $staffUser = User::create([
            'employee_code' => 'EMP_STAFF_501',
            'name' => 'Regular Staff 501',
            'username' => 'staff501',
            'email' => 'staff501@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $staffUser->assignRole('Staff');

        $this->actingAs($staffUser, 'web');

        $response = $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog");
        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'FORBIDDEN',
            ]);
    }

    /**
     * Test 7: Obsolete modules and permissions are also flagged is_active = 0.
     */
    public function test_obsolete_modules_and_permissions_marked_inactive(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        Http::fake([
            'http://localhost:8001/api/giam/integration/access-definition' => Http::sequence()
                ->push([
                    'modules' => [
                        ['id' => 'mod_old', 'name' => 'Old Module'],
                        ['id' => 'mod_keep', 'name' => 'Keep Module'],
                    ],
                    'roles' => [],
                    'permissionGroups' => [
                        ['id' => 'grp_old', 'name' => 'Old Group'],
                    ],
                    'permissions' => [
                        ['id' => 'perm_old', 'name' => 'Old Perm', 'groupId' => 'grp_old'],
                    ],
                ], 200)
                ->push([
                    'modules' => [
                        ['id' => 'mod_keep', 'name' => 'Keep Module'], // mod_old removed!
                    ],
                    'roles' => [],
                    'permissionGroups' => [], // grp_old and perm_old removed!
                    'permissions' => [],
                ], 200),
        ]);

        $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog")->assertStatus(200);
        $this->assertDatabaseHas('project_modules', ['external_module_id' => 'mod_old', 'is_active' => 1]);
        $this->assertDatabaseHas('project_permissions', ['external_permission_id' => 'perm_old', 'is_active' => 1]);

        // Second sync
        $this->postJson("/api/v1/projects/{$this->hrms->id}/sync-catalog")->assertStatus(200);

        // Flagged is_active = 0
        $this->assertDatabaseHas('project_modules', ['external_module_id' => 'mod_old', 'is_active' => 0]);
        $this->assertDatabaseHas('project_modules', ['external_module_id' => 'mod_keep', 'is_active' => 1]);
        $this->assertDatabaseHas('project_permissions', ['external_permission_id' => 'perm_old', 'is_active' => 0]);
    }
}
