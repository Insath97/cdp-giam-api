<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Goal10AccessReportingAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $staffUser;
    protected Project $hrms;
    protected ProjectRole $hrmsRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->hrms = Project::where('code', 'hrms')->first();

        // 1. Admin user with REPORT_VIEW, REPORT_EXPORT, AUDIT_VIEW
        $adminEmployee = Employee::create([
            'employee_code' => 'EMP1091',
            'f_name' => 'Report',
            'l_name' => 'Admin',
            'full_name' => 'Report Administrator',
            'name_with_initials' => 'R. Admin',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '931114445V',
            'date_of_birth' => '1993-01-01',
            'email' => 'report.admin@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Admin Avenue',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->adminUser = User::create([
            'employee_code' => 'EMP1091',
            'name' => 'Report Administrator',
            'username' => 'report_admin',
            'email' => 'report.admin@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->adminUser->assignRole('Admin');

        // 2. Staff user without reporting permissions
        $staffEmployee = Employee::create([
            'employee_code' => 'EMP1092',
            'f_name' => 'Basic',
            'l_name' => 'Staff',
            'full_name' => 'Basic Staff',
            'name_with_initials' => 'B. Staff',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '931114446V',
            'date_of_birth' => '1993-02-02',
            'email' => 'basic.staff@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Staff Lane',
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
            'employee_code' => 'EMP1092',
            'name' => 'Basic Staff',
            'username' => 'basic_staff',
            'email' => 'basic.staff@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->staffUser->assignRole('Staff');

        // 3. Project role and access assignment
        $this->hrmsRole = ProjectRole::create([
            'project_id' => $this->hrms->id,
            'external_role_id' => 'hrms_auditor',
            'code' => 'hrms_auditor',
            'name' => 'HR Auditor',
            'is_active' => true,
        ]);

        $access = UserProjectAccess::create([
            'user_id' => $this->adminUser->id,
            'project_id' => $this->hrms->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->adminUser->id,
            'version' => 1,
        ]);
        $access->roles()->sync([
            $this->hrmsRole->id => [
                'external_role_id' => 'hrms_auditor',
                'assigned_at' => now(),
            ],
        ]);
    }

    /**
     * Test 1: JSON Access Report supports rich filtering and pagination.
     */
    public function test_access_report_json_matrix_with_filters(): void
    {
        $this->actingAs($this->adminUser, 'web');

        $response = $this->getJson("/api/v1/reports/access?project_id={$this->hrms->id}&status=ACTIVE");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'current_page',
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'project_id',
                        'status',
                        'roles',
                        'user' => [
                            'id',
                            'name',
                            'username',
                            'email',
                            'employee',
                        ],
                        'project' => [
                            'id',
                            'code',
                            'name',
                        ],
                    ],
                ],
                'total',
            ]);

        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals($this->hrms->id, $response->json('data.0.project_id'));
    }

    /**
     * Test 2: Access report CSV streaming export produces valid CSV and logs audit event.
     */
    public function test_access_report_csv_streaming_export(): void
    {
        $this->actingAs($this->adminUser, 'web');

        $response = $this->get('/api/v1/reports/access/export/csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        // Capture streamed content
        $content = $response->streamedContent();
        $this->assertStringContainsString('Employee Code', $content);
        $this->assertStringContainsString('Username,Email', $content);
        $this->assertStringContainsString('EMP1091', $content);
        $this->assertStringContainsString('Report Administrator', $content);
        $this->assertStringContainsString('hrms', $content);

        // Verify audit log registration
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REPORT_EXPORTED',
            'entity_type' => 'AccessReport',
            'entity_id' => 'CSV',
            'actor_user_id' => $this->adminUser->id,
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * Test 3: Access report PDF export renders binary PDF and logs audit event.
     */
    public function test_access_report_pdf_export(): void
    {
        $this->actingAs($this->adminUser, 'web');

        $response = $this->get('/api/v1/reports/access/export/pdf');

        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        // Verify PDF signature (%PDF-)
        $content = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);

        // Decompress FlateDecode streams to verify actual rendered content
        $decompressedText = $content;
        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $uncompressed = @gzuncompress($stream);
                if ($uncompressed !== false) {
                    $decompressedText .= ' ' . $uncompressed;
                }
            }
        }

        // Verify headers, employee details, and project role exist in rendered PDF text
        $this->assertStringContainsString('Global Identity', $decompressedText);
        $this->assertStringContainsString('EMP1091', $decompressedText);
        $this->assertStringContainsString('Report Administrator', $decompressedText);
        $this->assertStringContainsString('HR Auditor', $decompressedText);

        // Verify audit log registration
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REPORT_EXPORTED',
            'entity_type' => 'AccessReport',
            'entity_id' => 'PDF',
            'actor_user_id' => $this->adminUser->id,
            'status' => 'SUCCESS',
        ]);
    }

    /**
     * Test 4: Audit log CSV export requires both REPORT_EXPORT and AUDIT_VIEW permissions.
     */
    public function test_audit_logs_csv_export_requires_both_report_export_and_audit_view(): void
    {
        // Staff user has neither permission
        $this->actingAs($this->staffUser, 'web');
        $this->get('/api/v1/reports/audit/export/csv')->assertStatus(403);

        // Admin user has both permissions
        $this->actingAs($this->adminUser, 'web');
        $response = $this->get('/api/v1/reports/audit/export/csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('ID,Timestamp', $content);
        $this->assertStringContainsString('Action', $content);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REPORT_EXPORTED',
            'entity_type' => 'AuditLogReport',
            'entity_id' => 'CSV',
        ]);
    }

    /**
     * Test 5: Unprivileged users cannot view or export reports.
     */
    public function test_reports_permission_gating(): void
    {
        $this->actingAs($this->staffUser, 'web');

        $this->getJson('/api/v1/reports/access')->assertStatus(403);
        $this->get('/api/v1/reports/access/export/csv')->assertStatus(403);
        $this->get('/api/v1/reports/access/export/pdf')->assertStatus(403);
    }
}
