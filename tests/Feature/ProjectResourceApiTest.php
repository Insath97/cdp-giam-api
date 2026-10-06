<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Services\Integration\ProjectApiKeyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectResourceApiTest extends TestCase
{
    use RefreshDatabase;

    protected ProjectApiKeyService $apiKeyService;
    protected Project $testProject;
    protected ProjectIntegration $testIntegration;
    protected string $plainTextApiKey;
    protected ProjectApiKey $apiKeyRecord;
    protected Employee $employee1;
    protected Employee $employee2;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.centrix.frontend_url', 'http://localhost:3001');
        Config::set('services.centrix.api_url', 'http://localhost:8002/api/giam/integration');
        Config::set('services.centrix.client_id', 'test_centrix_client');
        Config::set('services.centrix.client_secret', 'test_centrix_secret');
        Config::set('services.hrms.client_id', 'test_hrms_client');
        Config::set('services.hrms.client_secret', 'test_hrms_secret');
        Config::set('services.payroll.client_id', 'test_payroll_client');
        Config::set('services.payroll.client_secret', 'test_payroll_secret');

        $this->seed(DatabaseSeeder::class);

        $this->apiKeyService = app(ProjectApiKeyService::class);
        $this->superAdmin = User::where('username', 'user01')->first();

        // Use Centrix project for testing inbound resource consumption
        $this->testProject = Project::where('code', 'centrix')->first();
        $this->testIntegration = $this->testProject->integration;

        // Configure approved resource scopes and fields
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => ['employee_code', 'full_name', 'email', 'department_code'],
            ],
        ]);

        // Issue test API key
        $keyResult = $this->apiKeyService->generateKey(
            project: $this->testProject,
            name: 'Test Project Key'
        );
        $this->apiKeyRecord = $keyResult['api_key'];
        $this->plainTextApiKey = $keyResult['plain_text_key'];

        // Retrieve existing seeded employee and create second for pagination
        $this->employee1 = Employee::where('employee_code', 'EMP1001')->first();

        $this->employee2 = Employee::create([
            'employee_code' => 'EMP1002',
            'f_name' => 'Jane',
            'l_name' => 'Smith',
            'full_name' => 'Jane Smith',
            'name_with_initials' => 'J. Smith',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '961234567V',
            'date_of_birth' => '1996-06-16',
            'email' => 'jane.smith@example.com',
            'phone' => '+94112345679',
            'phone_primary' => '+94771122334',
            'address_line_1' => '456 Second St',
            'city' => 'Colombo',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'branch_code' => 'BR01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'start_date' => '2026-06-01',
            'is_active' => true,
        ]);
    }

    public function test_missing_x_api_key_returns_401(): void
    {
        $response = $this->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Missing X-API-KEY header.',
            ]);
    }

    public function test_malformed_x_api_key_returns_401(): void
    {
        $response = $this->withHeaders([
            'X-API-KEY' => 'not-a-valid-giam-key',
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key format.',
            ]);
    }

    public function test_unknown_key_id_returns_401(): void
    {
        $fakeKey = 'giam_0123456789abcdef_1234567890123456789012345678901234567890';

        $response = $this->withHeaders([
            'X-API-KEY' => $fakeKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid or unverified API key.',
            ]);
    }

    public function test_wrong_secret_returns_401(): void
    {
        $wrongSecretKey = 'giam_' . $this->apiKeyRecord->key_id . '_wrongsecret01234567890123456789012345678901';

        $response = $this->withHeaders([
            'X-API-KEY' => $wrongSecretKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Invalid or unverified API key.',
            ]);
    }

    public function test_valid_key_authenticates_project_and_updates_last_used(): void
    {
        $this->assertNull($this->apiKeyRecord->fresh()->last_used_at);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(200);
        $this->assertNotNull($this->apiKeyRecord->fresh()->last_used_at);
    }

    public function test_revoked_key_is_denied(): void
    {
        $this->apiKeyService->revokeKey($this->apiKeyRecord);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'API key has been revoked.',
            ]);
    }

    public function test_expired_key_is_denied(): void
    {
        $this->apiKeyRecord->update([
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'API key has expired.',
            ]);
    }

    public function test_inactive_project_is_denied(): void
    {
        $this->testProject->update(['status' => 'disabled']);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Associated project is inactive or disabled.',
            ]);
    }

    public function test_missing_employees_read_scope_returns_403(): void
    {
        $this->testIntegration->update([
            'allowed_resources' => ['reports:read'], // missing employees:read
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => "Project is not authorized to access resource scope 'employees:read'.",
            ]);
    }

    public function test_employees_read_scope_is_allowed(): void
    {
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees');

        $response->assertStatus(200);
    }

    public function test_collection_pagination(): void
    {
        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees?per_page=1&page=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(1, $response->json('meta.per_page'));
    }

    public function test_employee_lookup_by_canonical_employee_code(): void
    {
        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'employee_code' => $this->employee1->employee_code,
                    'full_name' => $this->employee1->full_name,
                    'email' => $this->employee1->email,
                ],
            ]);
    }

    public function test_unknown_employee_code_returns_404(): void
    {
        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/employees/NONEXISTENT_CODE');

        $response->assertStatus(404)
            ->assertJson([
                'error' => 'Not Found',
                'message' => "Employee with code 'NONEXISTENT_CODE' not found.",
            ]);
    }

    public function test_allowed_fields_returned_and_unapproved_fields_excluded(): void
    {
        // Allowed: employee_code, full_name, email
        $this->testIntegration->update([
            'allowed_resource_fields' => [
                'employees' => ['employee_code', 'full_name', 'email'],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response->assertStatus(200);

        $item = $response->json('data');

        // Verify approved fields are present
        $this->assertArrayHasKey('employee_code', $item);
        $this->assertArrayHasKey('full_name', $item);
        $this->assertArrayHasKey('email', $item);

        // Verify sensitive fields are strictly excluded
        $this->assertArrayNotHasKey('id', $item);
        $this->assertArrayNotHasKey('id_number', $item); // NIC
        $this->assertArrayNotHasKey('date_of_birth', $item);
        $this->assertArrayNotHasKey('address_line_1', $item);
        $this->assertArrayNotHasKey('phone_primary', $item);
        $this->assertArrayNotHasKey('department_code', $item);
    }

    public function test_empty_or_missing_field_allowlist_exposes_nothing_sensitive(): void
    {
        // Deny by default: empty allowed fields
        $this->testIntegration->update([
            'allowed_resource_fields' => [
                'employees' => [],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));

        // Missing field configuration completely
        $this->testIntegration->update([
            'allowed_resource_fields' => null,
        ]);

        $response2 = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response2->assertStatus(200);
        $this->assertEmpty($response2->json('data'));
    }

    public function test_one_project_configuration_cannot_leak_to_another_project(): void
    {
        // Project A (Centrix): only employee_code
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => ['employee_code'],
            ],
        ]);

        // Project B (HRMS): employee_code and email
        $hrms = Project::where('code', 'hrms')->first();
        $hrms->integration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => ['employee_code', 'email'],
            ],
        ]);

        $hrmsKey = $this->apiKeyService->generateKey(
            project: $hrms,
            name: 'HRMS Key'
        )['plain_text_key'];

        // Request with Centrix key
        $centrixResp = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $centrixResp->assertStatus(200);
        $this->assertArrayHasKey('employee_code', $centrixResp->json('data'));
        $this->assertArrayNotHasKey('email', $centrixResp->json('data'));

        // Request with HRMS key
        $hrmsResp = $this->withHeaders([
            'X-API-KEY' => $hrmsKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $hrmsResp->assertStatus(200);
        $this->assertArrayHasKey('employee_code', $hrmsResp->json('data'));
        $this->assertArrayHasKey('email', $hrmsResp->json('data'));
    }

    public function test_api_key_plaintext_is_not_persisted_in_database(): void
    {
        $dbRecord = DB::table('project_api_keys')
            ->where('id', $this->apiKeyRecord->id)
            ->first();

        $this->assertNotNull($dbRecord);
        $this->assertNotNull($dbRecord->key_hash);

        // Neither the full plaintext token nor the secret is anywhere in the table
        $parsed = $this->apiKeyService->parseToken($this->plainTextApiKey);
        $this->assertStringNotContainsString($this->plainTextApiKey, json_encode($dbRecord));
        $this->assertStringNotContainsString($parsed['secret'], json_encode($dbRecord));
    }

    public function test_api_key_is_not_returned_by_ordinary_retrieval_endpoints(): void
    {
        $response = $this->actingAs($this->superAdmin, 'web')
            ->getJson("/api/v1/projects/{$this->testProject->id}/api-keys");

        $response->assertStatus(200);

        $keys = $response->json('data');
        $this->assertNotEmpty($keys);

        foreach ($keys as $k) {
            $this->assertArrayNotHasKey('plain_text_key', $k);
            $this->assertArrayNotHasKey('key_hash', $k);
            $this->assertArrayHasKey('key_id', $k);
            $this->assertArrayHasKey('name', $k);
        }
    }

    public function test_admin_can_issue_and_revoke_project_api_keys(): void
    {
        // 1. Create key via admin endpoint
        $createResp = $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/projects/{$this->testProject->id}/api-keys", [
                'name' => 'Secondary Test Key',
            ]);

        $createResp->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['id', 'project_id', 'name', 'key_id'],
                'plain_text_key',
            ]);

        $newKeyId = $createResp->json('data.id');
        $newPlainText = $createResp->json('plain_text_key');

        // Verify newly created key can authenticate
        $authResp = $this->withHeaders([
            'X-API-KEY' => $newPlainText,
        ])->getJson('/api/v1/integrations/resources/employees');
        $authResp->assertStatus(200);

        // 2. Revoke key via admin endpoint
        $revokeResp = $this->actingAs($this->superAdmin, 'web')
            ->postJson("/api/v1/projects/{$this->testProject->id}/api-keys/{$newKeyId}/revoke");

        $revokeResp->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'API key revoked successfully.',
            ]);

        // Verify revoked key can no longer authenticate
        $deniedResp = $this->withHeaders([
            'X-API-KEY' => $newPlainText,
        ])->getJson('/api/v1/integrations/resources/employees');
        $deniedResp->assertStatus(401);
    }

    public function test_audit_log_recorded_for_resource_access_without_leaking_key_or_pii(): void
    {
        $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $auditLog = DB::table('audit_logs')
            ->where('action', 'PROJECT_RESOURCE_ACCESSED')
            ->where('entity_id', $this->employee1->employee_code)
            ->latest('id')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($this->testProject->id, $auditLog->project_id);

        $metadata = json_decode($auditLog->metadata, true);
        $this->assertEquals('employees', $metadata['resource']);
        $this->assertEquals('employees:read', $metadata['scope']);
        $this->assertEquals($this->apiKeyRecord->key_id, $metadata['key_id']);

        // Check that plaintext key, secret, and sensitive PII are NOT in audit logs
        $rawLog = json_encode($auditLog);
        $this->assertStringNotContainsString($this->plainTextApiKey, $rawLog);
        $parsed = $this->apiKeyService->parseToken($this->plainTextApiKey);
        $this->assertStringNotContainsString($parsed['secret'], $rawLog);
        $this->assertStringNotContainsString($this->employee1->id_number, $rawLog);
    }

    public function test_employee_response_can_contain_approved_nested_organization_relationships(): void
    {
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => [
                    'employee_code',
                    'full_name',
                    'department',
                    'designation',
                    'branch',
                    'region',
                    'zone',
                    'province',
                ],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals('EMP1001', $data['employee_code']);
        $this->assertEquals('DEP01', $data['department']['code']);
        $this->assertEquals('Human Resources', $data['department']['name']);
        $this->assertEquals('DES01', $data['designation']['code']);
        $this->assertEquals('Senior Software Engineer', $data['designation']['name']);
        $this->assertEquals('BR01', $data['branch']['code']);
        $this->assertEquals('Head Office Colombo', $data['branch']['name']);
        $this->assertEquals('R01', $data['region']['code']);
        $this->assertEquals('Colombo Central Region', $data['region']['name']);
        $this->assertEquals('Z01', $data['zone']['code']);
        $this->assertEquals('Colombo Zone', $data['zone']['name']);
        $this->assertEquals('WP', $data['province']['code']);
        $this->assertEquals('Western Province', $data['province']['name']);
    }

    public function test_unapproved_relationships_are_strictly_absent_from_employee_response(): void
    {
        // Approve ONLY department, NOT designation, branch, region, zone, or province
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => ['employee_code', 'full_name', 'department'],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertArrayHasKey('department', $data);
        $this->assertArrayNotHasKey('designation', $data);
        $this->assertArrayNotHasKey('branch', $data);
        $this->assertArrayNotHasKey('region', $data);
        $this->assertArrayNotHasKey('zone', $data);
        $this->assertArrayNotHasKey('province', $data);
    }

    public function test_nested_representation_does_not_expose_database_ids_or_internal_columns(): void
    {
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => ['employee_code', 'department'],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $response->assertStatus(200);

        $dept = $response->json('data.department');

        $this->assertArrayHasKey('code', $dept);
        $this->assertArrayHasKey('name', $dept);
        $this->assertArrayNotHasKey('id', $dept);
        $this->assertArrayNotHasKey('created_at', $dept);
        $this->assertArrayNotHasKey('updated_at', $dept);
    }

    public function test_only_required_relationships_are_conditionally_eager_loaded(): void
    {
        $this->testIntegration->update([
            'allowed_resources' => ['employees:read'],
            'allowed_resource_fields' => [
                'employees' => ['employee_code', 'department'],
            ],
        ]);

        $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $auditLog = DB::table('audit_logs')
            ->where('action', 'PROJECT_RESOURCE_ACCESSED')
            ->where('entity_id', $this->employee1->employee_code)
            ->latest('id')
            ->first();

        $metadata = json_decode($auditLog->metadata, true);

        // Verify that ONLY 'department' was eager-loaded, preventing N+1 without loading unneeded models
        $this->assertEquals(['department'], $metadata['eager_loaded']);
        $this->assertNotContains('designation', $metadata['eager_loaded']);
        $this->assertNotContains('branch', $metadata['eager_loaded']);
    }

    public function test_reference_resources_access_and_scope_enforcement(): void
    {
        $resources = [
            'departments' => 'departments:read',
            'designations' => 'designations:read',
            'branches' => 'branches:read',
            'regions' => 'regions:read',
            'zones' => 'zones:read',
            'provinces' => 'provinces:read',
        ];

        foreach ($resources as $endpoint => $scope) {
            // 1. Without scope -> 403 Forbidden
            $this->testIntegration->update([
                'allowed_resources' => ['employees:read'], // scope is missing
            ]);

            $denied = $this->withHeaders([
                'X-API-KEY' => $this->plainTextApiKey,
            ])->getJson("/api/v1/integrations/resources/{$endpoint}");

            $denied->assertStatus(403)
                ->assertJson([
                    'error' => 'Forbidden',
                    'message' => "Project is not authorized to access resource scope '{$scope}'.",
                ]);

            // 2. With scope -> 200 OK
            $this->testIntegration->update([
                'allowed_resources' => ['employees:read', $scope],
                'allowed_resource_fields' => [
                    $endpoint => ['code', 'name'],
                ],
            ]);

            $allowed = $this->withHeaders([
                'X-API-KEY' => $this->plainTextApiKey,
            ])->getJson("/api/v1/integrations/resources/{$endpoint}");

            $allowed->assertStatus(200);
            $this->assertNotEmpty($allowed->json('data'));
        }
    }

    public function test_reference_resource_field_projection_and_deny_by_default(): void
    {
        // 1. Allowed fields returned, unapproved excluded
        $this->testIntegration->update([
            'allowed_resources' => ['departments:read'],
            'allowed_resource_fields' => [
                'departments' => ['code', 'name'],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/departments');

        $response->assertStatus(200);
        $firstItem = $response->json('data.0');

        $this->assertArrayHasKey('code', $firstItem);
        $this->assertArrayHasKey('name', $firstItem);
        $this->assertArrayNotHasKey('id', $firstItem);
        $this->assertArrayNotHasKey('created_at', $firstItem);

        // 2. Empty field allowlist returns empty array (deny-by-default)
        $this->testIntegration->update([
            'allowed_resources' => ['departments:read'],
            'allowed_resource_fields' => [
                'departments' => [],
            ],
        ]);

        $emptyResponse = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/departments');

        $emptyResponse->assertStatus(200)
            ->assertJson(['data' => []]);
    }

    public function test_reference_resources_project_isolation(): void
    {
        // Project A (Centrix): only 'code' for branches
        $this->testIntegration->update([
            'allowed_resources' => ['branches:read'],
            'allowed_resource_fields' => [
                'branches' => ['code'],
            ],
        ]);

        // Project B (HRMS): 'code', 'name', 'city' for branches
        $hrms = Project::where('code', 'hrms')->first();
        $hrms->integration->update([
            'allowed_resources' => ['branches:read'],
            'allowed_resource_fields' => [
                'branches' => ['code', 'name', 'city'],
            ],
        ]);

        $hrmsKey = $this->apiKeyService->generateKey(
            project: $hrms,
            name: 'HRMS Key'
        )['plain_text_key'];

        // Request with Centrix key
        $centrixResp = $this->withHeaders([
            'X-API-KEY' => $this->plainTextApiKey,
        ])->getJson('/api/v1/integrations/resources/branches');

        $centrixResp->assertStatus(200);
        $centrixItem = $centrixResp->json('data.0');
        $this->assertArrayHasKey('code', $centrixItem);
        $this->assertArrayNotHasKey('name', $centrixItem);
        $this->assertArrayNotHasKey('city', $centrixItem);

        // Request with HRMS key
        $hrmsResp = $this->withHeaders([
            'X-API-KEY' => $hrmsKey,
        ])->getJson('/api/v1/integrations/resources/branches');

        $hrmsResp->assertStatus(200);
        $hrmsItem = $hrmsResp->json('data.0');
        $this->assertArrayHasKey('code', $hrmsItem);
        $this->assertArrayHasKey('name', $hrmsItem);
        $this->assertArrayHasKey('city', $hrmsItem);
    }

    public function test_stockly_is_onboarded_with_correct_scopes_and_fields(): void
    {
        $stockly = Project::where('code', 'stockly')->first();
        $this->assertNotNull($stockly);
        $this->assertEquals('active', $stockly->status);
        $this->assertEquals('Stockly', $stockly->name);

        $integration = $stockly->integration;
        $this->assertNotNull($integration);
        $this->assertEquals('api_key', $integration->auth_method);
        $this->assertFalse($integration->sync_enabled);
        $this->assertFalse($integration->sso_enabled);

        $expectedScopes = [
            'employees:read',
            'departments:read',
            'designations:read',
            'branches:read',
            'regions:read',
            'zones:read',
            'provinces:read',
        ];
        $this->assertEquals($expectedScopes, $integration->allowed_resources);

        // Check field allowlists
        $allowedResourceFields = $integration->allowed_resource_fields;
        $this->assertArrayHasKey('employees', $allowedResourceFields);
        $this->assertArrayHasKey('departments', $allowedResourceFields);
        $this->assertArrayHasKey('designations', $allowedResourceFields);
        $this->assertArrayHasKey('branches', $allowedResourceFields);
        $this->assertArrayHasKey('regions', $allowedResourceFields);
        $this->assertArrayHasKey('zones', $allowedResourceFields);
        $this->assertArrayHasKey('provinces', $allowedResourceFields);

        // Assert sensitive PII is absent
        $this->assertNotContains('id_number', $allowedResourceFields['employees']);
        $this->assertNotContains('date_of_birth', $allowedResourceFields['employees']);
        $this->assertNotContains('address_line_1', $allowedResourceFields['employees']);
    }

    public function test_stockly_can_consume_employee_and_org_resources_via_api_key(): void
    {
        $stockly = Project::where('code', 'stockly')->first();

        $keyResult = $this->apiKeyService->generateKey(
            project: $stockly,
            name: 'Stockly Production Integration Key'
        );
        $stocklyKey = $keyResult['plain_text_key'];

        // 1. Employees collection
        $empResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/employees');
        $empResp->assertStatus(200);
        $firstEmp = $empResp->json('data.0');
        $this->assertNotNull($firstEmp);
        $this->assertArrayHasKey('employee_code', $firstEmp);
        $this->assertArrayHasKey('full_name', $firstEmp);
        $this->assertArrayHasKey('department', $firstEmp);
        $this->assertArrayNotHasKey('id_number', $firstEmp);
        $this->assertArrayNotHasKey('date_of_birth', $firstEmp);

        // 2. Single Employee
        $singleResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/employees/EMP1001');
        $singleResp->assertStatus(200);
        $singleEmp = $singleResp->json('data');
        $this->assertEquals('EMP1001', $singleEmp['employee_code']);
        $this->assertEquals('John Doe', $singleEmp['full_name']);

        // 3. Departments
        $deptResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/departments');
        $deptResp->assertStatus(200);
        $deptItem = $deptResp->json('data.0');
        $this->assertArrayHasKey('code', $deptItem);
        $this->assertArrayHasKey('name', $deptItem);

        // 4. Designations
        $desigResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/designations');
        $desigResp->assertStatus(200);
        $desigItem = $desigResp->json('data.0');
        $this->assertArrayHasKey('code', $desigItem);
        $this->assertArrayHasKey('name', $desigItem);
        $this->assertArrayHasKey('department_code', $desigItem);

        // 5. Branches
        $branchResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/branches');
        $branchResp->assertStatus(200);
        $branchItem = $branchResp->json('data.0');
        $this->assertArrayHasKey('code', $branchItem);
        $this->assertArrayHasKey('name', $branchItem);
        $this->assertArrayHasKey('city', $branchItem);
        $this->assertArrayHasKey('region_code', $branchItem);

        // 6. Regions
        $regionResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/regions');
        $regionResp->assertStatus(200);
        $regionItem = $regionResp->json('data.0');
        $this->assertArrayHasKey('code', $regionItem);
        $this->assertArrayHasKey('name', $regionItem);
        $this->assertArrayHasKey('zonal_code', $regionItem);

        // 7. Zones
        $zoneResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/zones');
        $zoneResp->assertStatus(200);
        $zoneItem = $zoneResp->json('data.0');
        $this->assertArrayHasKey('code', $zoneItem);
        $this->assertArrayHasKey('name', $zoneItem);
        $this->assertArrayHasKey('province_code', $zoneItem);

        // 8. Provinces
        $provinceResp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson('/api/v1/integrations/resources/provinces');
        $provinceResp->assertStatus(200);
        $provinceItem = $provinceResp->json('data.0');
        $this->assertArrayHasKey('code', $provinceItem);
        $this->assertArrayHasKey('name', $provinceItem);
    }

    public function test_credix_is_onboarded_with_correct_scopes_and_canonical_fields(): void
    {
        $credix = Project::where('code', 'credix')->first();

        $this->assertNotNull($credix);
        $this->assertEquals('CrediX', $credix->name);
        $this->assertEquals('active', $credix->status);

        $integration = $credix->integration;
        $this->assertNotNull($integration);
        $this->assertEquals('api_key', $integration->auth_method);
        $this->assertFalse($integration->sync_enabled);
        $this->assertFalse($integration->sso_enabled);

        // Allowed resources must contain only employees:read
        $this->assertEquals(['employees:read'], $integration->allowed_resources);

        // Allowed employee fields must be strictly the canonical contract fields
        $allowedEmployeeFields = $integration->allowed_resource_fields['employees'] ?? [];
        $this->assertEquals(
            ['employee_code', 'full_name', 'id_type', 'id_number', 'phone_primary'],
            $allowedEmployeeFields
        );

        // Assert sensitive PII and unapproved fields are absent
        $this->assertNotContains('email', $allowedEmployeeFields);
        $this->assertNotContains('date_of_birth', $allowedEmployeeFields);
        $this->assertNotContains('address_line_1', $allowedEmployeeFields);
        $this->assertNotContains('department_code', $allowedEmployeeFields);
        $this->assertNotContains('salary', $allowedEmployeeFields);
        $this->assertNotContains('bank_account', $allowedEmployeeFields);

        // Assert non-canonical aliases are not in configuration
        $this->assertNotContains('employee_name', $allowedEmployeeFields);
        $this->assertNotContains('nic', $allowedEmployeeFields);
        $this->assertNotContains('phone_number', $allowedEmployeeFields);
    }

    public function test_credix_can_consume_employee_resources_using_canonical_contract(): void
    {
        $credix = Project::where('code', 'credix')->first();

        $keyResult = $this->apiKeyService->generateKey(
            project: $credix,
            name: 'CrediX Integration Test Key'
        );
        $credixKey = $keyResult['plain_text_key'];

        // 1. Create synthetic test employee with id_type = nic
        $empNic = Employee::create([
            'employee_code' => 'EMP_CDX_001',
            'f_name' => 'Alice',
            'l_name' => 'Perera',
            'full_name' => 'Alice Perera',
            'name_with_initials' => 'A. Perera',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '958889999V',
            'date_of_birth' => '1995-05-15',
            'email' => 'alice.perera@example.com',
            'phone' => '+94112345678',
            'phone_primary' => '+94771122334',
            'address_line_1' => '123 Main Road',
            'city' => 'Colombo',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // 2. Create synthetic test employee with id_type = passport
        $empPassport = Employee::create([
            'employee_code' => 'EMP_CDX_002',
            'f_name' => 'Bob',
            'l_name' => 'Foreigner',
            'full_name' => 'Bob Foreigner',
            'name_with_initials' => 'B. Foreigner',
            'employee_type' => 'contract',
            'id_type' => 'passport',
            'id_number' => 'N9876543',
            'date_of_birth' => '1990-08-20',
            'email' => 'bob.foreigner@example.com',
            'phone' => '+94119988776',
            'phone_primary' => '+94770009988',
            'address_line_1' => '456 Sea View',
            'city' => 'Colombo',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // --- Test 1: Single employee endpoint for employee with id_type = nic ---
        $resp1 = $this->withHeaders(['X-API-KEY' => $credixKey])
            ->getJson("/api/v1/integrations/resources/employees/{$empNic->employee_code}");

        $resp1->assertStatus(200);
        $data1 = $resp1->json('data');

        // Verify response contains ONLY the five permitted canonical fields
        $this->assertCount(5, $data1);
        $this->assertArrayHasKey('employee_code', $data1);
        $this->assertArrayHasKey('full_name', $data1);
        $this->assertArrayHasKey('id_type', $data1);
        $this->assertArrayHasKey('id_number', $data1);
        $this->assertArrayHasKey('phone_primary', $data1);

        // Verify canonical values
        $this->assertEquals('EMP_CDX_001', $data1['employee_code']);
        $this->assertEquals('Alice Perera', $data1['full_name']);
        $this->assertEquals('nic', $data1['id_type']);
        $this->assertEquals('958889999V', $data1['id_number']);
        $this->assertEquals('+94771122334', $data1['phone_primary']);

        // Verify non-canonical aliases do not exist in response
        $this->assertArrayNotHasKey('employee_name', $data1);
        $this->assertArrayNotHasKey('nic', $data1);
        $this->assertArrayNotHasKey('phone_number', $data1);

        // Verify unallowed fields are strictly excluded
        $this->assertArrayNotHasKey('id', $data1);
        $this->assertArrayNotHasKey('email', $data1);
        $this->assertArrayNotHasKey('phone', $data1);
        $this->assertArrayNotHasKey('date_of_birth', $data1);
        $this->assertArrayNotHasKey('address_line_1', $data1);
        $this->assertArrayNotHasKey('department_code', $data1);

        // --- Test 2: Single employee endpoint for employee with id_type = passport ---
        $resp2 = $this->withHeaders(['X-API-KEY' => $credixKey])
            ->getJson("/api/v1/integrations/resources/employees/{$empPassport->employee_code}");

        $resp2->assertStatus(200);
        $data2 = $resp2->json('data');

        $this->assertEquals('EMP_CDX_002', $data2['employee_code']);
        $this->assertEquals('Bob Foreigner', $data2['full_name']);
        $this->assertEquals('passport', $data2['id_type']);
        $this->assertEquals('N9876543', $data2['id_number']);
        $this->assertEquals('+94770009988', $data2['phone_primary']);

        // Consumer correctly distinguishes that id_number is a passport because id_type is passport
        $this->assertNotEquals('nic', $data2['id_type']);

        // --- Test 3: Paginated collection endpoint follows exact same rules ---
        $collResp = $this->withHeaders(['X-API-KEY' => $credixKey])
            ->getJson('/api/v1/integrations/resources/employees?per_page=10');

        $collResp->assertStatus(200);
        $items = $collResp->json('data');
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertArrayHasKey('employee_code', $item);
            $this->assertArrayHasKey('full_name', $item);
            $this->assertArrayHasKey('id_type', $item);
            $this->assertArrayHasKey('id_number', $item);
            $this->assertArrayHasKey('phone_primary', $item);

            $this->assertArrayNotHasKey('id', $item);
            $this->assertArrayNotHasKey('email', $item);
            $this->assertArrayNotHasKey('date_of_birth', $item);
            $this->assertArrayNotHasKey('address_line_1', $item);
            $this->assertArrayNotHasKey('employee_name', $item);
            $this->assertArrayNotHasKey('nic', $item);
            $this->assertArrayNotHasKey('phone_number', $item);
        }
    }

    public function test_another_project_without_identity_document_permission_cannot_receive_id_number(): void
    {
        // Stockly does NOT have id_number or id_type in allowed_resource_fields
        $stockly = Project::where('code', 'stockly')->first();
        $stocklyKey = $this->apiKeyService->generateKey(
            project: $stockly,
            name: 'Stockly Isolation Test Key'
        )['plain_text_key'];

        $resp = $this->withHeaders(['X-API-KEY' => $stocklyKey])
            ->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $resp->assertStatus(200);
        $data = $resp->json('data');

        $this->assertArrayNotHasKey('id_number', $data);
        $this->assertArrayNotHasKey('id_type', $data);

        // Centrix does NOT have id_number or id_type in allowed_resource_fields
        $centrixResp = $this->withHeaders(['X-API-KEY' => $this->plainTextApiKey])
            ->getJson("/api/v1/integrations/resources/employees/{$this->employee1->employee_code}");

        $centrixResp->assertStatus(200);
        $centrixData = $centrixResp->json('data');

        $this->assertArrayNotHasKey('id_number', $centrixData);
        $this->assertArrayNotHasKey('id_type', $centrixData);
    }
}


