<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\ProjectIntegration;
use App\Services\Integration\ProjectApiKeyService;
use App\Services\Integration\ProjectOnboardingService;
use Database\Seeders\ProjectRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProjectOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected ProjectOnboardingService $onboardingService;
    protected ProjectApiKeyService $apiKeyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->onboardingService = app(ProjectOnboardingService::class);
        $this->apiKeyService = app(ProjectApiKeyService::class);

        // Configure test credentials for credential-backed projects
        Config::set('services.centrix.frontend_url', 'http://localhost:3001');
        Config::set('services.centrix.api_url', 'http://localhost:8002/api/giam/integration');
        Config::set('services.centrix.client_id', 'test_centrix_client');
        Config::set('services.centrix.client_secret', 'test_centrix_secret');

        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');

        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');
    }

    public function test_onboarding_single_project_persists_only_that_project_and_its_integration(): void
    {
        $project = $this->onboardingService->onboard('credix');

        $this->assertEquals('credix', $project->code);
        $this->assertEquals('CrediX', $project->name);
        $this->assertEquals('active', $project->status);

        // Assert exactly 1 project and 1 integration exist in database
        $this->assertEquals(1, Project::count());
        $this->assertEquals(1, ProjectIntegration::count());

        $integration = $project->integration;
        $this->assertNotNull($integration);
        $this->assertEquals('api_key', $integration->auth_method);
        $this->assertNull($integration->client_id);
        $this->assertFalse($integration->sync_enabled);
        $this->assertFalse($integration->sso_enabled);
        $this->assertEquals(['employees:read'], $integration->allowed_resources);

        $expectedEmployeeFields = [
            'employee_code',
            'full_name',
            'id_type',
            'id_number',
            'phone_primary',
        ];
        $this->assertEquals($expectedEmployeeFields, $integration->allowed_user_fields);
        $this->assertEquals($expectedEmployeeFields, $integration->allowed_resource_fields['employees']);

        // Assert other projects are NOT created
        $this->assertNull(Project::where('code', 'stockly')->first());
        $this->assertNull(Project::where('code', 'centrix')->first());
        $this->assertNull(Project::where('code', 'hrms')->first());
        $this->assertNull(Project::where('code', 'payroll')->first());
    }

    public function test_onboarding_twice_is_idempotent(): void
    {
        $firstRun = $this->onboardingService->onboard('credix');
        $this->assertEquals(1, Project::count());
        $this->assertEquals(1, ProjectIntegration::count());

        $secondRun = $this->onboardingService->onboard('credix');
        $this->assertEquals(1, Project::count());
        $this->assertEquals(1, ProjectIntegration::count());

        $this->assertEquals($firstRun->id, $secondRun->id);
        $this->assertEquals($firstRun->integration->id, $secondRun->integration->id);
    }

    public function test_onboarding_credix_does_not_mutate_existing_stockly_or_centrix(): void
    {
        $centrix = $this->onboardingService->onboard('centrix');
        $stockly = $this->onboardingService->onboard('stockly');

        $centrixSnapshot = $centrix->fresh(['integration'])->toArray();
        $stocklySnapshot = $stockly->fresh(['integration'])->toArray();

        // Onboard CrediX
        $credix = $this->onboardingService->onboard('credix');
        $this->assertNotNull($credix);

        // Verify Centrix state is completely unchanged
        $centrixAfter = Project::where('code', 'centrix')->with('integration')->first()->toArray();
        $this->assertEquals($centrixSnapshot, $centrixAfter);

        // Verify Stockly state is completely unchanged
        $stocklyAfter = Project::where('code', 'stockly')->with('integration')->first()->toArray();
        $this->assertEquals($stocklySnapshot, $stocklyAfter);
    }

    public function test_onboarding_stockly_does_not_mutate_existing_credix_or_centrix(): void
    {
        $centrix = $this->onboardingService->onboard('centrix');
        $credix = $this->onboardingService->onboard('credix');

        $centrixSnapshot = $centrix->fresh(['integration'])->toArray();
        $credixSnapshot = $credix->fresh(['integration'])->toArray();

        // Onboard Stockly
        $stockly = $this->onboardingService->onboard('stockly');
        $this->assertNotNull($stockly);

        // Verify Centrix and CrediX are unchanged
        $this->assertEquals($centrixSnapshot, Project::where('code', 'centrix')->with('integration')->first()->toArray());
        $this->assertEquals($credixSnapshot, Project::where('code', 'credix')->with('integration')->first()->toArray());
    }

    public function test_unknown_project_code_fails_safely(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or unapproved project code: [non_existent_project]');

        $this->onboardingService->onboard('non_existent_project');
    }

    public function test_cli_command_fails_safely_on_unknown_project(): void
    {
        $exitCode = Artisan::call('giam:onboard-project', ['code' => 'invalid_code']);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Unknown or unapproved project code', Artisan::output());
    }

    public function test_missing_required_credentials_fails_safely_and_rolls_back_transaction(): void
    {
        // Clear required credential for HRMS
        Config::set('services.hrms.client_id', null);

        try {
            $this->onboardingService->onboard('hrms');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Missing required integration credential: [HRMS_CLIENT_ID]', $e->getMessage());
        }

        // Assert transaction rollback left no partial state
        $this->assertDatabaseMissing('projects', ['code' => 'hrms']);
        $this->assertEquals(0, ProjectIntegration::count());
    }

    public function test_cli_command_reports_error_when_required_credential_missing(): void
    {
        Config::set('services.payroll.client_secret', null);

        $exitCode = Artisan::call('giam:onboard-project', ['code' => 'payroll']);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Missing required integration credential: [PAYROLL_CLIENT_SECRET]', Artisan::output());
        $this->assertDatabaseMissing('projects', ['code' => 'payroll']);
    }

    public function test_existing_project_api_keys_remain_untouched_during_re_onboarding(): void
    {
        $project = $this->onboardingService->onboard('credix');

        $keyResult = $this->apiKeyService->generateKey(
            project: $project,
            name: 'Initial CrediX Key'
        );
        $apiKey = $keyResult['api_key'];
        $plainTextKey = $keyResult['plain_text_key'];

        $this->assertDatabaseHas('project_api_keys', [
            'id' => $apiKey->id,
            'project_id' => $project->id,
            'key_id' => $apiKey->key_id,
            'revoked_at' => null,
        ]);

        // Re-onboard CrediX multiple times
        $this->onboardingService->onboard('credix');
        $this->onboardingService->onboard('credix');

        // Verify API key record is completely intact and active
        $keyAfter = ProjectApiKey::find($apiKey->id);
        $this->assertNotNull($keyAfter);
        $this->assertNull($keyAfter->revoked_at);
        $this->assertEquals($apiKey->key_id, $keyAfter->key_id);
        $this->assertEquals($apiKey->key_hash, $keyAfter->key_hash);

        // Verify key still validates successfully
        $validatedKey = $this->apiKeyService->validateToken($plainTextKey);
        $this->assertNotNull($validatedKey);
        $this->assertEquals($project->id, $validatedKey->project_id);
    }

    public function test_seeder_and_cli_consume_same_canonical_definitions_and_service(): void
    {
        // 1. Run seeder (used for fresh environment / CI bootstrap)
        $seeder = new ProjectRegistrySeeder($this->onboardingService);
        $seeder->run();

        $this->assertEquals(5, Project::count());
        $this->assertEquals(5, ProjectIntegration::count());

        $codes = Project::pluck('code')->sort()->values()->toArray();
        $this->assertEquals(['centrix', 'credix', 'hrms', 'payroll', 'stockly'], $codes);

        // 2. Run CLI command for CrediX (normal operational single-project onboarding)
        $exitCode = Artisan::call('giam:onboard-project', ['code' => 'credix']);
        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('GIAM PROJECT ONBOARDED SUCCESSFULLY', $output);
        $this->assertStringContainsString('Project Code:   credix', $output);
        $this->assertStringContainsString('Auth Method:    api_key', $output);

        // Secret values must never appear in command output
        $this->assertStringNotContainsString('client_secret', $output);
        $this->assertStringNotContainsString('test_centrix_secret', $output);
    }

    public function test_generic_capability_driven_onboarding_with_synthetic_project_definition(): void
    {
        // Dynamically register an approved synthetic project in config
        Config::set('projects.definitions.synthetic_lending', [
            'name' => 'Synthetic Lending System',
            'description' => 'Dynamic capability testing',
            'base_url' => 'https://lending.example.com',
            'icon_url' => '/icons/synthetic.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url' => 'https://lending.example.com/api',
                'auth_method' => 'api_key',
                'client_id' => null,
                'allowed_user_fields' => ['employee_code', 'full_name'],
                'allowed_resources' => ['loans:read', 'customers:read'],
                'allowed_resource_fields' => [
                    'loans' => ['loan_id', 'amount', 'status'],
                ],
                'sync_enabled' => false,
                'sso_enabled' => false,
                'status' => 'healthy',
            ],
        ]);

        // Onboard the synthetic project using the generic service
        $project = $this->onboardingService->onboard('synthetic_lending');

        $this->assertEquals('synthetic_lending', $project->code);
        $this->assertEquals('Synthetic Lending System', $project->name);
        $this->assertEquals('https://lending.example.com', $project->base_url);

        $integration = $project->integration;
        $this->assertNotNull($integration);
        $this->assertEquals('api_key', $integration->auth_method);
        $this->assertEquals(['loans:read', 'customers:read'], $integration->allowed_resources);
        $this->assertEquals(['loan_id', 'amount', 'status'], $integration->allowed_resource_fields['loans']);

        // This proves that ProjectOnboardingService is completely generic and capability-driven
        // without requiring any project-specific branching or code changes.
    }
}
