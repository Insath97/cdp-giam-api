<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\SsoAuthCode;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProjectRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentrixIntegrationSecretManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $adminUser;
    protected Employee $employee;
    protected Project $centrix;
    protected string $testClientSecret;
    protected string $testClientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testClientSecret = 'testing_centrix_secret_key';
        $this->testClientId = 'giam_centrix_client';

        Config::set('services.centrix.frontend_url', 'http://localhost:3001');
        Config::set('services.centrix.api_url', 'http://localhost:8002/api/giam/integration');
        Config::set('services.centrix.client_id', $this->testClientId);
        Config::set('services.centrix.client_secret', $this->testClientSecret);

        $this->seed(DatabaseSeeder::class);

        $this->centrix = Project::with('integration')->where('code', 'centrix')->firstOrFail();

        $this->employee = Employee::create([
            'employee_code' => 'EMP_SEC_TEST',
            'f_name' => 'Sec',
            'l_name' => 'Tester',
            'full_name' => 'Sec Tester',
            'name_with_initials' => 'S. Tester',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '912345678V',
            'date_of_birth' => '1991-01-01',
            'email' => 'sec.tester@example.com',
            'phone' => '+94112233445',
            'phone_primary' => '+94771234567',
            'address_line_1' => 'Test Way',
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
            'name' => 'Sec Tester',
            'username' => 'sec_tester',
            'email' => 'sec.tester@example.com',
            'password' => Hash::make('Password123!'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->user->id,
        ]);

        $this->adminUser = User::where('username', 'user01')->firstOrFail();
    }

    /**
     * 1. Centrix secret comes from Laravel config.
     */
    public function test_centrix_secret_comes_from_laravel_config(): void
    {
        $customSecret = 'custom_synthetic_test_secret_' . Str::random(16);
        Config::set('services.centrix.client_secret', $customSecret);

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();

        $integration = ProjectIntegration::where('project_id', $this->centrix->id)->firstOrFail();
        $this->assertEquals($customSecret, $integration->getDecryptedClientSecret());
    }

    /**
     * 2. Missing client_secret fails safely.
     */
    public function test_missing_client_secret_fails_safely(): void
    {
        Config::set('services.centrix.client_secret', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required integration credential: [CENTRIX_CLIENT_SECRET]');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
    }

    /**
     * 3. Blank/whitespace client_secret fails safely.
     */
    public function test_blank_client_secret_fails_safely(): void
    {
        Config::set('services.centrix.client_secret', '   ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required integration credential: [CENTRIX_CLIENT_SECRET]');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
    }

    /**
     * 4. Missing or blank client_id fails safely.
     */
    public function test_missing_client_id_fails_safely(): void
    {
        Config::set('services.centrix.client_id', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required integration credential: [CENTRIX_CLIENT_ID]');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
    }

    public function test_blank_client_id_fails_safely(): void
    {
        Config::set('services.centrix.client_id', '  ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required integration credential: [CENTRIX_CLIENT_ID]');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
    }

    /**
     * 5. No fallback literal is used.
     */
    public function test_no_fallback_literal_is_used_when_secret_config_is_missing(): void
    {
        Config::set('services.centrix.client_secret', null);

        try {
            $seeder = new ProjectRegistrySeeder();
            $seeder->run();
            $this->fail('Expected RuntimeException was not thrown when client_secret was absent.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CENTRIX_CLIENT_SECRET', $e->getMessage());
            $this->assertStringNotContainsString('testing_centrix_secret_key', $e->getMessage());
        }
    }

    /**
     * 6. Existing DB secret is NOT silently reused when config is missing.
     */
    public function test_existing_db_secret_is_not_silently_reused_when_config_is_missing(): void
    {
        $this->assertNotNull($this->centrix->integration->getDecryptedClientSecret());

        Config::set('services.centrix.client_secret', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required integration credential: [CENTRIX_CLIENT_SECRET]');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
    }

    /**
     * 7 & 8. ProjectRegistrySeeder remains idempotent and does not duplicate rows.
     */
    public function test_project_registry_seeder_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $initialProjectsCount = Project::where('code', 'centrix')->count();
        $initialIntegrationsCount = ProjectIntegration::where('project_id', $this->centrix->id)->count();

        $this->assertEquals(1, $initialProjectsCount);
        $this->assertEquals(1, $initialIntegrationsCount);

        // Run seeder multiple times
        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
        $seeder->run();

        $this->assertEquals(1, Project::where('code', 'centrix')->count());
        $this->assertEquals(1, ProjectIntegration::where('project_id', $this->centrix->id)->count());

        $reloaded = Project::with('integration')->where('code', 'centrix')->first();
        $this->assertEquals($this->centrix->id, $reloaded->id);
        $this->assertEquals($this->testClientId, $reloaded->integration->client_id);
        $this->assertEquals($this->testClientSecret, $reloaded->integration->getDecryptedClientSecret());
    }

    /**
     * 9. Configured secret is persisted through existing encrypted storage.
     */
    public function test_configured_secret_is_persisted_through_encrypted_storage(): void
    {
        $rawEncryptedSecret = DB::table('project_integrations')
            ->where('project_id', $this->centrix->id)
            ->value('encrypted_client_secret');

        $this->assertNotNull($rawEncryptedSecret);
        $this->assertNotEquals($this->testClientSecret, $rawEncryptedSecret);
        $this->assertStringNotContainsString($this->testClientSecret, $rawEncryptedSecret);

        $integration = ProjectIntegration::where('project_id', $this->centrix->id)->firstOrFail();
        $this->assertEquals($this->testClientSecret, $integration->getDecryptedClientSecret());
    }

    /**
     * 10. SSO exchange rejects mismatched client secret.
     */
    public function test_sso_exchange_rejects_mismatched_client_secret(): void
    {
        $authResponse = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);
        $authResponse->assertStatus(200);
        $code = $authResponse->json('data.authorization_code');

        $tokenResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'client_id' => $this->testClientId,
            'client_secret' => 'WRONG_SECRET_VALUE',
        ]);

        $tokenResponse->assertStatus(401);
        $tokenResponse->assertJsonFragment(['message' => 'Invalid client credentials.']);
    }

    /**
     * 11. SSO exchange accepts matching configured secret.
     */
    public function test_sso_exchange_accepts_matching_configured_secret(): void
    {
        $authResponse = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);
        $authResponse->assertStatus(200);
        $code = $authResponse->json('data.authorization_code');

        $tokenResponse = $this->postJson('/api/v1/sso/token', [
            'code' => $code,
            'client_id' => $this->testClientId,
            'client_secret' => $this->testClientSecret,
        ]);

        $tokenResponse->assertStatus(200);
        $tokenResponse->assertJsonStructure([
            'token_type',
            'expires_in',
            'user' => [
                'externalRef',
                'username',
                'email',
            ],
        ]);
        $this->assertEquals('Bearer', $tokenResponse->json('token_type'));
        $this->assertEquals($this->user->username, $tokenResponse->json('user.username'));
    }

    /**
     * 12. Secret value is not present in existing API surfaces or model serialization.
     */
    public function test_secret_value_is_never_present_in_existing_api_surfaces_or_model_serialization(): void
    {
        // 12a: Project integration inspection API
        $respIntegration = $this->actingAs($this->adminUser, 'web')
            ->getJson("/api/v1/projects/{$this->centrix->id}/integration");
        $respIntegration->assertStatus(200);
        $integrationData = $respIntegration->json('data');
        $this->assertArrayNotHasKey('client_secret', $integrationData);
        $this->assertArrayNotHasKey('encrypted_client_secret', $integrationData);
        $this->assertStringNotContainsString($this->testClientSecret, $respIntegration->getContent());
        $respIntegration->assertJsonFragment(['has_client_secret' => true]);

        // 12b: Projects list API
        $respProjects = $this->actingAs($this->adminUser, 'web')
            ->getJson('/api/v1/projects');
        $respProjects->assertStatus(200);
        $this->assertStringNotContainsString($this->testClientSecret, $respProjects->getContent());
        foreach ($respProjects->json('data') as $projectItem) {
            $this->assertArrayNotHasKey('client_secret', $projectItem);
            if (isset($projectItem['integration'])) {
                $this->assertArrayNotHasKey('client_secret', $projectItem['integration']);
                $this->assertArrayNotHasKey('encrypted_client_secret', $projectItem['integration']);
            }
        }

        // 12c: Single project detail API
        $respProject = $this->actingAs($this->adminUser, 'web')
            ->getJson("/api/v1/projects/{$this->centrix->id}");
        $respProject->assertStatus(200);
        $this->assertStringNotContainsString($this->testClientSecret, $respProject->getContent());
        $this->assertArrayNotHasKey('client_secret', $respProject->json('data'));

        // 12d: User launchpad API
        $respLaunchpad = $this->actingAs($this->user, 'web')
            ->getJson('/api/v1/auth/my-projects');
        $respLaunchpad->assertStatus(200);
        $this->assertStringNotContainsString($this->testClientSecret, $respLaunchpad->getContent());
        foreach ($respLaunchpad->json('data') as $launchpadItem) {
            $this->assertArrayNotHasKey('client_secret', $launchpadItem);
            $this->assertArrayNotHasKey('encrypted_client_secret', $launchpadItem);
        }

        // 12e: Model serialization (toArray / toJson)
        $integrationModel = ProjectIntegration::where('project_id', $this->centrix->id)->firstOrFail();
        $arrayData = $integrationModel->toArray();
        $jsonData = $integrationModel->toJson();

        $this->assertArrayNotHasKey('client_secret', $arrayData);
        $this->assertArrayNotHasKey('encrypted_client_secret', $arrayData);
        $this->assertStringNotContainsString('client_secret', $jsonData);
        $this->assertStringNotContainsString($this->testClientSecret, $jsonData);
    }

    /**
     * 13. Existing callback URLs remain on port 3002.
     */
    public function test_centrix_callback_urls_remain_on_port_3001(): void
    {
        $redirectUris = $this->centrix->integration->redirect_uris;

        $this->assertContains('http://localhost:3001/sso/callback', $redirectUris);
        $this->assertContains('http://127.0.0.1:3001/sso/callback', $redirectUris);
        $this->assertNotContains('http://localhost:3002/sso/callback', $redirectUris);
        $this->assertNotContains('http://localhost:3000/sso/callback', $redirectUris);
    }

    /**
     * 14. SSO single-use auth code security controls preserved.
     */
    public function test_sso_single_use_auth_code_security_controls_preserved(): void
    {
        $authResponse = $this->actingAs($this->user, 'web')->postJson('/api/v1/sso/authorize', [
            'project_id' => $this->centrix->id,
            'redirect_uri' => 'http://localhost:3001/sso/callback',
        ]);
        $rawCode = $authResponse->json('data.authorization_code');
        $codeHash = hash('sha256', $rawCode);

        // Assert code is hashed
        $this->assertDatabaseHas('sso_auth_codes', [
            'code_hash' => $codeHash,
            'project_id' => $this->centrix->id,
            'used_at' => null,
        ]);

        // First redemption succeeds
        $firstExchange = $this->postJson('/api/v1/sso/token', [
            'code' => $rawCode,
            'client_id' => $this->testClientId,
            'client_secret' => $this->testClientSecret,
        ]);
        $firstExchange->assertStatus(200);

        // Code is marked used
        $authCodeRecord = SsoAuthCode::where('code_hash', $codeHash)->firstOrFail();
        $this->assertNotNull($authCodeRecord->used_at);

        // Second redemption fails (replay prevention)
        $replayExchange = $this->postJson('/api/v1/sso/token', [
            'code' => $rawCode,
            'client_id' => $this->testClientId,
            'client_secret' => $this->testClientSecret,
        ]);
        $replayExchange->assertStatus(400);
        $replayExchange->assertSee('Authorization code has already been redeemed.');
    }

    // =========================================================================
    // HRMS & Payroll Integration Secret Management Tests
    // =========================================================================

    /**
     * 1. HRMS seeder fails fast when HRMS client secret config is missing.
     */
    public function test_hrms_seeder_fails_fast_when_client_secret_missing(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', null);

        $seeder = new ProjectRegistrySeeder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[HRMS_CLIENT_SECRET]');

        $seeder->run();
    }

    /**
     * 2. HRMS seeder fails fast when HRMS client ID config is missing.
     */
    public function test_hrms_seeder_fails_fast_when_client_id_missing(): void
    {
        Config::set('services.hrms.client_id', null);
        Config::set('services.hrms.client_secret', 'test_hrms_secret');

        $seeder = new ProjectRegistrySeeder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[HRMS_CLIENT_ID]');

        $seeder->run();
    }

    /**
     * 3. Payroll seeder fails fast when Payroll client secret config is missing.
     */
    public function test_payroll_seeder_fails_fast_when_client_secret_missing(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', null);

        $seeder = new ProjectRegistrySeeder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[PAYROLL_CLIENT_SECRET]');

        $seeder->run();
    }

    /**
     * 4. Payroll seeder fails fast when Payroll client ID config is missing.
     */
    public function test_payroll_seeder_fails_fast_when_client_id_missing(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', null);
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $seeder = new ProjectRegistrySeeder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[PAYROLL_CLIENT_ID]');

        $seeder->run();
    }

    /**
     * 5. HRMS configured secret is encrypted at rest and round-trips correctly.
     */
    public function test_hrms_configured_secret_is_encrypted_at_rest(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();

        $hrms = Project::with('integration')->where('code', 'hrms')->firstOrFail();

        // Raw DB column must NOT be the plaintext value
        $rawInDb = ProjectIntegration::where('project_id', $hrms->id)->value('encrypted_client_secret');
        $this->assertNotEquals('test_hrms_secret', $rawInDb);
        $this->assertNotEmpty($rawInDb);

        // Decrypted value must match what was configured
        $this->assertEquals('test_hrms_secret', $hrms->integration->getDecryptedClientSecret());
    }

    /**
     * 6. Payroll configured secret is encrypted at rest and round-trips correctly.
     */
    public function test_payroll_configured_secret_is_encrypted_at_rest(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();

        $payroll = Project::with('integration')->where('code', 'payroll')->firstOrFail();

        $rawInDb = ProjectIntegration::where('project_id', $payroll->id)->value('encrypted_client_secret');
        $this->assertNotEquals('test_payroll_secret', $rawInDb);
        $this->assertNotEmpty($rawInDb);

        $this->assertEquals('test_payroll_secret', $payroll->integration->getDecryptedClientSecret());
    }

    /**
     * 7. HRMS and Payroll secrets are absent from API serialization.
     */
    public function test_hrms_and_payroll_secrets_absent_from_api_serialization(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();

        $this->actingAs($this->adminUser, 'web');

        $response = $this->getJson('/api/v1/projects');
        $response->assertStatus(200);
        $body = $response->getContent();

        $this->assertStringNotContainsString('test_hrms_secret', $body);
        $this->assertStringNotContainsString('test_payroll_secret', $body);
        $this->assertStringNotContainsString('encrypted_client_secret', $body);
    }

    /**
     * 8. ProjectRegistrySeeder with HRMS and Payroll config is idempotent.
     */
    public function test_hrms_payroll_seeder_is_idempotent(): void
    {
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
        $seeder->run(); // Second run must not throw or duplicate

        $this->assertEquals(1, Project::where('code', 'hrms')->count());
        $this->assertEquals(1, Project::where('code', 'payroll')->count());
        $this->assertEquals(1, ProjectIntegration::whereHas('project', fn ($q) => $q->where('code', 'hrms'))->count());
        $this->assertEquals(1, ProjectIntegration::whereHas('project', fn ($q) => $q->where('code', 'payroll'))->count());
    }
}
