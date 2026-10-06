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
use Database\Seeders\ProjectRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CentrixSsoCallbackFixTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Employee $employee;
    protected Project $centrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Run ProjectRegistrySeeder to assert seeder behavior
        $this->seed(ProjectRegistrySeeder::class);

        $this->centrix = Project::with('integration')->where('code', 'centrix')->firstOrFail();

        $this->employee = Employee::create([
            'employee_code' => 'EMP_TEST_MAYA',
            'f_name' => 'Maya',
            'l_name' => 'Fernando',
            'full_name' => 'Maya Fernando',
            'name_with_initials' => 'M. Fernando',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '905544332V',
            'date_of_birth' => '1990-05-15',
            'email' => 'maya.test@example.com',
            'phone' => '+94112233445',
            'phone_primary' => '+94771234567',
            'address_line_1' => 'Logistics Way',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'is_active' => true,
        ]);

        $this->user = User::create([
            'employee_code' => $this->employee->employee_code,
            'name' => 'Maya Fernando',
            'username' => 'maya_sso_test',
            'email' => 'maya.test@example.com',
            'password' => Hash::make('SecretPass123!'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'must_change_password' => false,
        ]);

        // Assign ACTIVE access to Centrix
        UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->user->id,
        ]);
    }

    public function test_centrix_project_configuration_resolves_development_frontend_to_port_3001(): void
    {
        $centrixConfig = config('services.centrix.frontend_url');
        $this->assertEquals('http://localhost:3001', $centrixConfig);
    }

    public function test_project_registry_seeder_persists_port_3001_and_canonical_redirect_uri(): void
    {
        $centrix = Project::with('integration')->where('code', 'centrix')->first();

        $this->assertNotNull($centrix);
        $this->assertEquals('http://localhost:3001', $centrix->base_url);

        $redirectUris = $centrix->integration->redirect_uris;
        $this->assertIsArray($redirectUris);
        $this->assertNotEmpty($redirectUris);

        // Canonical element [0] must be http://localhost:3001/sso/callback
        $this->assertEquals('http://localhost:3001/sso/callback', $redirectUris[0]);

        // Must NOT contain old ports 3002 or 3000
        $this->assertNotContains('http://localhost:3002/sso/callback', $redirectUris);
        $this->assertNotContains('http://localhost:3000/sso/callback', $redirectUris);
        $this->assertNotContains('http://127.0.0.1:3002/sso/callback', $redirectUris);
        $this->assertNotContains('http://127.0.0.1:3000/sso/callback', $redirectUris);
    }

    public function test_auth_my_projects_returns_centrix_launch_url_on_port_3001_for_active_access(): void
    {
        $response = $this->actingAs($this->user, 'web')->getJson('/api/v1/auth/my-projects');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        $projects = $response->json('data');
        $centrixEntry = collect($projects)->firstWhere('code', 'centrix');

        $this->assertNotNull($centrixEntry);
        $this->assertEquals('ACTIVE', $centrixEntry['access_status']);
        $this->assertTrue($centrixEntry['sso_enabled']);
        $this->assertEquals('http://localhost:3001/sso/callback', $centrixEntry['launch_url']);
    }

    public function test_sso_authorize_accepts_registered_centrix_callback_on_port_3001(): void
    {
        $response = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'authorization_code',
                'redirect_url',
                'expires_in',
            ],
        ]);

        $redirectUrl = $response->json('data.redirect_url');
        $this->assertStringStartsWith('http://localhost:3001/sso/callback?code=', $redirectUrl);
    }

    public function test_sso_authorize_rejects_unregistered_callback_for_centrix(): void
    {
        $response = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3999/sso/callback',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['redirect_uri']);
    }

    public function test_sso_authorize_rejects_arbitrary_external_redirect_uri(): void
    {
        $response = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'https://malicious-attacker.com/steal-code',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['redirect_uri']);
    }

    public function test_sso_code_is_short_lived_single_use_project_and_user_bound_and_hashed(): void
    {
        $response = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);

        $response->assertStatus(200);
        $rawCode = $response->json('data.authorization_code');
        $this->assertNotEmpty($rawCode);

        // Verify code is stored as SHA-256 hash, never plaintext
        $codeHash = hash('sha256', $rawCode);
        $this->assertDatabaseHas('sso_auth_codes', [
            'code_hash' => $codeHash,
            'user_id' => $this->user->id,
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);

        $authCodeRecord = SsoAuthCode::where('code_hash', $codeHash)->firstOrFail();
        $this->assertNull($authCodeRecord->used_at);
        $this->assertTrue($authCodeRecord->expires_at->isFuture());
    }

    public function test_unassigned_or_inactive_project_cannot_authorize_sso(): void
    {
        // Change access to SUSPENDED
        UserProjectAccess::where('user_id', $this->user->id)
            ->where('project_id', $this->centrix->id)
            ->update(['status' => 'SUSPENDED']);

        $response = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);

        $response->assertStatus(403);
    }
}

