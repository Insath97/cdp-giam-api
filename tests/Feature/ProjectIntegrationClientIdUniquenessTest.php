<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectRole;
use App\Models\SsoAuthCode;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enterprise scale test suite verifying global project integration client_id uniqueness,
 * database unique constraints, application validation, self-ignore updates, and SSO lookup resolution.
 */
class ProjectIntegrationClientIdUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->adminUser = User::where('username', 'user01')->firstOrFail();
    }

    /**
     * Test 1: First non-null client_id can be stored successfully.
     */
    public function test_first_non_null_client_id_can_be_stored(): void
    {
        $project = Project::create([
            'code' => 'project_alpha',
            'name' => 'Alpha Logistics',
            'base_url' => 'http://alpha.internal',
            'status' => 'active',
        ]);

        $integration = ProjectIntegration::create([
            'project_id' => $project->id,
            'api_base_url' => 'http://alpha.internal/api',
            'auth_method' => 'oauth2',
            'client_id' => 'giam_alpha_unique_client',
            'client_secret' => 'alpha_secret_123',
            'allowed_user_fields' => ['employee_code', 'email'],
            'sync_enabled' => true,
            'sso_enabled' => true,
        ]);

        $this->assertDatabaseHas('project_integrations', [
            'id' => $integration->id,
            'client_id' => 'giam_alpha_unique_client',
        ]);
    }

    /**
     * Test 2: Duplicate client_id for a different ProjectIntegration is rejected by DB constraint.
     */
    public function test_duplicate_client_id_for_different_integration_is_rejected_by_database(): void
    {
        $projectA = Project::create([
            'code' => 'project_a',
            'name' => 'Project A',
            'base_url' => 'http://a.internal',
        ]);

        $projectB = Project::create([
            'code' => 'project_b',
            'name' => 'Project B',
            'base_url' => 'http://b.internal',
        ]);

        ProjectIntegration::create([
            'project_id' => $projectA->id,
            'api_base_url' => 'http://a.internal/api',
            'client_id' => 'shared_duplicate_client_id',
            'allowed_user_fields' => ['email'],
        ]);

        $this->expectException(QueryException::class);

        ProjectIntegration::create([
            'project_id' => $projectB->id,
            'api_base_url' => 'http://b.internal/api',
            'client_id' => 'shared_duplicate_client_id',
            'allowed_user_fields' => ['email'],
        ]);
    }

    /**
     * Test 3: Different client_ids for different integrations succeed.
     */
    public function test_different_client_ids_for_different_integrations_succeed(): void
    {
        $projectOne = Project::create([
            'code' => 'proj_one',
            'name' => 'Project One',
            'base_url' => 'http://one.internal',
        ]);

        $projectTwo = Project::create([
            'code' => 'proj_two',
            'name' => 'Project Two',
            'base_url' => 'http://two.internal',
        ]);

        $integrationOne = ProjectIntegration::create([
            'project_id' => $projectOne->id,
            'api_base_url' => 'http://one.internal/api',
            'client_id' => 'client_id_one',
            'allowed_user_fields' => ['email'],
        ]);

        $integrationTwo = ProjectIntegration::create([
            'project_id' => $projectTwo->id,
            'api_base_url' => 'http://two.internal/api',
            'client_id' => 'client_id_two',
            'allowed_user_fields' => ['email'],
        ]);

        $this->assertDatabaseHas('project_integrations', ['id' => $integrationOne->id, 'client_id' => 'client_id_one']);
        $this->assertDatabaseHas('project_integrations', ['id' => $integrationTwo->id, 'client_id' => 'client_id_two']);
    }

    /**
     * Test 4: Existing integration can retain its unchanged client_id during update.
     */
    public function test_existing_integration_can_retain_its_unchanged_client_id_during_update(): void
    {
        $centrix = Project::where('code', 'centrix')->firstOrFail();
        $currentClientId = $centrix->integration->client_id;
        $this->assertNotNull($currentClientId);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/projects/{$centrix->id}/integration", [
                'api_base_url' => $centrix->integration->api_base_url,
                'client_id' => $currentClientId, // Unchanged
                'allowed_user_fields' => ['email', 'full_name'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.client_id', $currentClientId);
    }

    /**
     * Test 5: Changing an integration to another integration's client_id is rejected by validation.
     */
    public function test_changing_an_integration_to_another_integrations_client_id_is_rejected(): void
    {
        $hrms = Project::where('code', 'hrms')->firstOrFail();
        $centrix = Project::where('code', 'centrix')->firstOrFail();

        $hrmsClientId = $hrms->integration->client_id;
        $this->assertNotNull($hrmsClientId);

        // Try updating Centrix integration to use HRMS's client_id
        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/projects/{$centrix->id}/integration", [
                'api_base_url' => $centrix->integration->api_base_url,
                'client_id' => $hrmsClientId,
                'allowed_user_fields' => ['email'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id']);
    }

    /**
     * Test 5b: StoreProjectRequest rejects duplicate client_id on project registration.
     */
    public function test_store_project_request_rejects_duplicate_client_id(): void
    {
        $hrms = Project::where('code', 'hrms')->firstOrFail();
        $hrmsClientId = $hrms->integration->client_id;

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/projects', [
                'code' => 'new_conflicting_project',
                'name' => 'Conflicting Project',
                'base_url' => 'http://conflict.internal',
                'integration' => [
                    'api_base_url' => 'http://conflict.internal/api',
                    'client_id' => $hrmsClientId,
                    'allowed_user_fields' => ['email'],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['integration.client_id']);
    }

    /**
     * Test 6: Nullable client_id behavior remains supported across multiple integrations.
     */
    public function test_nullable_client_id_is_supported_for_multiple_integrations(): void
    {
        $projectX = Project::create([
            'code' => 'proj_null_x',
            'name' => 'Project Null X',
            'base_url' => 'http://null-x.internal',
        ]);

        $projectY = Project::create([
            'code' => 'proj_null_y',
            'name' => 'Project Null Y',
            'base_url' => 'http://null-y.internal',
        ]);

        $integrationX = ProjectIntegration::create([
            'project_id' => $projectX->id,
            'api_base_url' => 'http://null-x.internal/api',
            'client_id' => null,
            'allowed_user_fields' => ['email'],
        ]);

        $integrationY = ProjectIntegration::create([
            'project_id' => $projectY->id,
            'api_base_url' => 'http://null-y.internal/api',
            'client_id' => null,
            'allowed_user_fields' => ['email'],
        ]);

        $this->assertDatabaseHas('project_integrations', ['id' => $integrationX->id, 'client_id' => null]);
        $this->assertDatabaseHas('project_integrations', ['id' => $integrationY->id, 'client_id' => null]);
    }

    /**
     * Test 7: One Project still cannot have multiple ProjectIntegration records (project_id UNIQUE preserved).
     */
    public function test_one_project_cannot_have_multiple_integrations(): void
    {
        $project = Project::create([
            'code' => 'proj_single_integ',
            'name' => 'Single Integration Project',
            'base_url' => 'http://single.internal',
        ]);

        ProjectIntegration::create([
            'project_id' => $project->id,
            'api_base_url' => 'http://single.internal/api/first',
            'client_id' => 'client_unique_first',
            'allowed_user_fields' => ['email'],
        ]);

        $this->expectException(QueryException::class);

        // Attempting to create a second integration for the same project_id must fail
        ProjectIntegration::create([
            'project_id' => $project->id,
            'api_base_url' => 'http://single.internal/api/second',
            'client_id' => 'client_unique_second',
            'allowed_user_fields' => ['email'],
        ]);
    }

    /**
     * Test 8: SSO client lookup still resolves the correct ProjectIntegration using unique client_id.
     */
    public function test_sso_client_lookup_resolves_correct_project_integration(): void
    {
        $centrix = Project::where('code', 'centrix')->firstOrFail();
        $centrixClientId = $centrix->integration->client_id;
        $centrixSecret = 'centrix-test-secret-value-123';
        $centrix->integration->update(['client_secret' => $centrixSecret]);

        $employee = Employee::create([
            'employee_code' => 'EMP_UNIQ_SSO',
            'f_name' => 'Uniq',
            'l_name' => 'Sso',
            'full_name' => 'Uniq Sso',
            'name_with_initials' => 'U. Sso',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '123456789V',
            'date_of_birth' => '1990-01-01',
            'email' => 'uniq.sso@example.com',
            'phone' => '+94771234567',
            'address_line_1' => 'No 1, Colombo',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94771234567',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'start_date' => '2026-01-01',
        ]);

        $user = User::create([
            'employee_code' => $employee->employee_code,
            'username' => 'uniq_sso_user',
            'name' => 'Uniq Sso',
            'email' => 'uniq.sso@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $role = ProjectRole::firstOrCreate(
            ['project_id' => $centrix->id, 'external_role_id' => 'EXT_ROLE_1'],
            ['code' => 'operator', 'name' => 'Operator', 'is_active' => true]
        );

        $access = UserProjectAccess::create([
            'user_id' => $user->id,
            'project_id' => $centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'assigned_at' => now(),
        ]);
        $access->roles()->sync([
            $role->id => [
                'external_role_id' => 'EXT_ROLE_1',
                'assigned_at' => now(),
            ],
        ]);

        $code = Str::random(40);
        SsoAuthCode::create([
            'code_hash' => hash('sha256', $code),
            'user_id' => $user->id,
            'project_id' => $centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
            'expires_at' => now()->addSeconds(60),
        ]);

        $response = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'client_id' => $centrixClientId,
            'client_secret' => $centrixSecret,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token_type', 'Bearer');
        $response->assertJsonPath('user.email', $user->email);
    }

    /**
     * Test 9: Logout telemetry functions cleanly with unique client_id lookup.
     */
    public function test_logout_telemetry_functions_cleanly(): void
    {
        $centrix = Project::where('code', 'centrix')->firstOrFail();
        $centrixClientId = $centrix->integration->client_id;
        $centrixSecret = 'centrix-telemetry-secret-999';
        $centrix->integration->update(['client_secret' => $centrixSecret]);

        $response = $this->postJson('/api/v1/sso/logout-telemetry', [
            'client_id' => $centrixClientId,
            'client_secret' => $centrixSecret,
            'external_ref' => 'REF_SSO_SESSION_123',
            'session_id' => 'sess_abc',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'acknowledged');
    }
}
