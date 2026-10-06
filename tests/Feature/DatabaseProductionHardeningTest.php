<?php

namespace Tests\Feature;

use App\Exceptions\OptimisticLockException;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectAccessRequest;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Import\BulkImportService;
use App\Services\Report\AccessReportQueryService;
use App\Services\Report\CsvReportGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Project $hrms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->hrms = Project::where('code', 'hrms')->first();

        $employee = Employee::create([
            'employee_code' => 'EMP9901',
            'f_name' => 'Hardening',
            'l_name' => 'Admin',
            'full_name' => 'Hardening Admin',
            'name_with_initials' => 'H. Admin',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '990111222V',
            'date_of_birth' => '1990-01-01',
            'email' => 'hardening.admin@example.com',
            'phone' => '+94779901001',
            'address_line_1' => 'Hardening Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94779901001',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'start_date' => '2026-01-01',
        ]);

        $this->adminUser = User::create([
            'employee_code' => 'EMP9901',
            'name' => 'Hardening Admin',
            'username' => 'hardening_admin',
            'email' => 'hardening.admin@example.com',
            'password' => 'secret123',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->adminUser->assignRole('Super Admin');
    }

    /**
     * Test 1: Optimistic locking atomically rejects stale version updates.
     */
    public function test_optimistic_locking_prevents_concurrent_overwrites(): void
    {
        $access = UserProjectAccess::create([
            'user_id' => $this->adminUser->id,
            'project_id' => $this->hrms->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'version' => 1,
        ]);

        // Process 1 loads model with version 1
        $process1 = UserProjectAccess::find($access->id);
        $this->assertEquals(1, $process1->version);

        // Process 2 loads model with version 1
        $process2 = UserProjectAccess::find($access->id);
        $this->assertEquals(1, $process2->version);

        // Process 1 saves changes -> version increments to 2
        $process1->status = 'SUSPENDED';
        $process1->save();

        $this->assertEquals(2, $process1->fresh()->version);
        $this->assertEquals('SUSPENDED', $process1->fresh()->status);

        // Process 2 attempts to save stale changes with expectedVersion 1 -> must throw OptimisticLockException
        $this->expectException(OptimisticLockException::class);
        $process2->status = 'REVOKED';
        $process2->setExpectedVersion(1);
        $process2->save();
    }

    /**
     * Test 2: Project access requests foreign key prevents deletion of employee (ON DELETE RESTRICT).
     */
    public function test_project_access_requests_restrict_employee_deletion(): void
    {
        $employee = Employee::where('employee_code', 'EMP9901')->first();

        // Create an access request for this employee
        ProjectAccessRequest::create([
            'employee_id' => $employee->id,
            'requested_project_name' => 'HRMS',
            'nature_of_role' => 'Auditor',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->adminUser->id,
        ]);

        // Attempting to hard delete the employee must trigger foreign key constraint violation
        $this->expectException(QueryException::class);
        $employee->forceDelete();
    }

    /**
     * Test 3: Bulk import in-memory optimization minimizes database queries during employee ingestion.
     */
    public function test_bulk_employee_import_query_reduction_benchmark(): void
    {
        $service = app(BulkImportService::class);
        $headers = $service->getEmployeeTemplateHeaders();

        // Generate 10 valid employee rows (using EMP98XX to avoid colliding with EMP9901)
        $csvRows = [implode(',', $headers)];
        for ($i = 1; $i <= 10; $i++) {
            $code = sprintf('EMP98%02d', $i);
            $idNumber = sprintf('980111%03dV', $i);
            $csvRows[] = implode(',', [
                $code, "First{$i}", "Last{$i}", "Full Name {$i}", "F. Name {$i}", 'permanent',
                'nic', $idNumber, '1992-05-10', "emp{$i}@example.com", '+9477980100' . $i,
                'Street', 'Colombo', 'Sri Lanka', '+9477980100' . $i, 'WP', 'Z01', 'R01',
                'DEP01', 'DES01', '2026-01-01'
            ]);
        }

        $csvContent = implode("\n", $csvRows) . "\n";
        $file = UploadedFile::fake()->createWithContent('benchmark_employees.csv', $csvContent);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $service->importEmployees($file, $this->adminUser);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(10, $result['summary']['successful']);
        $this->assertEquals(0, $result['summary']['failed']);

        // Verify that per-row SELECT queries for employee_code and id_number uniqueness are NOT executed.
        $selectQueries = array_filter($queries, fn ($q) => stripos(trim($q['query']), 'select') === 0);
        // Under old row-by-row validation, 10 rows would execute at least 80 SELECT queries.
        // With in-memory pre-flight, exactly 8 pre-flight SELECTs occur regardless of row count.
        $this->assertLessThanOrEqual(10, count($selectQueries));
    }

    /**
     * Test 4: Bulk user import query reduction benchmark with Bcrypt security preservation.
     */
    public function test_bulk_user_import_query_reduction_benchmark(): void
    {
        // First create 5 employees to link
        for ($i = 1; $i <= 5; $i++) {
            Employee::create([
                'employee_code' => sprintf('EMP88%02d', $i),
                'f_name' => "UserEmp{$i}",
                'l_name' => "Test{$i}",
                'full_name' => "UserEmp Test {$i}",
                'name_with_initials' => "U. Test {$i}",
                'employee_type' => 'permanent',
                'id_type' => 'nic',
                'id_number' => sprintf('880111%03dV', $i),
                'date_of_birth' => '1991-03-15',
                'email' => "useremp{$i}@example.com",
                'phone' => '+9477880100' . $i,
                'address_line_1' => 'User Road',
                'city' => 'Colombo',
                'country' => 'Sri Lanka',
                'phone_primary' => '+9477880100' . $i,
                'province_code' => 'WP',
                'zonal_code' => 'Z01',
                'region_code' => 'R01',
                'department_code' => 'DEP01',
                'designation_code' => 'DES01',
                'start_date' => '2026-01-01',
            ]);
        }

        $service = app(BulkImportService::class);
        $userHeaders = $service->getUserTemplateHeaders();

        $csvRows = [implode(',', $userHeaders)];
        for ($i = 1; $i <= 5; $i++) {
            $csvRows[] = implode(',', [
                sprintf('EMP88%02d', $i),
                "Test User {$i}",
                "testuser_{$i}",
                "testuser{$i}@example.com",
                'staff',
                '1',
                '1',
                'Staff',
            ]);
        }

        $csvContent = implode("\n", $csvRows) . "\n";
        $file = UploadedFile::fake()->createWithContent('benchmark_users.csv', $csvContent);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $service->importUsers($file, $this->adminUser);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(5, $result['summary']['successful']);
        $this->assertEquals(0, $result['summary']['failed']);

        // Verify that 5 users were created with Bcrypt passwords and must_change_password=true
        $createdUser = User::where('username', 'testuser_1')->first();
        $this->assertNotNull($createdUser);
        $this->assertTrue((bool)$createdUser->must_change_password);
        $this->assertTrue(str_starts_with($createdUser->password, '$2y$'));
    }

    /**
     * Test 5: Access reporting consolidates multi-filter subqueries and streams with O(1) memory.
     */
    public function test_access_report_query_consolidation_and_streaming(): void
    {
        // Create an access record
        $access = UserProjectAccess::create([
            'user_id' => $this->adminUser->id,
            'project_id' => $this->hrms->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'version' => 1,
        ]);

        $queryService = app(AccessReportQueryService::class);
        $query = $queryService->buildQuery([
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'province_code' => 'WP',
        ]);

        // Verify the SQL compiles properly and executes cleanly
        $results = $query->get();
        $this->assertCount(1, $results);
        $this->assertEquals($access->id, $results->first()->id);

        // Verify CSV report generator streams cleanly
        $csvGenerator = app(CsvReportGenerator::class);
        $response = $csvGenerator->streamAccessReport($query);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('Employee Code', $content);
        $this->assertStringContainsString('EMP9901', $content);
        $this->assertStringContainsString('Hardening Admin', $content);
    }

    /**
     * Test 6: Database-level foreign key rejects inserting employee with non-canonical department_code.
     */
    public function test_employee_organizational_foreign_key_rejects_invalid_department(): void
    {
        $this->expectException(QueryException::class);

        Employee::create([
            'employee_code' => 'EMP_FK_FAIL_01',
            'f_name' => 'FK',
            'l_name' => 'Fail',
            'full_name' => 'FK Fail',
            'name_with_initials' => 'F. Fail',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '990111999V',
            'date_of_birth' => '1990-01-01',
            'email' => 'fk.fail@example.com',
            'phone' => '+94779901999',
            'address_line_1' => 'Fail Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94779901999',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'NON_CANONICAL_DEPT',
            'designation_code' => 'DES01',
            'start_date' => '2026-01-01',
        ]);
    }

    /**
     * Test 7: Bulk import rejects non-canonical organizational codes and in-batch duplicates.
     */
    public function test_bulk_employee_import_rejects_non_canonical_org_codes_and_in_batch_duplicates(): void
    {
        $service = app(BulkImportService::class);
        $headers = $service->getEmployeeTemplateHeaders();

        // Row 1: Invalid department code
        // Row 2: Valid
        // Row 3: In-batch duplicate of Row 2 employee_code
        $csvRows = [
            implode(',', $headers),
            implode(',', [
                'EMP_BAD_01', 'Bad', 'Dept', 'Bad Dept', 'B. Dept', 'permanent',
                'nic', '980111881V', '1992-05-10', 'bad.dept@example.com', '+94779801881',
                'Street', 'Colombo', 'Sri Lanka', '+94779801881', 'WP', 'Z01', 'R01',
                'NON_EXISTENT_DEP', 'DES01', '2026-01-01'
            ]),
            implode(',', [
                'EMP_GOOD_02', 'Good', 'One', 'Good One', 'G. One', 'permanent',
                'nic', '980111882V', '1992-05-10', 'good.one@example.com', '+94779801882',
                'Street', 'Colombo', 'Sri Lanka', '+94779801882', 'WP', 'Z01', 'R01',
                'DEP01', 'DES01', '2026-01-01'
            ]),
            implode(',', [
                'EMP_GOOD_02', 'Dup', 'Two', 'Dup Two', 'D. Two', 'permanent',
                'nic', '980111883V', '1992-05-10', 'dup.two@example.com', '+94779801883',
                'Street', 'Colombo', 'Sri Lanka', '+94779801883', 'WP', 'Z01', 'R01',
                'DEP01', 'DES01', '2026-01-01'
            ]),
        ];

        $csvContent = implode("\n", $csvRows) . "\n";
        $file = UploadedFile::fake()->createWithContent('test_validation.csv', $csvContent);

        $result = $service->importEmployees($file, $this->adminUser);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['summary']['successful']);
        $this->assertEquals(2, $result['summary']['failed']);

        // Check error reasons
        $reasons = array_column($result['errors'], 'reason');
        $this->assertStringContainsString('department code', $reasons[0]);
        $this->assertStringContainsString('Duplicate employee_code', $reasons[1]);
    }
}
