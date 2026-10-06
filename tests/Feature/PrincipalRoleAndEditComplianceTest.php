<?php

namespace Tests\Feature;

use App\Mail\NewAccountCredentialsMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PrincipalRoleAndEditComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $hrUser;
    protected Employee $testEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();

        // HR User with USER_CREATE and ACCESS_VIEW
        $hrEmp = Employee::create([
            'employee_code' => 'EMP_HR_TEST',
            'f_name' => 'HR',
            'l_name' => 'Test',
            'full_name' => 'HR Test Officer',
            'name_with_initials' => 'H. Test',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '923456789V',
            'date_of_birth' => '1992-02-02',
            'email' => 'hr_test@example.com',
            'phone' => '+94112345678',
            'phone_primary' => '+94771234567',
            'address_line_1' => 'Test St',
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

        $this->hrUser = User::create([
            'employee_code' => 'EMP_HR_TEST',
            'name' => 'HR Test Officer',
            'username' => 'hr_test_actor',
            'email' => 'hr_test@example.com',
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'must_change_password' => false,
        ]);
        $this->hrUser->syncRoles(['HR']);
        $this->hrUser->givePermissionTo(['EMPLOYEE_CREATE', 'EMPLOYEE_VIEW', 'EMPLOYEE_UPDATE', 'USER_VIEW']);

        // Base test employee
        $this->testEmployee = Employee::create([
            'employee_code' => 'EMP_COMP_01',
            'f_name' => 'Compliance',
            'l_name' => 'Subject',
            'full_name' => 'Compliance Subject',
            'name_with_initials' => 'C. Subject',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '934567890V',
            'date_of_birth' => '1993-03-03',
            'email' => 'compliance01@example.com',
            'phone' => '+94112345678',
            'phone_primary' => '+94772345678',
            'address_line_1' => 'Test Ave',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'is_active' => true,
            'version' => 1,
        ]);
        $this->testEmployee->refresh();
    }

    /**
     * TEST 1: Create Employee only -> no linked User row created.
     */
    public function test_1_create_employee_only_creates_no_linked_user(): void
    {
        $this->actingAs($this->hrUser, 'web');

        $response = $this->postJson('/api/v1/employees', [
            'employee_code' => 'EMP_ONLY_01',
            'f_name' => 'Employee',
            'l_name' => 'Only',
            'full_name' => 'Employee Only',
            'name_with_initials' => 'E. Only',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '889977665V',
            'date_of_birth' => '1995-05-05',
            'email' => 'emponly@example.com',
            'phone' => '+94112345678',
            'phone_primary' => '+94773456789',
            'address_line_1' => 'Street 1',
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

        $response->assertStatus(201);
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP_ONLY_01']);
        $this->assertDatabaseMissing('users', ['employee_code' => 'EMP_ONLY_01']);
    }

    /**
     * TEST 2: Create Principal without roles -> validation error 422, no partial records.
     */
    public function test_2_create_principal_without_roles_fails_validation(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_subject_01',
            'email' => $this->testEmployee->email,
            // roles is omitted entirely
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['roles']);

        $this->assertDatabaseMissing('users', ['username' => 'comp_subject_01']);
    }

    /**
     * TEST 3: Super Admin explicitly selects Staff -> Principal created with exactly Staff Spatie role.
     */
    public function test_3_super_admin_explicitly_selects_staff_role(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_staff_01',
            'email' => $this->testEmployee->email,
            'roles' => ['Staff'],
        ]);

        $response->assertStatus(201);

        $user = User::where('username', 'comp_staff_01')->firstOrFail();
        $this->assertTrue($user->hasRole('Staff'));
        $this->assertSame(['Staff'], $user->roles->pluck('name')->toArray());
    }

    /**
     * TEST 4: Confirm user_type=staff alone does NOT cause Staff Spatie role assignment.
     */
    public function test_4_user_type_staff_alone_does_not_cause_staff_spatie_role(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_hr_only',
            'email' => $this->testEmployee->email,
            'user_type' => 'staff', // account type is staff
            'roles' => ['HR'],      // but role explicitly selected is HR
        ]);

        $response->assertStatus(201);

        $user = User::where('username', 'comp_hr_only')->firstOrFail();
        $this->assertSame('staff', $user->user_type);
        $this->assertTrue($user->hasRole('HR'));
        $this->assertFalse($user->hasRole('Staff'));
    }

    /**
     * TEST 5: Cannot assign non-existent GIAM role -> 422 rejected.
     */
    public function test_5_cannot_assign_non_existent_giam_role(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_fake_role',
            'email' => $this->testEmployee->email,
            'roles' => ['NonExistentRole999'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0']);
    }

    /**
     * TEST 6: Unauthorized actor cannot assign Super Admin role -> 403 rejected.
     */
    public function test_6_unauthorized_actor_cannot_assign_super_admin_role(): void
    {
        $this->hrUser->givePermissionTo('USER_CREATE');
        $this->actingAs($this->hrUser, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_escalate',
            'email' => $this->testEmployee->email,
            'roles' => ['Super Admin'],
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Privilege escalation rejected', $response->json('message') ?? '');
    }

    /**
     * TEST 7: GIAM Role (Staff) and Project Role (Warehouse Staff) remain completely independent.
     */
    public function test_7_giam_role_and_project_role_remain_independent(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        // Create GIAM Principal with GIAM role Staff
        $userResponse = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_dual_roles',
            'email' => $this->testEmployee->email,
            'roles' => ['Staff'],
        ]);
        $userResponse->assertStatus(201);
        $user = User::where('username', 'comp_dual_roles')->firstOrFail();

        // Assign project role from Centrix
        $centrixProject = Project::first();
        $projectRole = ProjectRole::firstOrCreate(
            ['project_id' => $centrixProject->id, 'code' => 'WAREHOUSE_STAFF'],
            [
                'name' => 'Warehouse Staff',
                'external_role_id' => 101,
                'is_active' => true,
            ]
        );

        $assignResponse = $this->postJson("/api/v1/users/{$user->id}/project-access", [
            'project_id' => $centrixProject->id,
            'role_ids' => [$projectRole->id],
        ]);
        $assignResponse->assertStatus(200);

        // Verify GIAM Spatie role is STILL ONLY 'Staff'
        $freshUser = $user->fresh(['roles']);
        $this->assertSame(['Staff'], $freshUser->roles->pluck('name')->toArray());

        // Verify project access role belongs to downstream project
        $this->assertDatabaseHas('user_project_roles', [
            'project_role_id' => $projectRole->id,
        ]);
    }

    /**
     * TEST 8: Change Employee official email for linked Principal -> employees.email and users.email both updated atomically.
     */
    public function test_8_change_employee_official_email_updates_linked_user_atomically(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $this->testEmployee->refresh();

        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_sync_mail',
            'email' => $this->testEmployee->email,
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);
        $user->syncRoles(['Staff']);

        $updateResponse = $this->putJson("/api/v1/employees/{$this->testEmployee->id}", [
            'email' => 'new_official_sync@example.com',
            'version' => $this->testEmployee->version ?? 1,
        ]);

        $updateResponse->assertStatus(200);

        $this->assertSame('new_official_sync@example.com', $this->testEmployee->fresh()->email);
        $this->assertSame('new_official_sync@example.com', $user->fresh()->email);
    }

    /**
     * TEST 9: Duplicate users.email update -> rejected (422), no partial divergence.
     */
    public function test_9_duplicate_users_email_update_rejected_without_divergence(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $this->testEmployee->refresh();

        // Existing employee and user taking the target email
        Employee::create([
            'employee_code' => 'EMP_COLLIDE',
            'f_name' => 'Existing',
            'l_name' => 'Collide',
            'full_name' => 'Existing Collide',
            'name_with_initials' => 'E. Collide',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '941234567V',
            'date_of_birth' => '1994-01-01',
            'email' => 'occupied@example.com',
            'phone' => '+94112345678',
            'phone_primary' => '+94771234567',
            'address_line_1' => 'Street',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
            'is_active' => true,
            'version' => 1,
        ]);

        User::create([
            'employee_code' => 'EMP_COLLIDE',
            'name' => 'Existing Collide',
            'username' => 'existing_collide',
            'email' => 'occupied@example.com',
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);

        // Linked employee and user
        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_no_diverge',
            'email' => $this->testEmployee->email,
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);

        $origEmail = $this->testEmployee->email;

        $updateResponse = $this->putJson("/api/v1/employees/{$this->testEmployee->id}", [
            'email' => 'occupied@example.com',
            'version' => $this->testEmployee->version ?? 1,
        ]);

        $updateResponse->assertStatus(422);

        // Neither record must have changed
        $this->assertSame($origEmail, $this->testEmployee->fresh()->email);
        $this->assertSame($origEmail, $user->fresh()->email);
    }

    /**
     * TEST 10: employee_code cannot be changed through edit/update.
     */
    public function test_10_employee_code_cannot_be_changed_through_edit_update(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $this->testEmployee->refresh();

        // Attempt on Employee
        $empResponse = $this->putJson("/api/v1/employees/{$this->testEmployee->id}", [
            'employee_code' => 'HACKED_EMP_CODE',
            'version' => $this->testEmployee->version ?? 1,
        ]);
        $empResponse->assertStatus(422);
        $this->assertSame('EMP_COMP_01', $this->testEmployee->fresh()->employee_code);

        // Attempt on User
        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_code_guard',
            'email' => $this->testEmployee->email,
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);
        $user->refresh();

        $userResponse = $this->putJson("/api/v1/users/{$user->id}", [
            'employee_code' => 'HACKED_USER_CODE',
            'version' => $user->version ?? 1,
        ]);
        $userResponse->assertStatus(422);
        $this->assertSame('EMP_COMP_01', $user->fresh()->employee_code);
    }

    /**
     * TEST 11: Allowed changed user fields create correct downstream update/sync job.
     */
    public function test_11_allowed_changed_user_fields_create_downstream_sync_job(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $this->testEmployee->refresh();

        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_downstream_sync',
            'email' => $this->testEmployee->email,
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);
        $user->syncRoles(['Staff']);

        $centrixProject = Project::first();
        UserProjectAccess::create([
            'user_id' => $user->id,
            'project_id' => $centrixProject->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
        ]);

        // Update employee official email
        $response = $this->putJson("/api/v1/employees/{$this->testEmployee->id}", [
            'email' => 'propagated_sync@example.com',
            'version' => $this->testEmployee->version ?? 1,
        ]);
        $response->assertStatus(200);

        $syncJob = SyncJob::where('user_id', $user->id)
            ->where('project_id', $centrixProject->id)
            ->where('operation', 'UPDATE_USER')
            ->latest('id')
            ->first();

        $this->assertNotNull($syncJob, 'SyncJob with operation UPDATE_USER must be created.');
        $this->assertSame('propagated_sync@example.com', $syncJob->payload['email'] ?? null);
    }

    /**
     * TEST 12: Sensitive/non-allowed Employee fields are not included in Centrix projection.
     */
    public function test_12_sensitive_fields_not_in_centrix_projection(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $this->testEmployee->refresh();

        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_projection_check',
            'email' => $this->testEmployee->email,
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);
        $user->syncRoles(['Staff']);

        $centrixProject = Project::first();
        if ($centrixProject->integration) {
            $centrixProject->integration->update([
                'allowed_user_fields' => ['email', 'full_name', 'name'],
            ]);
        }

        UserProjectAccess::create([
            'user_id' => $user->id,
            'project_id' => $centrixProject->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'assigned_at' => now(),
        ]);

        $this->putJson("/api/v1/employees/{$this->testEmployee->id}", [
            'email' => 'projection_safe@example.com',
            'address_line_1' => 'Confidential Address Line',
            'version' => $this->testEmployee->version ?? 1,
        ]);

        $syncJob = SyncJob::where('user_id', $user->id)
            ->where('operation', 'UPDATE_USER')
            ->latest('id')
            ->firstOrFail();

        // Sensitive/private fields not in allowed_user_fields must not leak into projection
        $this->assertArrayNotHasKey('id_number', $syncJob->payload);
        $this->assertArrayNotHasKey('date_of_birth', $syncJob->payload);
        $this->assertArrayNotHasKey('address_line_1', $syncJob->payload);
    }

    /**
     * TEST 13: USER_UPDATED audit log contains before/after safely without credentials.
     */
    public function test_13_user_updated_audit_log_contains_safe_data(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $user = User::create([
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Before Update Name',
            'username' => 'comp_audit_safe',
            'email' => $this->testEmployee->email,
            'password' => Hash::make('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);
        $user->refresh();

        $response = $this->putJson("/api/v1/users/{$user->id}", [
            'name' => 'After Update Name',
            'version' => $user->version ?? 1,
        ]);
        $response->assertStatus(200);

        $log = AuditLog::where('action', 'USER_UPDATED')
            ->where('entity_id', (string) $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Before Update Name', $log->before_data['name'] ?? null);
        $this->assertSame('After Update Name', $log->after_data['name'] ?? null);
        $this->assertArrayNotHasKey('raw_password', $log->after_data);
    }

    /**
     * TEST 14: New Principal creation triggers the new-account credentials mail.
     */
    public function test_14_new_principal_creation_triggers_credentials_mail(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_mail_trigger',
            'email' => $this->testEmployee->email,
            'roles' => ['Staff'],
        ]);
        $response->assertStatus(201);

        Mail::assertSent(NewAccountCredentialsMail::class, function ($mail) {
            return $mail->user->username === 'comp_mail_trigger'
                && $mail->hasTo('compliance01@example.com');
        });
    }

    /**
     * TEST 15: Mail contains username, temporary password, but plaintext password is not in DB or API response.
     */
    public function test_15_plaintext_password_not_in_db_or_api_response(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_secret_safe',
            'email' => $this->testEmployee->email,
            'roles' => ['Staff'],
        ]);
        $response->assertStatus(201);

        // Plaintext password MUST NOT be in API JSON response
        $response->assertJsonMissing(['password' => 'ChangeMe@123']);
        $this->assertArrayNotHasKey('raw_password', $response->json('data') ?? []);

        // Plaintext password MUST NOT be in DB
        $user = User::where('username', 'comp_secret_safe')->firstOrFail();
        $this->assertStringStartsWith('$2y$', $user->password); // bcrypt hash

        // Mail contains the temporary password safely
        Mail::assertSent(NewAccountCredentialsMail::class, function ($mail) {
            return ! empty($mail->temporaryPassword) && $mail->user->username === 'comp_secret_safe';
        });
    }

    /**
     * TEST 16: must_change_password = true for temporary account password.
     */
    public function test_16_must_change_password_is_true_for_temporary_password(): void
    {
        Mail::fake();
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson('/api/v1/users', [
            'employee_code' => $this->testEmployee->employee_code,
            'name' => 'Compliance Subject',
            'username' => 'comp_change_pw',
            'email' => $this->testEmployee->email,
            'roles' => ['Staff'],
        ]);
        $response->assertStatus(201);

        $user = User::where('username', 'comp_change_pw')->firstOrFail();
        $this->assertTrue((bool) $user->must_change_password);
    }
}
