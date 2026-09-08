<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;


class Goal4ProjectRegistryAndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Inject synthetic test-only credentials — NOT real integration secrets
        Config::set('services.centrix.frontend_url', 'http://localhost:3001');
        Config::set('services.centrix.api_url', 'http://localhost:8002/api/giam/integration');
        Config::set('services.centrix.client_id', 'test_centrix_client');
        Config::set('services.centrix.client_secret', 'test_centrix_secret');
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $this->seed(DatabaseSeeder::class);


        $this->superAdmin = User::where('username', 'user01')->first();

        // Create staff user
        $staffEmployee = Employee::create([
            'employee_code' => 'EMP4001',
            'f_name' => 'Regular',
            'l_name' => 'Staff',
            'full_name' => 'Regular Staff',
            'name_with_initials' => 'R. Staff',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '971112223V',
            'date_of_birth' => '1997-07-07',
            'email' => 'staff4001@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Staff St',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->staffUser = User::create([
            'employee_code' => 'EMP4001',
            'name' => 'Regular Staff',
            'username' => 'regular_staff',
            'email' => 'staff4001@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->staffUser->assignRole('Staff');
    }

    /**
     * Test 1: Project CRUD and role-based permissions.
     */
    public function test_project_crud_and_role_permissions(): void
    {
        // 1. Staff user is blocked from viewing and managing projects (HTTP 403)
        $this->actingAs($this->staffUser, 'web');
        $staffIndex = $this->getJson('/api/v1/projects');
        $staffIndex->assertStatus(403);

        $staffStore = $this->postJson('/api/v1/projects', [
            'code' => 'dm',
            'name' => 'Distribution Management',
            'base_url' => 'http://localhost:8004',
        ]);
        $staffStore->assertStatus(403);

        // 2. Super Admin can register a new project with integration settings
        $this->actingAs($this->superAdmin, 'web');
        $adminStore = $this->postJson('/api/v1/projects', [
            'code' => 'dm',
            'name' => 'Distribution Management',
            'description' => 'Delivery and wholesale route management portal',
            'base_url' => 'http://localhost:8004',
            'icon_url' => '/icons/dm.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url' => 'http://localhost:8004/api/giam/integration',
                'auth_method' => 'bearer_token',
                'client_id' => 'giam_dm_client',
                'client_secret' => 'super_secret_dm_key_999',
                'allowed_user_fields' => ['employee_code', 'full_name', 'email', 'phone_primary', 'department_code'],
            ],
        ]);

        $adminStore->assertStatus(201)
            ->assertJson([
                'data' => [
                    'code' => 'dm',
                    'name' => 'Distribution Management',
                ],
            ]);

        $this->assertDatabaseHas('projects', ['code' => 'dm']);
        $this->assertDatabaseHas('project_integrations', ['client_id' => 'giam_dm_client']);

        // 3. Update project metadata
        $updateResp = $this->putJson('/api/v1/projects/dm', [
            'name' => 'Distribution & Fleet Portal',
        ]);
        $updateResp->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => 'Distribution & Fleet Portal',
                ],
            ]);

        // 4. Disable project
        $deleteResp = $this->deleteJson('/api/v1/projects/dm');
        $deleteResp->assertStatus(200);
        $this->assertDatabaseHas('projects', ['code' => 'dm', 'status' => 'disabled']);
    }

    /**
     * Test 2: Client secrets are never returned in plaintext in API responses.
     */
    public function test_client_secret_is_never_returned_in_plaintext(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $hrms = Project::where('code', 'hrms')->first();

        // 1. Fetch via projects list
        $indexResp = $this->getJson('/api/v1/projects');
        $indexResp->assertStatus(200);
        $indexContent = $indexResp->getContent();
        $this->assertStringNotContainsString('test_hrms_secret', $indexContent);


        // 2. Fetch via project show
        $showResp = $this->getJson("/api/v1/projects/{$hrms->id}");
        $showResp->assertStatus(200);
        $showContent = $showResp->getContent();
        $this->assertStringNotContainsString('test_hrms_secret', $showContent);


        // 3. Fetch via integration show
        $integrationResp = $this->getJson("/api/v1/projects/{$hrms->id}/integration");
        $integrationResp->assertStatus(200)
            ->assertJson([
                'data' => [
                    'has_client_secret' => true,
                ],
            ]);
        $integrationContent = $integrationResp->getContent();
        $this->assertStringNotContainsString('test_hrms_secret', $integrationContent);


        // 4. Verify in database that it is encrypted, not plaintext
        $rawInDb = ProjectIntegration::where('project_id', $hrms->id)->value('encrypted_client_secret');
        $this->assertNotEquals('test_hrms_secret', $rawInDb);
        $this->assertEquals('test_hrms_secret', $hrms->integration->getDecryptedClientSecret());

    }

    /**
     * Test 3: Invalid allowed_user_fields are rejected at configuration time.
     */
    public function test_invalid_allowed_user_fields_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Attempt to configure forbidden fields (e.g. password, salary, fake_field)
        $response = $this->postJson('/api/v1/projects', [
            'code' => 'finance_portal',
            'name' => 'Finance Portal',
            'base_url' => 'http://localhost:8005',
            'integration' => [
                'api_base_url' => 'http://localhost:8005/api/giam/integration',
                'allowed_user_fields' => ['employee_code', 'salary', 'password_hash'], // FORBIDDEN!
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'integration.allowed_user_fields.1',
                'integration.allowed_user_fields.2',
            ]);
    }

    /**
     * Test 4: Project health-check runner updates status accurately.
     */
    public function test_project_health_check_runner_updates_status(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $hrms = Project::where('code', 'hrms')->first();

        // Mock sequential responses: 200 OK then 500 error
        Http::fake([
            'http://localhost:8001/api/giam/integration/health' => Http::sequence()
                ->push(['status' => 'UP'], 200)
                ->push(['error' => 'Internal Server Error'], 500),
        ]);

        $respSuccess = $this->postJson("/api/v1/projects/{$hrms->id}/health-check");
        $respSuccess->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'healthy',
                    'http_status' => 200,
                ],
            ]);

        $this->assertEquals('healthy', $hrms->fresh()->integration->status);
        $this->assertNotNull($hrms->fresh()->integration->last_health_check_at);

        // 2. Second request hits the 500 response
        $respFail = $this->postJson("/api/v1/projects/{$hrms->id}/health-check");
        $respFail->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'offline',
                    'http_status' => 500,
                ],
            ]);

        $this->assertEquals('offline', $hrms->fresh()->integration->status);
    }
}
