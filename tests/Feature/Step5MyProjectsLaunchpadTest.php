<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Models\UserProjectRole;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Step5MyProjectsLaunchpadTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected User $superAdmin;
    protected Project $centrix;
    protected Project $projectB;
    protected ProjectRole $centrixRole;
    protected ProjectRole $projectBRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Employee & User A
        $empA = Employee::create([
            'employee_code' => 'EMP9001',
            'f_name' => 'Alice',
            'l_name' => 'Alpha',
            'full_name' => 'Alice Alpha',
            'name_with_initials' => 'A. Alpha',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '950000001V',
            'date_of_birth' => '1995-01-01',
            'email' => 'alice@cdp.lk',
            'phone' => '+94112345001',
            'address_line_1' => 'Alpha St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001001',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->userA = User::create([
            'employee_code' => 'EMP9001',
            'name' => 'Alice Alpha',
            'username' => 'alice_a',
            'email' => 'alice@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->userA->syncRoles(['Staff']);

        // Employee & User B
        $empB = Employee::create([
            'employee_code' => 'EMP9002',
            'f_name' => 'Bob',
            'l_name' => 'Beta',
            'full_name' => 'Bob Beta',
            'name_with_initials' => 'B. Beta',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '950000002V',
            'date_of_birth' => '1995-02-02',
            'email' => 'bob@cdp.lk',
            'phone' => '+94112345002',
            'address_line_1' => 'Beta St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001002',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->userB = User::create([
            'employee_code' => 'EMP9002',
            'name' => 'Bob Beta',
            'username' => 'bob_b',
            'email' => 'bob@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->userB->syncRoles(['Staff']);

        // Super Admin with ZERO project assignments
        $empAdmin = Employee::create([
            'employee_code' => 'EMP9003',
            'f_name' => 'Super',
            'l_name' => 'Admin',
            'full_name' => 'Super Admin',
            'name_with_initials' => 'S. Admin',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '950000003V',
            'date_of_birth' => '1995-03-03',
            'email' => 'superadmin@cdp.lk',
            'phone' => '+94112345003',
            'address_line_1' => 'Admin Tower',
            'city' => 'Colombo',
            'phone_primary' => '+94770001003',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->superAdmin = User::create([
            'employee_code' => 'EMP9003',
            'name' => 'Super Admin',
            'username' => 'super_admin',
            'email' => 'superadmin@cdp.lk',
            'password' => 'password123',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->superAdmin->syncRoles(['Super Admin']);

        // Projects
        $this->centrix = Project::with('integration')->where('code', 'centrix')->firstOrFail();
        $this->centrixRole = ProjectRole::firstOrCreate(
            ['project_id' => $this->centrix->id, 'code' => 'warehouse_manager'],
            ['name' => 'Warehouse Manager', 'is_active' => true, 'external_role_id' => '10']
        );

        $this->projectB = Project::firstOrCreate(
            ['code' => 'project_b'],
            [
                'name' => 'Project Bravo',
                'description' => 'Secondary project',
                'base_url' => 'https://bravo.local',
                'status' => 'active',
                'version' => 1,
            ]
        );
        $this->projectBRole = ProjectRole::firstOrCreate(
            ['project_id' => $this->projectB->id, 'code' => 'bravo_analyst'],
            ['name' => 'Bravo Analyst', 'is_active' => true, 'external_role_id' => '99']
        );

        ProjectIntegration::firstOrCreate(
            ['project_id' => $this->projectB->id],
            [
                'api_base_url' => 'https://bravo.local/api',
                'client_id' => 'bravo_client_id',
                'client_secret' => 'super_secret_bravo_key',
                'redirect_uris' => ['https://bravo.local/sso/callback'],
                'allowed_user_fields' => ['employee_code', 'email', 'full_name'],
                'sso_enabled' => true,
                'sync_enabled' => true,
            ]
        );
    }

    /**
     * Test 1: User sees only own active project assignments.
     * User A has Project 1 & Project 2. User B has only Project 2.
     */
    public function test_user_sees_only_own_active_project_assignments(): void
    {
        // User A assigned Centrix and Project B
        $accessA1 = UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);
        UserProjectRole::create(['user_project_access_id' => $accessA1->id, 'project_role_id' => $this->centrixRole->id, 'external_role_id' => '10']);

        $accessA2 = UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->projectB->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);
        UserProjectRole::create(['user_project_access_id' => $accessA2->id, 'project_role_id' => $this->projectBRole->id, 'external_role_id' => '99']);

        // User B assigned ONLY Project B
        $accessB = UserProjectAccess::create([
            'user_id' => $this->userB->id,
            'project_id' => $this->projectB->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);
        UserProjectRole::create(['user_project_access_id' => $accessB->id, 'project_role_id' => $this->projectBRole->id, 'external_role_id' => '99']);

        // User A queries My Projects
        $resA = $this->actingAs($this->userA)->getJson('/api/v1/auth/my-projects');
        $resA->assertOk();
        $dataA = $resA->json('data');
        $this->assertCount(2, $dataA);
        $projectCodesA = collect($dataA)->pluck('code')->toArray();
        $this->assertContains('centrix', $projectCodesA);
        $this->assertContains('project_b', $projectCodesA);

        // User B queries My Projects
        $resB = $this->actingAs($this->userB)->getJson('/api/v1/auth/my-projects');
        $resB->assertOk();
        $dataB = $resB->json('data');
        $this->assertCount(1, $dataB);
        $this->assertEquals('project_b', $dataB[0]['code']);
    }

    /**
     * Test 2: Super Admin with zero assignments sees zero projects (no implicit project access).
     */
    public function test_super_admin_with_no_assignments_sees_zero_projects(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/auth/my-projects');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    /**
     * Test 3: Revoked and pending assignments are excluded (ACTIVE-only filtering).
     */
    public function test_revoked_and_pending_assignments_are_excluded(): void
    {
        // Centrix: REVOKED
        UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->centrix->id,
            'status' => 'REVOKED',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);

        // Project B: PENDING
        UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->projectB->id,
            'status' => 'PENDING',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);

        $response = $this->actingAs($this->userA)->getJson('/api/v1/auth/my-projects');

        $response->assertOk();
        // Both REVOKED and PENDING are excluded
        $this->assertEmpty($response->json('data'));
    }

    /**
     * Test 4: Cross-user scoping cannot be bypassed by client parameters.
     */
    public function test_cross_user_access_prevented(): void
    {
        // User B has Project B
        UserProjectAccess::create([
            'user_id' => $this->userB->id,
            'project_id' => $this->projectB->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);

        // User A attempts to pass user_id parameter pointing to User B
        $response = $this->actingAs($this->userA)->getJson("/api/v1/auth/my-projects?user_id={$this->userB->id}");

        $response->assertOk();
        // Must return User A's projects (empty), NOT User B's
        $this->assertEmpty($response->json('data'));
    }

    /**
     * Test 5: Technical integration secrets are not exposed in my-projects payload.
     */
    public function test_technical_integration_secrets_are_not_exposed(): void
    {
        UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->projectB->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);

        $response = $this->actingAs($this->userA)->getJson('/api/v1/auth/my-projects');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringNotContainsString('super_secret_bravo_key', $content);
        $this->assertStringNotContainsString('base_url', $content);
        $this->assertStringNotContainsString('client_secret', $content);
    }

    /**
     * Test 6: Truthful project roles returned and no fake catalog permissions fabricated.
     */
    public function test_assigned_project_roles_are_truthfully_returned(): void
    {
        $access = UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);
        UserProjectRole::create(['user_project_access_id' => $access->id, 'project_role_id' => $this->centrixRole->id, 'external_role_id' => '10']);

        $response = $this->actingAs($this->userA)->getJson('/api/v1/auth/my-projects');

        $response->assertOk();
        $proj = $response->json('data.0');

        $this->assertEquals('centrix', $proj['code']);
        $this->assertCount(1, $proj['roles']);
        $this->assertEquals('warehouse_manager', $proj['roles'][0]['code']);
        $this->assertEquals('Warehouse Manager', $proj['roles'][0]['name']);
        $this->assertEquals(0, $proj['permissions_count']);
        $this->assertEquals([], $proj['permission_groups']);
    }

    /**
     * Test 7: SSO launch is permitted for active assigned project, but rejected for non-assigned project.
     */
    public function test_sso_launch_authorization_gated_by_active_project_access(): void
    {
        // User A assigned ONLY Project B
        UserProjectAccess::create([
            'user_id' => $this->userA->id,
            'project_id' => $this->projectB->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
            'version' => 1,
        ]);

        $verifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        // Launching Project B: succeeds
        $resB = $this->actingAs($this->userA)->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->projectB->id,
            'redirect_uri' => 'https://bravo.local/sso/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
        $resB->assertOk();
        $this->assertNotEmpty($resB->json('data.authorization_code'));
        $this->assertNotEmpty($resB->json('data.redirect_url'));

        $projectUnassigned = Project::create([
            'code' => 'project_unassigned',
            'name' => 'Unassigned Project',
            'description' => 'Not assigned to User A',
            'base_url' => 'https://unassigned.local',
            'status' => 'active',
            'version' => 1,
        ]);

        ProjectIntegration::create([
            'project_id' => $projectUnassigned->id,
            'api_base_url' => 'https://unassigned.local/api',
            'client_id' => 'unassigned_client_id',
            'client_secret' => 'unassigned_secret_key',
            'redirect_uris' => ['https://unassigned.local/sso/callback'],
            'allowed_user_fields' => ['employee_code', 'email'],
            'sso_enabled' => true,
            'sync_enabled' => true,
        ]);

        // Launching projectUnassigned (not assigned to User A): rejected with 403 Forbidden
        $resUnassigned = $this->actingAs($this->userA)->postJson('/api/v1/sso/authorize', [
            'project_id' => $projectUnassigned->id,
            'redirect_uri' => 'https://unassigned.local/sso/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
        $resUnassigned->assertStatus(403);
    }
}
