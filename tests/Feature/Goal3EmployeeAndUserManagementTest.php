<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCreationDraft;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Goal3EmployeeAndUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $hrUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();

        // Create an HR user for role permission tests
        $hrEmployee = Employee::create([
            'employee_code' => 'EMP_HR_01',
            'f_name' => 'HR',
            'l_name' => 'Officer',
            'full_name' => 'HR Officer',
            'name_with_initials' => 'H. Officer',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '912345678V',
            'date_of_birth' => '1991-01-01',
            'email' => 'hr@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'HR St',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES02',
        ]);

        $this->hrUser = User::create([
            'employee_code' => 'EMP_HR_01',
            'name' => 'HR Officer',
            'username' => 'hr_officer',
            'email' => 'hr@example.com',
            'password' => 'password123',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->hrUser->assignRole('HR');
    }

    /**
     * Test 1: Creating an employee alone persists the master HR record without creating a user account.
     */
    public function test_create_employee_only_without_user_account(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $payload = [
            'employee_code' => 'EMP3001',
            'f_name' => 'Jane',
            'l_name' => 'Smith',
            'full_name' => 'Jane Smith',
            'name_with_initials' => 'J. Smith',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '961234567V',
            'date_of_birth' => '1996-04-12',
            'email' => 'jane.smith@example.com',
            'phone' => '0112345678',
            'address_line_1' => '456 Elm St',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '0771234567',
            'start_date' => '2026-04-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'create_user_account' => false,
        ];

        $response = $this->postJson('/api/v1/employees', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'employee_code' => 'EMP3001',
                    'full_name' => 'Jane Smith',
                    'phone_primary' => '+94771234567', // Normalized!
                ],
            ]);

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP3001']);
        // Verify NO user was created for EMP3001
        $this->assertDatabaseMissing('users', ['employee_code' => 'EMP3001']);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EMPLOYEE_CREATED',
            'entity_id' => 'EMP3001',
        ]);
    }

    /**
     * Test 2: Creating an employee with optional user account persists both.
     */
    public function test_create_employee_with_optional_user_account(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $payload = [
            'employee_code' => 'EMP3002',
            'f_name' => 'Robert',
            'l_name' => 'Taylor',
            'full_name' => 'Robert Taylor',
            'name_with_initials' => 'R. Taylor',
            'employee_type' => 'contract',
            'id_type' => 'nic',
            'id_number' => '199512345678', // 12-digit format
            'date_of_birth' => '1995-10-20',
            'email' => 'robert.taylor@example.com',
            'phone' => '+94112345678',
            'address_line_1' => '789 Oak Ave',
            'city' => 'Kandy',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94812345678',
            'start_date' => '2026-05-01',
            'province_code' => 'CP',
            'zonal_code' => 'Z02',
            'region_code' => 'R02',
            'department_code' => 'DEP02',
            'designation_code' => 'DES04',
            'create_user_account' => true,
            'username' => 'robertt',
            'password' => 'SecurePass#2026',
            'user_type' => 'staff',
            'roles' => ['Staff'],
        ];

        $response = $this->postJson('/api/v1/employees', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP3002']);
        $this->assertDatabaseHas('users', [
            'employee_code' => 'EMP3002',
            'username' => 'robertt',
            'email' => 'robert.taylor@example.com',
        ]);

        $user = User::where('username', 'robertt')->first();
        $this->assertTrue($user->hasRole('Staff'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'USER_CREATED',
            'entity_id' => (string) $user->id,
        ]);
    }

    /**
     * Test 3: NIC format validation rejects invalid strings and accepts valid 9+V and 12-digit formats.
     */
    public function test_nic_format_validation(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $basePayload = [
            'employee_code' => 'EMP3003',
            'f_name' => 'Test',
            'l_name' => 'User',
            'full_name' => 'Test User',
            'name_with_initials' => 'T. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'date_of_birth' => '1990-01-01',
            'email' => 'test.user@example.com',
            'phone' => '+94112345678',
            'address_line_1' => '123 Test St',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94771234567',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ];

        // Invalid NIC
        $invalidPayload = array_merge($basePayload, ['id_number' => '12345INVALID']);
        $resp = $this->postJson('/api/v1/employees', $invalidPayload);
        $resp->assertStatus(422)->assertJsonValidationErrors(['id_number']);

        // Valid 9-digit + X format
        $validPayload = array_merge($basePayload, ['id_number' => '923456789X']);
        $validResp = $this->postJson('/api/v1/employees', $validPayload);
        $validResp->assertStatus(201);
    }

    /**
     * Test 4: Optimistic locking conflict detection (HTTP 409).
     */
    public function test_optimistic_locking_conflict_on_employee_update(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $employee = Employee::where('employee_code', 'EMP1001')->first();
        $this->assertEquals(1, $employee->version);

        // Update 1 with version 1 succeeds
        $resp1 = $this->putJson("/api/v1/employees/{$employee->id}", [
            'version' => 1,
            'city' => 'Colombo 03',
        ]);
        $resp1->assertStatus(200);

        // Update 2 still sending stale version 1 must return HTTP 409 Conflict
        $resp2 = $this->putJson("/api/v1/employees/{$employee->id}", [
            'version' => 1,
            'city' => 'Colombo 07',
        ]);
        $resp2->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'error' => 'CONFLICT',
            ]);
    }

    /**
     * Test 5: Employee update synchronizes common fields to linked user.
     */
    public function test_employee_update_synchronizes_linked_user(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $employee = Employee::where('employee_code', 'EMP1001')->first();
        $user = User::where('employee_code', 'EMP1001')->first();
        $this->assertEquals('Sample Name', $user->name);

        $response = $this->putJson("/api/v1/employees/{$employee->id}", [
            'version' => $employee->version,
            'full_name' => 'Johnathon Doe Updated',
            'email' => 'john.updated@example.com',
        ]);
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('Johnathon Doe Updated', $user->name);
        $this->assertEquals('john.updated@example.com', $user->email);
    }

    /**
     * Test 6: Multi-step draft saving and resumption.
     */
    public function test_multi_step_draft_lifecycle(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Step 1: Save draft
        $draftResp = $this->postJson('/api/v1/users/drafts', [
            'current_step' => 1,
            'form_data' => [
                'f_name' => 'Draft',
                'l_name' => 'Employee',
                'id_number' => '941234567V',
            ],
        ]);

        $draftResp->assertStatus(201);
        $token = $draftResp->json('data.draft_token');
        $this->assertNotEmpty($token);

        // Step 2: Update same draft
        $step2Resp = $this->postJson('/api/v1/users/drafts', [
            'draft_token' => $token,
            'current_step' => 2,
            'form_data' => [
                'f_name' => 'Draft',
                'l_name' => 'Employee',
                'id_number' => '941234567V',
                'projects' => ['hrms', 'centrix'],
            ],
        ]);
        $step2Resp->assertStatus(201)
            ->assertJson([
                'data' => [
                    'current_step' => 2,
                ],
            ]);

        // Retrieve draft
        $getResp = $this->getJson("/api/v1/users/drafts/{$token}");
        $getResp->assertStatus(200)
            ->assertJson([
                'data' => [
                    'current_step' => 2,
                    'form_data' => [
                        'projects' => ['hrms', 'centrix'],
                    ],
                ],
            ]);

        // Discard draft
        $deleteResp = $this->deleteJson("/api/v1/users/drafts/{$token}");
        $deleteResp->assertStatus(200);

        // Retrieval fails after deletion
        $afterDeleteResp = $this->getJson("/api/v1/users/drafts/{$token}");
        $afterDeleteResp->assertStatus(404);
    }

    /**
     * Test 7: Section 40 - Prevent Privilege Escalation when creating user.
     */
    public function test_privilege_escalation_prevention(): void
    {
        // User with USER_CREATE attempting to assign Super Admin role must be rejected
        $this->hrUser->givePermissionTo('USER_CREATE');
        $this->actingAs($this->hrUser, 'web');

        $employee = Employee::create([
            'employee_code' => 'EMP3004',
            'f_name' => 'Attempt',
            'l_name' => 'Escalate',
            'full_name' => 'Attempt Escalate',
            'name_with_initials' => 'A. Escalate',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '948765432V',
            'date_of_birth' => '1994-04-04',
            'email' => 'escalate@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Street',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => 'EMP3004',
            'name' => 'Attempt Escalate',
            'username' => 'escalate_user',
            'email' => 'escalate@example.com',
            'password' => 'Password#123',
            'user_type' => 'admin',
            'roles' => ['Super Admin'], // Forbidden for HR!
        ]);

        $response->assertStatus(403)
            ->assertSee('Privilege escalation rejected');
    }
}
