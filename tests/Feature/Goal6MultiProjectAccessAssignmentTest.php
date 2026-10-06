<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectPermission;
use App\Models\ProjectPermissionGroup;
use App\Models\ProjectRole;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\UserProjectAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Goal6MultiProjectAccessAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $targetUser;
    protected Project $hrms;
    protected Project $centrix;
    protected ProjectRole $hrmsRole;
    protected ProjectRole $centrixRole;
    protected ProjectPermission $hrmsPerm;
    protected ProjectPermission $centrixPerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();
        $this->hrms = Project::where('code', 'hrms')->first();
        $this->centrix = Project::where('code', 'centrix')->first();

        // Create target user for testing project access
        $employee = Employee::create([
            'employee_code' => 'EMP6001',
            'f_name' => 'Alice',
            'l_name' => 'Wonderland',
            'full_name' => 'Alice Wonderland',
            'name_with_initials' => 'A. Wonderland',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '965554443V',
            'date_of_birth' => '1996-05-05',
            'email' => 'alice@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Wonderland Way',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->targetUser = User::create([
            'employee_code' => 'EMP6001',
            'name' => 'Alice Wonderland',
            'username' => 'alice6001',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        // Seed HRMS catalog items
        $this->hrmsRole = ProjectRole::create([
            'project_id' => $this->hrms->id,
            'external_role_id' => 'hrms_officer',
            'code' => 'hrms_officer',
            'name' => 'HRMS Officer',
            'is_active' => true,
        ]);

        $hrmsGroup = ProjectPermissionGroup::create([
            'project_id' => $this->hrms->id,
            'external_group_id' => 'hrms_records',
            'code' => 'hrms_records',
            'name' => 'HRMS Records',
            'is_active' => true,
        ]);

        $this->hrmsPerm = ProjectPermission::create([
            'project_id' => $this->hrms->id,
            'project_permission_group_id' => $hrmsGroup->id,
            'external_permission_id' => 'view_records',
            'code' => 'view_records',
            'name' => 'View Employee Records',
            'is_active' => true,
        ]);

        // Seed CENTRIX catalog items
        $this->centrixRole = ProjectRole::create([
            'project_id' => $this->centrix->id,
            'external_role_id' => 'centrix_agent',
            'code' => 'centrix_agent',
            'name' => 'Centrix Agent',
            'is_active' => true,
        ]);

        $centrixGroup = ProjectPermissionGroup::create([
            'project_id' => $this->centrix->id,
            'external_group_id' => 'centrix_crm',
            'code' => 'centrix_crm',
            'name' => 'Centrix CRM',
            'is_active' => true,
        ]);

        $this->centrixPerm = ProjectPermission::create([
            'project_id' => $this->centrix->id,
            'project_permission_group_id' => $centrixGroup->id,
            'external_permission_id' => 'crm_access',
            'code' => 'crm_access',
            'name' => 'CRM Access',
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Cross-project role assignment is strictly rejected (HTTP 422).
     */
    public function test_cross_project_role_assignment_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Attempt to assign CENTRIX role to HRMS project assignment
        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->centrixRole->id], // INVALID: Belongs to CENTRIX!
        ]);

        $response->assertStatus(422)
            ->assertSee('Cross-project isolation violation');

        $this->assertDatabaseMissing('user_project_access', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->hrms->id,
        ]);
    }

    /**
     * Test 2: Cross-project permission assignment is strictly rejected (HTTP 422).
     */
    public function test_cross_project_permission_assignment_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Attempt to assign CENTRIX permission to HRMS project assignment
        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'permission_ids' => [$this->centrixPerm->id], // INVALID: Belongs to CENTRIX!
        ]);

        $response->assertStatus(422)
            ->assertSee('Cross-project isolation violation');
    }

    /**
     * Test 3: Local access mutation and outbox sync_jobs committed atomically.
     */
    public function test_local_access_and_outbox_sync_job_written_in_same_transaction(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Prevent any unexpected outgoing HTTP requests to verify zero external calls occur inside transaction
        Http::preventStrayRequests();

        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
            'permission_ids' => [$this->hrmsPerm->id],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'user_id' => $this->targetUser->id,
                    'project_id' => $this->hrms->id,
                    'status' => 'PENDING',
                    'version' => 1,
                ],
            ]);

        // 1. Verify user_project_access created
        $this->assertDatabaseHas('user_project_access', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->hrms->id,
            'status' => 'PENDING',
            'version' => 1,
        ]);

        // 2. Verify roles and permissions mapped
        $access = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->hrms->id)
            ->first();

        $this->assertDatabaseHas('user_project_roles', [
            'user_project_access_id' => $access->id,
            'project_role_id' => $this->hrmsRole->id,
        ]);

        $this->assertDatabaseHas('user_project_permissions', [
            'user_project_access_id' => $access->id,
            'project_permission_id' => $this->hrmsPerm->id,
        ]);

        // 3. Verify transactional outbox sync_jobs record created with status PENDING
        $this->assertDatabaseHas('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'status' => 'PENDING',
        ]);

        $syncJob = SyncJob::where('user_id', $this->targetUser->id)->first();
        $this->assertNotNull($syncJob->payload);
        $this->assertEquals(['hrms_officer'], $syncJob->payload['roles']);
        $this->assertEquals(['view_records'], $syncJob->payload['permissions']);
    }

    /**
     * Test 4: Re-assigning access to already assigned project updates version and resets status to PENDING.
     */
    public function test_reassigning_access_updates_version_and_resets_status_to_pending(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Initial assignment
        $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
        ])->assertStatus(200);

        $access = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->hrms->id)
            ->first();
        $this->assertEquals(1, $access->version);

        // Simulate that background worker previously marked it ACTIVE
        UserProjectAccess::where('id', $access->id)->update(['status' => 'ACTIVE']);

        // Update assignment with expected version 1
        $updateResponse = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
            'permission_ids' => [$this->hrmsPerm->id],
            'version' => 1,
        ]);

        $updateResponse->assertStatus(200)
            ->assertJson([
                'data' => [
                    'version' => 2,
                    'status' => 'PENDING', // Reset to PENDING for outbox synchronization
                ],
            ]);

        $access->refresh();
        $this->assertEquals(2, $access->version);
        $this->assertEquals('PENDING', $access->status);

        // Outbox record for ASSIGN_ACCESS created
        $this->assertDatabaseHas('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->hrms->id,
            'operation' => 'ASSIGN_ACCESS',
            'status' => 'PENDING',
        ]);
    }

    /**
     * Test 5: Optimistic locking conflict on project access update (HTTP 409).
     */
    public function test_optimistic_locking_conflict_on_access_update(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Initial assignment
        $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
        ])->assertStatus(200);

        // Update sending wrong version (e.g. 99 instead of 1) returns HTTP 409
        $conflictResp = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
            'version' => 99,
        ]);

        $conflictResp->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'error' => 'CONFLICT',
            ]);
    }

    /**
     * Test 6: Revoking project access sets status to REVOKED and creates outbox job.
     */
    public function test_revoking_access_sets_status_revoked_and_creates_outbox_job(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Assign access first
        $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
        ])->assertStatus(200);

        // Revoke access
        $revokeResp = $this->deleteJson("/api/v1/users/{$this->targetUser->id}/project-access/{$this->hrms->id}", [
            'reason' => 'Transfer to another division',
            'version' => 1,
        ]);

        $revokeResp->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'REVOKED',
                    'version' => 2,
                    'revocation_reason' => 'Transfer to another division',
                ],
            ]);

        $this->assertDatabaseHas('user_project_access', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->hrms->id,
            'status' => 'REVOKED',
        ]);

        $this->assertDatabaseHas('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->hrms->id,
            'operation' => 'REVOKE_ACCESS',
            'status' => 'PENDING',
        ]);
    }

    /**
     * Test 7: Inactive user cannot be assigned project access (HTTP 422).
     */
    public function test_inactive_user_cannot_be_assigned_project_access(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $this->targetUser->update(['is_active' => false]);

        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->hrms->id,
            'role_ids' => [$this->hrmsRole->id],
        ]);

        $response->assertStatus(422)
            ->assertSee('inactive');
    }

    /**
     * Test 8: Server-side allowlist projection strictly filters sensitive employee fields.
     */
    public function test_server_side_allowlist_projection_filters_sensitive_fields(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        // Configure CENTRIX allowlist to only include employee_code, full_name, email, phone_primary
        $this->centrix->integration->update([
            'allowed_user_fields' => ['employee_code', 'full_name', 'email', 'phone_primary'],
        ]);

        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->centrixRole->id],
        ]);
        $response->assertStatus(200);

        $syncJob = SyncJob::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->first();

        $payload = $syncJob->payload;

        // Included fields
        $this->assertArrayHasKey('externalRef', $payload);
        $this->assertArrayHasKey('full_name', $payload);
        $this->assertArrayHasKey('email', $payload);
        $this->assertArrayHasKey('phone_primary', $payload);

        // Strictly EXCLUDED sensitive fields
        $this->assertArrayNotHasKey('id_number', $payload);
        $this->assertArrayNotHasKey('date_of_birth', $payload);
        $this->assertArrayNotHasKey('address_line_1', $payload);
    }
}
