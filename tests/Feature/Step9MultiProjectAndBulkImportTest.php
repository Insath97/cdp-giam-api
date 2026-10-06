<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\UserProjectAccess;
use App\Services\Auth\SimpleJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Step9MultiProjectAndBulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $hrUser;
    protected User $staffUser;
    protected Employee $adminEmp;
    protected Employee $hrEmp;
    protected Employee $staffEmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\OrgStructureSeeder::class);

        // 1. Create essential GIAM permissions
        $permissions = [
            'USER_VIEW', 'USER_CREATE', 'USER_UPDATE', 'USER_DEACTIVATE',
            'EMPLOYEE_VIEW', 'EMPLOYEE_CREATE', 'EMPLOYEE_UPDATE',
            'ACCESS_VIEW', 'ACCESS_REQUEST', 'ACCESS_ASSIGN', 'ACCESS_REVOKE',
            'REPORT_VIEW', 'REPORT_EXPORT',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // 2. Roles
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $superAdminRole->syncPermissions($permissions);

        $hrRole = Role::firstOrCreate(['name' => 'HR', 'guard_name' => 'web']);
        $hrRole->syncPermissions(['USER_VIEW', 'EMPLOYEE_VIEW', 'EMPLOYEE_CREATE', 'EMPLOYEE_UPDATE', 'REPORT_VIEW']);

        $staffRole = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);
        $staffRole->syncPermissions([]);

        // 3. Real Employee Master Records
        $this->adminEmp = Employee::create([
            'employee_code' => 'EMP9001',
            'f_name' => 'Admin',
            'l_name' => 'Super',
            'full_name' => 'Admin Super',
            'name_with_initials' => 'A. Super',
            'id_type' => 'nic',
            'id_number' => '900000001V',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'employee_type' => 'permanent',
            'email' => 'admin.super@example.com',
            'phone' => '+94771000001',
            'phone_primary' => '+94771000001',
            'address_line_1' => 'HQ Colombo',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'branch_code' => 'BR01',
            'department_code' => 'DEP03',
            'designation_code' => 'DES06',
        ]);

        $this->hrEmp = Employee::create([
            'employee_code' => 'EMP9002',
            'f_name' => 'HR',
            'l_name' => 'Officer',
            'full_name' => 'HR Officer',
            'name_with_initials' => 'H. Officer',
            'id_type' => 'nic',
            'id_number' => '900000002V',
            'date_of_birth' => '1992-05-15',
            'gender' => 'female',
            'employee_type' => 'permanent',
            'email' => 'hr.officer@example.com',
            'phone' => '+94771000002',
            'phone_primary' => '+94771000002',
            'address_line_1' => 'HR Department',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'branch_code' => 'BR01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES02',
        ]);

        $this->staffEmp = Employee::create([
            'employee_code' => 'EMP9003',
            'f_name' => 'Staff',
            'l_name' => 'Member',
            'full_name' => 'Staff Member',
            'name_with_initials' => 'S. Member',
            'id_type' => 'nic',
            'id_number' => '900000003V',
            'date_of_birth' => '1995-10-20',
            'gender' => 'male',
            'employee_type' => 'contract',
            'email' => 'staff.member@example.com',
            'phone' => '+94771000003',
            'phone_primary' => '+94771000003',
            'address_line_1' => 'Operations Hub',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'branch_code' => 'BR01',
            'department_code' => 'DEP02',
            'designation_code' => 'DES04',
        ]);

        // 4. Linked Principals
        $this->adminUser = User::create([
            'employee_code' => 'EMP9001',
            'name' => 'Admin Super',
            'username' => 'admin_super',
            'email' => 'admin.super@example.com',
            'password' => Hash::make('Password@123'),
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->adminUser->assignRole('Super Admin');

        $this->hrUser = User::create([
            'employee_code' => 'EMP9002',
            'name' => 'HR Officer',
            'username' => 'hr_officer',
            'email' => 'hr.officer@example.com',
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->hrUser->assignRole('HR');

        $this->staffUser = User::create([
            'employee_code' => 'EMP9003',
            'name' => 'Staff Member',
            'username' => 'staff_member',
            'email' => 'staff.member@example.com',
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->staffUser->assignRole('Staff');
    }

    /**
     * Test 1: Employee CSV Template Download (requires EMPLOYEE_CREATE).
     */
    public function test_employee_template_download(): void
    {
        // Gated: Staff cannot download
        $this->actingAs($this->staffUser, 'web')
            ->get('/api/v1/employees/template')
            ->assertStatus(403);

        // HR has EMPLOYEE_CREATE
        $response = $this->actingAs($this->hrUser, 'web')
            ->get('/api/v1/employees/template');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('employee_code', $response->streamedContent());
    }

    /**
     * Test 2: User CSV Template Download has NO plaintext password column (requires USER_CREATE).
     */
    public function test_user_template_download_has_no_plaintext_password_column(): void
    {
        // HR does NOT have USER_CREATE
        $this->actingAs($this->hrUser, 'web')
            ->get('/api/v1/users/template')
            ->assertStatus(403);

        // Admin has USER_CREATE
        $response = $this->actingAs($this->adminUser, 'web')
            ->get('/api/v1/users/template');

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('employee_code', $content);
        $this->assertStringContainsString('username', $content);
        // CRITICAL REQUIREMENT: MUST NOT HAVE PASSWORD COLUMN
        $this->assertStringNotContainsString('password', strtolower($content));
    }

    /**
     * Test 3: Bulk Employee Import parses CSV and creates employee master rows (requires EMPLOYEE_CREATE).
     */
    public function test_bulk_employee_import_success(): void
    {
        $headers = app(\App\Services\Import\BulkImportService::class)->getEmployeeTemplateHeaders();
        $row = [
            'EMP9090', 'Kamal', 'Perera', 'Kamal Perera', 'K. Perera', 'permanent',
            'nic', '901112223V', '1990-03-10', 'kamal.perera@example.com', '+94779090001',
            'Main St', 'Colombo', 'Sri Lanka', '+94779090001', 'WP', 'Z01', 'R01',
            'DEP03', 'DES06', '2026-01-01'
        ];
        $csvContent = implode(',', $headers) . "\n" . implode(',', $row) . "\n";

        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        $response = $this->actingAs($this->hrUser, 'web')
            ->postJson('/api/v1/employees/bulk-import', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'summary' => [
                    'total' => 1,
                    'successful' => 1,
                    'failed' => 0,
                ],
            ]);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP9090',
            'full_name' => 'Kamal Perera',
        ]);
    }

    /**
     * Test 4: Bulk User Import validates employee exists, generates server temporary password, sets must_change_password=true (requires USER_CREATE).
     */
    public function test_bulk_user_import_creates_principals_safely(): void
    {
        // First create an employee to link
        $emp = Employee::create([
            'employee_code' => 'EMP9091',
            'f_name' => 'Nimal',
            'l_name' => 'Silva',
            'full_name' => 'Nimal Silva',
            'name_with_initials' => 'N. Silva',
            'id_type' => 'nic',
            'id_number' => '901112224V',
            'date_of_birth' => '1991-04-12',
            'gender' => 'male',
            'employee_type' => 'permanent',
            'email' => 'nimal.silva@example.com',
            'phone' => '+94779090002',
            'phone_primary' => '+94779090002',
            'address_line_1' => '2nd Cross St',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'branch_code' => 'BR01',
            'department_code' => 'DEP04',
            'designation_code' => 'DES07',
        ]);

        $csvContent = "employee_code,username,name,email,user_type,is_active,can_login,role\n" .
                      "EMP9091,nimal_silva,Nimal Silva,nimal.silva@example.com,staff,1,1,Staff\n";

        $file = UploadedFile::fake()->createWithContent('users.csv', $csvContent);

        $response = $this->actingAs($this->adminUser, 'web')
            ->postJson('/api/v1/users/bulk-import', ['file' => $file]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'summary' => [
                    'total' => 1,
                    'successful' => 1,
                    'failed' => 0,
                ],
            ]);

        $newUser = User::where('username', 'nimal_silva')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue((bool)$newUser->must_change_password);
        $this->assertEquals('EMP9091', $newUser->employee_code);
        $this->assertTrue($newUser->hasRole('Staff'));
    }

    /**
     * Test 5: Report Export Authorization (REPORT_VIEW vs REPORT_EXPORT).
     */
    public function test_report_export_authorization(): void
    {
        // HR has REPORT_VIEW but NOT REPORT_EXPORT -> 403 Forbidden
        $this->actingAs($this->hrUser, 'web')
            ->get('/api/v1/reports/access/export/csv')
            ->assertStatus(403);

        // Super Admin has REPORT_EXPORT -> 200 OK streamed CSV
        $response = $this->actingAs($this->adminUser, 'web')
            ->get('/api/v1/reports/access/export/csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('Employee Code', $response->streamedContent());
    }

    /**
     * Test 6: Normal Change Password API Endpoint.
     */
    public function test_normal_change_password_endpoint(): void
    {
        $response = $this->actingAs($this->staffUser, 'web')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Password@123',
                'new_password' => 'BrandNewStrongPass#2026',
                'new_password_confirmation' => 'BrandNewStrongPass#2026',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->staffUser->refresh();
        $this->assertTrue(Hash::check('BrandNewStrongPass#2026', $this->staffUser->password));
        $this->assertFalse((bool)$this->staffUser->must_change_password);
        $this->assertNotNull($this->staffUser->password_changed_at);
    }

    /**
     * Test 7: JWT Bearer Token Authentication directly against protected GIAM API.
     */
    public function test_jwt_bearer_token_access(): void
    {
        // 1. Generate valid signed token for staffUser
        $token = SimpleJwtService::generateToken($this->staffUser);
        $this->assertNotEmpty($token);

        // 2. Access protected endpoint with Bearer header (WITHOUT session)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'username' => 'staff_member',
                        'employee_code' => 'EMP9003',
                    ],
                ],
            ]);
    }

    /**
     * Test 8: Multi-Project Access Isolation in GET /users/{userId}/project-access.
     */
    public function test_multi_project_access_serialization(): void
    {
        $projectA = Project::create([
            'name' => 'Project Alpha',
            'code' => 'alpha',
            'base_url' => 'https://alpha.example.com',
            'status' => 'ACTIVE',
        ]);
        $roleA = ProjectRole::create([
            'project_id' => $projectA->id,
            'external_role_id' => 'alpha_lead',
            'code' => 'alpha_lead',
            'name' => 'Alpha Lead',
            'is_active' => true,
        ]);

        $projectB = Project::create([
            'name' => 'Project Beta',
            'code' => 'beta',
            'base_url' => 'https://beta.example.com',
            'status' => 'ACTIVE',
        ]);
        $roleB = ProjectRole::create([
            'project_id' => $projectB->id,
            'external_role_id' => 'beta_viewer',
            'code' => 'beta_viewer',
            'name' => 'Beta Viewer',
            'is_active' => true,
        ]);

        // Assign both projects with distinct roles to staffUser
        $accessA = UserProjectAccess::create([
            'user_id' => $this->staffUser->id,
            'project_id' => $projectA->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'assigned_at' => now(),
        ]);
        $accessA->roles()->sync([$roleA->id => ['external_role_id' => 'alpha_lead', 'assigned_at' => now()]]);

        $accessB = UserProjectAccess::create([
            'user_id' => $this->staffUser->id,
            'project_id' => $projectB->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'assigned_at' => now(),
        ]);
        $accessB->roles()->sync([$roleB->id => ['external_role_id' => 'beta_viewer', 'assigned_at' => now()]]);

        // Access overview endpoint
        $response = $this->actingAs($this->adminUser, 'web')
            ->getJson("/api/v1/users/{$this->staffUser->id}/project-access");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Alpha has role Alpha Lead
        $itemAlpha = collect($data)->firstWhere('project.code', 'alpha');
        $this->assertNotNull($itemAlpha);
        $this->assertEquals(['Alpha Lead'], collect($itemAlpha['roles'])->pluck('name')->toArray());

        // Beta has role Beta Viewer (NOT flattened or combined)
        $itemBeta = collect($data)->firstWhere('project.code', 'beta');
        $this->assertNotNull($itemBeta);
        $this->assertEquals(['Beta Viewer'], collect($itemBeta['roles'])->pluck('name')->toArray());
    }
}
