<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectAccessRequest;
use App\Models\ProjectRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Step4ProjectAccessRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $hrUser;
    protected User $accessAdminUser;
    protected User $plainUser;
    protected Project $centrix;
    protected ProjectRole $warehouseRole;
    protected Project $projectB;
    protected ProjectRole $projectBRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // HR Employee & User (Has EMPLOYEE_CREATE, but NOT USER_CREATE)
        $hrEmp = Employee::create([
            'employee_code' => 'EMP8001',
            'f_name' => 'Hannah',
            'l_name' => 'Recruiter',
            'full_name' => 'Hannah Recruiter',
            'name_with_initials' => 'H. Recruiter',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112221V',
            'date_of_birth' => '1995-01-01',
            'email' => 'hr@cdp.lk',
            'phone' => '+94112345001',
            'address_line_1' => 'HR Lane',
            'city' => 'Colombo',
            'phone_primary' => '+94770001001',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->hrUser = User::create([
            'employee_code' => 'EMP8001',
            'name' => 'Hannah Recruiter',
            'username' => 'hannah_hr',
            'email' => 'hr@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->hrUser->syncRoles(['HR']);

        // Access Administrator (Has ACCESS_VIEW, ACCESS_ASSIGN, ACCESS_REVOKE, USER_CREATE)
        $adminEmp = Employee::create([
            'employee_code' => 'EMP8002',
            'f_name' => 'Adam',
            'l_name' => 'Admin',
            'full_name' => 'Adam Admin',
            'name_with_initials' => 'A. Admin',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112222V',
            'date_of_birth' => '1995-02-02',
            'email' => 'admin@cdp.lk',
            'phone' => '+94112345002',
            'address_line_1' => 'Admin Lane',
            'city' => 'Colombo',
            'phone_primary' => '+94770001002',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->accessAdminUser = User::create([
            'employee_code' => 'EMP8002',
            'name' => 'Adam Admin',
            'username' => 'adam_admin',
            'email' => 'admin@cdp.lk',
            'password' => 'password123',
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->accessAdminUser->syncRoles(['Admin']);

        // Plain User (No elevated permissions)
        $plainEmp = Employee::create([
            'employee_code' => 'EMP8003',
            'f_name' => 'Paul',
            'l_name' => 'Plain',
            'full_name' => 'Paul Plain',
            'name_with_initials' => 'P. Plain',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112223V',
            'date_of_birth' => '1995-03-03',
            'email' => 'plain@cdp.lk',
            'phone' => '+94112345003',
            'address_line_1' => 'Plain St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001003',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->plainUser = User::create([
            'employee_code' => 'EMP8003',
            'name' => 'Paul Plain',
            'username' => 'paul_plain',
            'email' => 'plain@cdp.lk',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $this->plainUser->syncRoles(['Staff']);

        $this->centrix = Project::where('code', 'centrix')->firstOrFail();

        $this->warehouseRole = ProjectRole::firstOrCreate(
            ['project_id' => $this->centrix->id, 'external_role_id' => '10'],
            ['code' => 'warehouse_manager', 'name' => 'Warehouse Manager', 'is_active' => true]
        );

        // Project B for cross-project isolation checks
        $this->projectB = Project::firstOrCreate(
            ['code' => 'project_b'],
            [
                'name' => 'Project Bravo',
                'description' => 'Secondary project',
                'base_url' => 'https://bravo.local',
                'status' => 'active',
                'version' => 1,
            ]
        );

        $this->projectBRole = ProjectRole::firstOrCreate(
            ['project_id' => $this->projectB->id, 'code' => 'bravo_analyst'],
            ['name' => 'Bravo Analyst', 'is_active' => true, 'external_role_id' => '99']
        );
    }

    /**
     * Requirement 1: EMPLOYEE_CREATE without USER_CREATE cannot create a GIAM users account.
     */
    public function test_employee_create_without_user_create_cannot_create_giam_account(): void
    {
        $this->actingAs($this->hrUser);

        // HR tries to pass hidden user creation fields
        $payload = [
            'employee_code' => 'EMP8010',
            'f_name' => 'Sneaky',
            'l_name' => 'Creation',
            'full_name' => 'Sneaky Creation',
            'name_with_initials' => 'S. Creation',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112230V',
            'date_of_birth' => '1995-10-10',
            'email' => 'sneaky@cdp.lk',
            'phone' => '+94112345010',
            'address_line_1' => 'Sneaky Way',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94770001010',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'create_user_account' => true,
            'username' => 'sneaky_user',
            'password' => 'Password123!',
        ];

        $response = $this->postJson('/api/v1/employees', $payload);

        // Must be rejected with 403 Forbidden because HR does not possess USER_CREATE
        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['username' => 'sneaky_user']);
        $this->assertDatabaseMissing('users', ['employee_code' => 'EMP8010']);
    }

    /**
     * Requirement 2: submitted_by_user_id cannot be spoofed by client input.
     */
    public function test_submitted_by_user_id_cannot_be_spoofed(): void
    {
        $this->actingAs($this->hrUser);

        $payload = [
            'employee_code' => 'EMP8011',
            'f_name' => 'Valid',
            'l_name' => 'Employee',
            'full_name' => 'Valid Employee',
            'name_with_initials' => 'V. Employee',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112231V',
            'date_of_birth' => '1995-11-11',
            'email' => 'valid@cdp.lk',
            'phone' => '+94112345011',
            'address_line_1' => 'Valid Way',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94770001011',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'requested_project_name' => 'Warehouse Management',
            'nature_of_role' => 'Inventory coordination',
            // Attempt to spoof submitter
            'submitted_by_user_id' => 99999,
        ];

        $response = $this->postJson('/api/v1/employees', $payload);
        $response->assertStatus(201);

        // Verify request was created with HR user's actual ID, NOT the spoofed ID
        $req = ProjectAccessRequest::where('requested_project_name', 'Warehouse Management')->firstOrFail();
        $this->assertEquals($this->hrUser->id, $req->submitted_by_user_id);
        $this->assertNotEquals(99999, $req->submitted_by_user_id);
    }

    /**
     * Requirement 3: reviewed_by_user_id cannot be spoofed by client input.
     */
    public function test_reviewed_by_user_id_cannot_be_spoofed(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8012',
            'f_name' => 'Review',
            'l_name' => 'Target',
            'full_name' => 'Review Target',
            'name_with_initials' => 'R. Target',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112232V',
            'date_of_birth' => '1995-12-12',
            'email' => 'target@cdp.lk',
            'phone' => '+94112345012',
            'address_line_1' => 'Target St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001012',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP8012',
            'name' => 'Review Target',
            'username' => 'target8012',
            'email' => 'target@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Logistics oversight',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        $response = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
            // Attempt to spoof reviewer
            'reviewed_by_user_id' => 88888,
        ]);

        $response->assertStatus(200);

        $freshReq = $req->fresh();
        $this->assertEquals($this->accessAdminUser->id, $freshReq->reviewed_by_user_id);
        $this->assertNotEquals(88888, $freshReq->reviewed_by_user_id);
    }

    /**
     * Requirement 4: Project A request cannot use Project B role (strict server-side project scoping).
     */
    public function test_project_a_request_cannot_use_project_b_role(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8013',
            'f_name' => 'Cross',
            'l_name' => 'Project',
            'full_name' => 'Cross Project',
            'name_with_initials' => 'C. Project',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112233V',
            'date_of_birth' => '1995-01-13',
            'email' => 'cross@cdp.lk',
            'phone' => '+94112345013',
            'address_line_1' => 'Cross St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001013',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP8013',
            'name' => 'Cross Project',
            'username' => 'cross8013',
            'email' => 'cross@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Logistics',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        // Attempt to submit Centrix (Project A) with Project B role
        $response = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->projectBRole->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['code' => 'INVALID_PROJECT_ROLES']);
        $this->assertEquals('PENDING', $req->fresh()->status);
    }

    /**
     * Requirement 5 & 6: PENDING request becomes FULFILLED; FULFILLED request cannot resolve again (Concurrency/Double resolution).
     */
    public function test_fulfilled_request_cannot_resolve_again(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8014',
            'f_name' => 'Twice',
            'l_name' => 'Resolve',
            'full_name' => 'Twice Resolve',
            'name_with_initials' => 'T. Resolve',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112234V',
            'date_of_birth' => '1995-02-14',
            'email' => 'twice@cdp.lk',
            'phone' => '+94112345014',
            'address_line_1' => 'Twice St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001014',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP8014',
            'name' => 'Twice Resolve',
            'username' => 'twice8014',
            'email' => 'twice@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Supervisor',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        // First resolution: succeeds
        $res1 = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ]);
        $res1->assertStatus(200);
        $this->assertEquals('FULFILLED', $req->fresh()->status);

        // Second resolution: must be rejected with 409 Conflict
        $res2 = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ]);
        $res2->assertStatus(409);
        $res2->assertJsonFragment(['code' => 'INVALID_STATE']);
    }

    /**
     * Requirement 7 & 8: PENDING request becomes REJECTED; REJECTED request cannot resolve.
     */
    public function test_rejected_request_cannot_resolve(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8015',
            'f_name' => 'Rej',
            'l_name' => 'ThenResolve',
            'full_name' => 'Rej ThenResolve',
            'name_with_initials' => 'R. ThenResolve',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112235V',
            'date_of_birth' => '1995-03-15',
            'email' => 'rej@cdp.lk',
            'phone' => '+94112345015',
            'address_line_1' => 'Rej St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001015',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP8015',
            'name' => 'Rej ThenResolve',
            'username' => 'rej8015',
            'email' => 'rej@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Coordinator',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        // Reject request
        $resReject = $this->postJson("/api/v1/project-access-requests/{$req->id}/reject");
        $resReject->assertStatus(200);
        $this->assertEquals('REJECTED', $req->fresh()->status);

        // Attempt to resolve REJECTED request: must return 409 Conflict
        $resResolve = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ]);
        $resResolve->assertStatus(409);
        $resResolve->assertJsonFragment(['code' => 'INVALID_STATE']);
    }

    /**
     * Requirement 9: FULFILLED request cannot reject.
     */
    public function test_fulfilled_request_cannot_reject(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8016',
            'f_name' => 'Fulfilled',
            'l_name' => 'NoReject',
            'full_name' => 'Fulfilled NoReject',
            'name_with_initials' => 'F. NoReject',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112236V',
            'date_of_birth' => '1995-04-16',
            'email' => 'fulfilled@cdp.lk',
            'phone' => '+94112345016',
            'address_line_1' => 'NoReject St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001016',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP8016',
            'name' => 'Fulfilled NoReject',
            'username' => 'noreject8016',
            'email' => 'fulfilled@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Operations',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        // Resolve first
        $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ])->assertStatus(200);

        // Attempt to reject: must return 409 Conflict
        $resReject = $this->postJson("/api/v1/project-access-requests/{$req->id}/reject");
        $resReject->assertStatus(409);
        $resReject->assertJsonFragment(['code' => 'INVALID_STATE']);
    }

    /**
     * Requirement 10: Failed assignment leaves request PENDING.
     */
    public function test_failed_assignment_leaves_request_pending(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8017',
            'f_name' => 'Inactive',
            'l_name' => 'UserTarget',
            'full_name' => 'Inactive UserTarget',
            'name_with_initials' => 'I. UserTarget',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112237V',
            'date_of_birth' => '1995-05-17',
            'email' => 'inactive@cdp.lk',
            'phone' => '+94112345017',
            'address_line_1' => 'Inactive St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001017',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        // Inactive user: assignment service will throw 422
        User::create([
            'employee_code' => 'EMP8017',
            'name' => 'Inactive UserTarget',
            'username' => 'inactive8017',
            'email' => 'inactive@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => false,
            'can_login' => false,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Logistics',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        // Attempt to resolve: assignment service fails because user is inactive
        $response = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ]);

        $response->assertStatus(422);

        // Assert request status rolled back and remains PENDING
        $this->assertEquals('PENDING', $req->fresh()->status);
        $this->assertNull($req->fresh()->resolved_project_id);
    }

    /**
     * Requirement 11: Rejection requires ACCESS_ASSIGN, NOT ACCESS_REVOKE.
     */
    public function test_rejection_requires_access_assign_not_access_revoke(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8018',
            'f_name' => 'Perm',
            'l_name' => 'Check',
            'full_name' => 'Perm Check',
            'name_with_initials' => 'P. Check',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112238V',
            'date_of_birth' => '1995-06-18',
            'email' => 'perm@cdp.lk',
            'phone' => '+94112345018',
            'address_line_1' => 'Perm St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001018',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Coordinator',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $revokeEmp = Employee::create([
            'employee_code' => 'EMP8019',
            'f_name' => 'Revoke',
            'l_name' => 'Only',
            'full_name' => 'Revoke Only',
            'name_with_initials' => 'R. Only',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112239V',
            'date_of_birth' => '1995-07-19',
            'email' => 'revoke_only@cdp.lk',
            'phone' => '+94112345019',
            'address_line_1' => 'Revoke St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001019',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        // User with ONLY ACCESS_REVOKE (and NOT ACCESS_ASSIGN)
        $revokeOnlyUser = User::create([
            'employee_code' => 'EMP8019',
            'name' => 'Revoke Only',
            'username' => 'revoke_only_user',
            'email' => 'revoke_only@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
        $revokeOnlyUser->givePermissionTo('ACCESS_REVOKE');

        $this->actingAs($revokeOnlyUser);

        // Reject must fail with 403 because ACCESS_ASSIGN is required
        $res = $this->postJson("/api/v1/project-access-requests/{$req->id}/reject");
        $res->assertStatus(403);
    }

    /**
     * Foundational Test A: HR submits employee onboarding with free-text project request atomically.
     */
    public function test_hr_submits_employee_with_project_request_atomically(): void
    {
        $this->actingAs($this->hrUser);

        $payload = [
            'employee_code' => 'EMP8004',
            'f_name' => 'Oliver',
            'l_name' => 'Onboarded',
            'full_name' => 'Oliver Onboarded',
            'name_with_initials' => 'O. Onboarded',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112224V',
            'date_of_birth' => '1995-04-04',
            'email' => 'oliver@cdp.lk',
            'phone' => '+94112345004',
            'address_line_1' => 'Onboard Way',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'phone_primary' => '+94770001004',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'requested_project_name' => 'Centrix Logistics Fleet',
            'nature_of_role' => 'Responsible for receiving and dispatching warehouse inventory.',
        ];

        $response = $this->postJson('/api/v1/employees', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP8004']);

        $this->assertDatabaseHas('project_access_requests', [
            'requested_project_name' => 'Centrix Logistics Fleet',
            'nature_of_role' => 'Responsible for receiving and dispatching warehouse inventory.',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
            'resolved_project_id' => null,
            'user_project_access_id' => null,
        ]);

        $this->assertDatabaseMissing('users', ['employee_code' => 'EMP8004']);
        $this->assertDatabaseCount('user_project_access', 0);
    }

    /**
     * Foundational Test B: Access Administrator can view pending requests without employee having user.
     */
    public function test_access_admin_can_view_pending_requests_without_user(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8005',
            'f_name' => 'NoUser',
            'l_name' => 'Staff',
            'full_name' => 'NoUser Staff',
            'name_with_initials' => 'N. Staff',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112225V',
            'date_of_birth' => '1995-05-05',
            'email' => 'nouser@cdp.lk',
            'phone' => '+94112345005',
            'address_line_1' => 'Staff Road',
            'city' => 'Colombo',
            'phone_primary' => '+94770001005',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $request = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix System',
            'nature_of_role' => 'Logistics coordination',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        $response = $this->getJson('/api/v1/project-access-requests');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $request->id,
            'requested_project_name' => 'Centrix System',
            'status' => 'PENDING',
        ]);
    }

    /**
     * Foundational Test C: Resolving request fails if employee has no user account.
     */
    public function test_resolving_request_fails_if_employee_has_no_user_account(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8006',
            'f_name' => 'Orphan',
            'l_name' => 'Employee',
            'full_name' => 'Orphan Employee',
            'name_with_initials' => 'O. Employee',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112226V',
            'date_of_birth' => '1995-06-06',
            'email' => 'orphan@cdp.lk',
            'phone' => '+94112345006',
            'address_line_1' => 'No User Ave',
            'city' => 'Colombo',
            'phone_primary' => '+94770001006',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Freight dispatcher',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        $response = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['code' => 'USER_ACCOUNT_REQUIRED']);
    }

    /**
     * Foundational Test D: PENDING request becomes FULFILLED.
     */
    public function test_pending_request_can_become_fulfilled(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8007',
            'f_name' => 'Qualified',
            'l_name' => 'User',
            'full_name' => 'Qualified User',
            'name_with_initials' => 'Q. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112227V',
            'date_of_birth' => '1995-07-07',
            'email' => 'qualified@cdp.lk',
            'phone' => '+94112345007',
            'address_line_1' => 'Qualified St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001007',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => 'EMP8007',
            'name' => 'Qualified User',
            'username' => 'qualified8007',
            'email' => 'qualified@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Centrix',
            'nature_of_role' => 'Warehouse oversight',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        $response = $this->postJson("/api/v1/project-access-requests/{$req->id}/resolve", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->warehouseRole->id],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('project_access_requests', [
            'id' => $req->id,
            'status' => 'FULFILLED',
            'resolved_project_id' => $this->centrix->id,
            'reviewed_by_user_id' => $this->accessAdminUser->id,
        ]);

        $this->assertDatabaseHas('user_project_access', [
            'user_id' => $user->id,
            'project_id' => $this->centrix->id,
        ]);
    }

    /**
     * Foundational Test E: PENDING request becomes REJECTED.
     */
    public function test_pending_request_can_become_rejected(): void
    {
        $emp = Employee::create([
            'employee_code' => 'EMP8008',
            'f_name' => 'Rejected',
            'l_name' => 'Candidate',
            'full_name' => 'Rejected Candidate',
            'name_with_initials' => 'R. Candidate',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112228V',
            'date_of_birth' => '1995-08-08',
            'email' => 'rejected@cdp.lk',
            'phone' => '+94112345008',
            'address_line_1' => 'Candidate St',
            'city' => 'Colombo',
            'phone_primary' => '+94770001008',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $req = ProjectAccessRequest::create([
            'employee_id' => $emp->id,
            'requested_project_name' => 'Unknown System',
            'nature_of_role' => 'Inappropriate request',
            'status' => 'PENDING',
            'submitted_by_user_id' => $this->hrUser->id,
        ]);

        $this->actingAs($this->accessAdminUser);

        $response = $this->postJson("/api/v1/project-access-requests/{$req->id}/reject");

        $response->assertStatus(200);

        $this->assertDatabaseHas('project_access_requests', [
            'id' => $req->id,
            'status' => 'REJECTED',
            'reviewed_by_user_id' => $this->accessAdminUser->id,
            'resolved_project_id' => null,
            'user_project_access_id' => null,
        ]);

        $this->assertDatabaseCount('user_project_access', 0);
    }
}
