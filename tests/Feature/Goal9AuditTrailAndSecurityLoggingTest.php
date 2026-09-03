<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class Goal9AuditTrailAndSecurityLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $staffUser;
    protected User $auditorUser;
    protected Project $hrms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();
        $this->hrms = Project::where('code', 'hrms')->first();

        $employee = Employee::create([
            'employee_code' => 'EMP9001',
            'f_name' => 'Auditor',
            'l_name' => 'General',
            'full_name' => 'Auditor General',
            'name_with_initials' => 'A. General',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '941113335V',
            'date_of_birth' => '1994-01-01',
            'email' => 'auditor@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Audit Street',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->auditorUser = User::create([
            'employee_code' => 'EMP9001',
            'name' => 'Auditor General',
            'username' => 'auditor01',
            'email' => 'auditor@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->auditorUser->givePermissionTo('AUDIT_VIEW');

        $staffEmp = Employee::create([
            'employee_code' => 'EMP9002',
            'f_name' => 'Regular',
            'l_name' => 'Staff',
            'full_name' => 'Regular Staff 9002',
            'name_with_initials' => 'R. Staff',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '941113336V',
            'date_of_birth' => '1994-02-02',
            'email' => 'staff9002@example.com',
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

        $this->staffUser = User::create([
            'employee_code' => 'EMP9002',
            'name' => 'Regular Staff 9002',
            'username' => 'staff9002',
            'email' => 'staff9002@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->staffUser->assignRole('Staff');
    }

    /**
     * Test 1: AuditLog records are strictly immutable and throw RuntimeException on update or delete.
     */
    public function test_audit_logs_are_strictly_immutable_and_cannot_be_updated_or_deleted(): void
    {
        $log = AuditLog::create([
            'actor_user_id' => $this->superAdmin->id,
            'action' => 'TEST_IMMUTABILITY',
            'entity_type' => 'Test',
            'entity_id' => '100',
            'ip_address' => '127.0.0.1',
            'status' => 'SUCCESS',
            'created_at' => now(),
        ]);

        // Attempting to update throws RuntimeException
        try {
            $log->update(['action' => 'TAMPERED_ACTION']);
            $this->fail('Expected RuntimeException was not thrown on update.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('strictly immutable', $e->getMessage());
        }

        // Attempting to delete throws RuntimeException
        try {
            $log->delete();
            $this->fail('Expected RuntimeException was not thrown on delete.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tamper-evident', $e->getMessage());
        }

        // Record still intact and unchanged
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'TEST_IMMUTABILITY',
        ]);
    }

    /**
     * Test 1b: MySQL database triggers reject raw SQL update and delete bypassing Eloquent.
     */
    public function test_database_triggers_prevent_raw_sql_update_and_delete_bypassing_eloquent(): void
    {
        $logId = \Illuminate\Support\Facades\DB::table('audit_logs')->insertGetId([
            'actor_user_id' => $this->superAdmin->id,
            'action' => 'RAW_TRIGGER_TEST',
            'entity_type' => 'RawTest',
            'entity_id' => '200',
            'ip_address' => '127.0.0.1',
            'status' => 'SUCCESS',
            'created_at' => now(),
        ]);

        // 1. Raw SQL UPDATE bypasses Eloquent events, but must be rejected by MySQL BEFORE UPDATE trigger
        try {
            \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('id', $logId)
                ->update(['action' => 'RAW_TAMPERED']);
            $this->fail('Expected QueryException was not thrown on raw SQL UPDATE.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('strictly immutable and cannot be updated at the database level', $e->getMessage());
        }

        // 2. Raw SQL DELETE bypasses Eloquent events, but must be rejected by MySQL BEFORE DELETE trigger
        try {
            \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('id', $logId)
                ->delete();
            $this->fail('Expected QueryException was not thrown on raw SQL DELETE.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('tamper-evident and cannot be deleted at the database level', $e->getMessage());
        }

        // 3. Confirm row is still intact in the database
        $row = \Illuminate\Support\Facades\DB::table('audit_logs')->where('id', $logId)->first();
        $this->assertNotNull($row);
        $this->assertEquals('RAW_TRIGGER_TEST', $row->action);
    }

    /**
     * Test 2: Querying audit logs with action, date range, and actor filters.
     */
    public function test_querying_audit_logs_with_action_date_and_actor_filters(): void
    {
        $this->actingAs($this->auditorUser, 'web');

        AuditLog::create([
            'actor_user_id' => $this->superAdmin->id,
            'action' => 'USER_CREATED',
            'entity_type' => 'User',
            'entity_id' => 'USER_101',
            'ip_address' => '192.168.1.10',
            'status' => 'SUCCESS',
            'created_at' => now()->subDays(2),
        ]);

        AuditLog::create([
            'actor_user_id' => $this->auditorUser->id,
            'action' => 'LOGIN_SUCCESS',
            'entity_type' => 'User',
            'entity_id' => 'USER_102',
            'ip_address' => '192.168.1.20',
            'status' => 'SUCCESS',
            'created_at' => now()->subDay(),
        ]);

        // 1. Filter by action
        $response = $this->getJson('/api/v1/audit-logs?action=LOGIN_SUCCESS');
        $response->assertStatus(200);
        $actions = collect($response->json('data'))->pluck('action');
        $this->assertTrue($actions->every(fn ($a) => $a === 'LOGIN_SUCCESS'));

        // 2. Filter by actor_user_id
        $actorResp = $this->getJson("/api/v1/audit-logs?actor_user_id={$this->superAdmin->id}");
        $actorResp->assertStatus(200);
        $actorIds = collect($actorResp->json('data'))->pluck('actor_user_id');
        $this->assertTrue($actorIds->every(fn ($id) => $id === $this->superAdmin->id));

        // 3. Filter by search
        $searchResp = $this->getJson('/api/v1/audit-logs?search=192.168.1.20');
        $searchResp->assertStatus(200);
        $this->assertCount(1, $searchResp->json('data'));
        $this->assertEquals('192.168.1.20', $searchResp->json('data.0.ip_address'));
    }

    /**
     * Test 3: Detail view returns full diff snapshots and correlation metadata.
     */
    public function test_single_audit_log_view_returns_full_diff_data(): void
    {
        $this->actingAs($this->auditorUser, 'web');

        $log = AuditLog::create([
            'actor_user_id' => $this->superAdmin->id,
            'action' => 'PROJECT_ACCESS_GRANTED',
            'entity_type' => 'UserProjectAccess',
            'entity_id' => '45',
            'project_id' => $this->hrms->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0 TestBrowser',
            'request_id' => 'req-trace-xyz-999',
            'before_data' => ['roles' => []],
            'after_data' => ['roles' => ['hrms_admin']],
            'status' => 'SUCCESS',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/audit-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $log->id,
                    'action' => 'PROJECT_ACCESS_GRANTED',
                    'entity_type' => 'UserProjectAccess',
                    'entity_id' => '45',
                    'request_id' => 'req-trace-xyz-999',
                    'before_data' => ['roles' => []],
                    'after_data' => ['roles' => ['hrms_admin']],
                    'project' => [
                        'id' => $this->hrms->id,
                        'code' => 'hrms',
                    ],
                ],
            ]);
    }

    /**
     * Test 4: Access to audit-logs requires AUDIT_VIEW permission.
     */
    public function test_audit_logs_require_audit_view_permission(): void
    {
        // Regular staff user has no AUDIT_VIEW permission
        $this->actingAs($this->staffUser, 'web');

        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
        $this->getJson('/api/v1/audit-logs/1')->assertStatus(403);

        // Auditor user with Audit Analyst role has AUDIT_VIEW
        $this->actingAs($this->auditorUser, 'web');
        $this->getJson('/api/v1/audit-logs')->assertStatus(200);
    }

    /**
     * Test 5: Sensitive credentials are automatically masked as [REDACTED].
     */
    public function test_sensitive_fields_are_masked_as_redacted(): void
    {
        $logger = app(AuditLoggerService::class);

        $log = $logger->log(
            action: 'TEST_SECRET_MASKING',
            entityType: 'User',
            entityId: '99',
            beforeData: ['password' => 'secret_plain_pw', 'name' => 'Alice'],
            afterData: ['password' => 'new_secret_pw', 'client_secret' => 'super_secret', 'email' => 'alice@test.com'],
            status: 'SUCCESS'
        );

        $log->refresh();

        // Passwords and secrets are masked
        $this->assertEquals('[REDACTED]', $log->before_data['password']);
        $this->assertEquals('[REDACTED]', $log->after_data['password']);
        $this->assertEquals('[REDACTED]', $log->after_data['client_secret']);

        // Non-sensitive fields preserved intact
        $this->assertEquals('Alice', $log->before_data['name']);
        $this->assertEquals('alice@test.com', $log->after_data['email']);
    }
}
