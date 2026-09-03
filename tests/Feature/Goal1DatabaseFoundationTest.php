<?php

namespace Tests\Feature;

use App\Exceptions\OptimisticLockException;
use App\Models\Employee;
use App\Models\OrgBranch;
use App\Models\OrgDepartment;
use App\Models\OrgDesignation;
use App\Models\OrgProvince;
use App\Models\OrgRegion;
use App\Models\OrgZone;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Goal1DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Test 1: Verify all 28 tables exist in MySQL database.
     */
    public function test_all_database_tables_exist(): void
    {
        $expectedTables = [
            // Org reference
            'org_provinces',
            'org_zones',
            'org_regions',
            'org_branches',
            'org_departments',
            'org_designations',
            // Identity & Employee
            'employees',
            'users',
            // GIAM RBAC
            'giam_permission_groups',
            'permissions',
            'roles',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            // Project Registry
            'projects',
            'project_integrations',
            // Project RBAC Catalog
            'project_modules',
            'project_roles',
            'project_permission_groups',
            'project_permissions',
            // Access & Assignments
            'user_project_access',
            'user_project_roles',
            'user_project_permissions',
            // Provisioning, SSO, Sessions
            'sync_jobs',
            'sso_auth_codes',
            'user_sessions',
            // Audit & Drafts
            'audit_logs',
            'user_creation_drafts',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Database table '{$table}' was expected to exist, but does not.");
        }
    }

    /**
     * Test 2: Verify organizational relationships and employee master linkages.
     */
    public function test_org_structure_and_employee_relationships(): void
    {
        $employee = Employee::where('employee_code', 'EMP1001')->first();
        $this->assertNotNull($employee);
        $this->assertEquals('John', $employee->f_name);
        $this->assertEquals('Doe', $employee->l_name);

        // Check relationships
        $this->assertNotNull($employee->province);
        $this->assertEquals('WP', $employee->province->code);
        $this->assertNotNull($employee->zone);
        $this->assertEquals('Z01', $employee->zone->code);
        $this->assertNotNull($employee->region);
        $this->assertEquals('R01', $employee->region->code);
        $this->assertNotNull($employee->branch);
        $this->assertEquals('BR01', $employee->branch->code);
        $this->assertNotNull($employee->department);
        $this->assertEquals('DEP01', $employee->department->code);
        $this->assertNotNull($employee->designation);
        $this->assertEquals('DES01', $employee->designation->code);

        // User linkage
        $user = User::where('employee_code', 'EMP1001')->first();
        $this->assertNotNull($user);
        $this->assertEquals($employee->id, $user->employee->id);
    }

    /**
     * Test 3: Verify unique constraints and foreign key integrity.
     */
    public function test_unique_constraints_and_validation(): void
    {
        // 1. Duplicate employee_code must fail
        $this->expectException(QueryException::class);
        Employee::create([
            'employee_code' => 'EMP1001', // duplicate
            'f_name' => 'Duplicate',
            'l_name' => 'Employee',
            'full_name' => 'Duplicate Employee',
            'name_with_initials' => 'D. Employee',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '999999999V',
            'date_of_birth' => '1990-01-01',
            'email' => 'other@example.com',
            'phone' => '+94112223344',
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
    }

    /**
     * Test 4: Verify unique ID document constraint on (id_type, id_number).
     */
    public function test_unique_id_document_constraint(): void
    {
        $this->expectException(QueryException::class);
        Employee::create([
            'employee_code' => 'EMP9999',
            'f_name' => 'Duplicate',
            'l_name' => 'ID',
            'full_name' => 'Duplicate ID',
            'name_with_initials' => 'D. ID',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951234567V', // Duplicate ID of EMP1001
            'date_of_birth' => '1990-01-01',
            'email' => 'unique9999@example.com',
            'phone' => '+94112223344',
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
    }

    /**
     * Test 5: Verify unique (user_id, project_id) on user_project_access.
     */
    public function test_unique_user_project_access_constraint(): void
    {
        $user = User::where('username', 'user01')->first();
        $project = Project::where('code', 'hrms')->first();

        // First assignment
        UserProjectAccess::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => 'PENDING',
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);

        // Duplicate assignment must throw QueryException
        $this->expectException(QueryException::class);
        UserProjectAccess::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => 'PENDING',
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Test 6: Verify optimistic concurrency locking.
     */
    public function test_optimistic_concurrency_locking(): void
    {
        $employee1 = Employee::where('employee_code', 'EMP1001')->first();
        $employee2 = Employee::where('employee_code', 'EMP1001')->first();

        $this->assertEquals(1, $employee1->version);
        $this->assertEquals(1, $employee2->version);

        // Process 1 updates employee
        $employee1->f_name = 'Jonathan';
        $employee1->save();

        $this->assertEquals(2, $employee1->fresh()->version);

        // Process 2 attempts to update with stale version 1
        $this->expectException(OptimisticLockException::class);
        $employee2->f_name = 'Johnny';
        $employee2->save();
    }

    /**
     * Test 7: Verify Spatie GIAM RBAC role and permission matrix.
     */
    public function test_spatie_giam_rbac_seeding(): void
    {
        $superAdmin = Role::findByName('Super Admin', 'web');
        $this->assertTrue($superAdmin->hasPermissionTo('USER_CREATE'));
        $this->assertTrue($superAdmin->hasPermissionTo('ACCESS_ASSIGN'));
        $this->assertTrue($superAdmin->hasPermissionTo('AUDIT_VIEW'));

        $staff = Role::findByName('Staff', 'web');
        $this->assertFalse($staff->hasPermissionTo('USER_CREATE'));
        $this->assertFalse($staff->hasPermissionTo('ACCESS_ASSIGN'));

        $user01 = User::where('username', 'user01')->first();
        $this->assertTrue($user01->hasRole('Super Admin'));
        $this->assertTrue($user01->can('USER_CREATE'));
    }

    /**
     * Test 8: Verify project registry and data projection allowlist configurations.
     */
    public function test_project_registry_and_data_projection_allowlist(): void
    {
        $hrms = Project::where('code', 'hrms')->first();
        $this->assertNotNull($hrms);
        $this->assertNotNull($hrms->integration);
        $this->assertContains('id_number', $hrms->integration->allowed_user_fields);
        $this->assertEquals('hrms_secret_key_12345', $hrms->integration->getDecryptedClientSecret());

        $centrix = Project::where('code', 'centrix')->first();
        $this->assertNotNull($centrix);
        $this->assertNotNull($centrix->integration);
        // Sensitive PII must NOT be in CENTRIX allowed_user_fields
        $this->assertNotContains('id_number', $centrix->integration->allowed_user_fields);
        $this->assertNotContains('date_of_birth', $centrix->integration->allowed_user_fields);
        $this->assertNotContains('address_line_1', $centrix->integration->allowed_user_fields);
        $this->assertContains('email', $centrix->integration->allowed_user_fields);
    }
}
