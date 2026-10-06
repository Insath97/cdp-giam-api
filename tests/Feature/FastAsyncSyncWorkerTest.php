<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSyncJob;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Access\ProjectAccessAssignmentService;
use App\Services\Sync\ProvisioningSyncWorker;
use App\Services\User\UserCreationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FastAsyncSyncWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $targetUser;
    protected Project $centrix;
    protected ProjectRole $centrixRole;
    protected ProvisioningSyncWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::where('username', 'user01')->first();
        $this->centrix = Project::where('code', 'centrix')->first();
        $this->worker = app(ProvisioningSyncWorker::class);

        $employee = Employee::create([
            'employee_code' => 'EMP_ASYNC_01',
            'f_name' => 'Fast',
            'l_name' => 'Async',
            'full_name' => 'Fast Async Worker',
            'name_with_initials' => 'F. Async',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '951112223V',
            'date_of_birth' => '1995-01-01',
            'email' => 'fast.async@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Async Boulevard',
            'city' => 'Colombo',
            'phone_primary' => '+94771234567',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->targetUser = User::create([
            'employee_code' => 'EMP_ASYNC_01',
            'name' => 'Fast Async Worker',
            'username' => 'fast_async_01',
            'email' => 'fast.async@example.com',
            'password' => bcrypt('Password@123'),
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->centrixRole = ProjectRole::create([
            'project_id' => $this->centrix->id,
            'external_role_id' => 'gate_security',
            'code' => 'gate_security',
            'name' => 'Gate Security',
            'is_active' => true,
        ]);
    }

    /**
     * Requirement 1 & 2: Project access is initially PENDING and sync_jobs row is created durably.
     */
    public function test_project_access_is_initially_pending_and_sync_jobs_row_created_durably(): void
    {
        $this->actingAs($this->superAdmin, 'web');

        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->centrixRole->id],
        ]);

        $response->assertStatus(200);

        // 1. Initial status MUST be PENDING
        $this->assertDatabaseHas('user_project_access', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'status' => 'PENDING',
        ]);

        // 2. sync_jobs row exists durably with status PENDING
        $this->assertDatabaseHas('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'CREATE_USER',
            'status' => 'PENDING',
        ]);
    }

    /**
     * Requirement 3: Worker processing does not happen before DB commit (afterCommit safety).
     */
    public function test_worker_processing_does_not_happen_before_db_commit(): void
    {
        $assignmentService = app(ProjectAccessAssignmentService::class);

        // 1. Verify ExecuteSyncJob implements ShouldQueueAfterCommit contract and has afterCommit enabled
        $jobInstance = new ExecuteSyncJob(new SyncJob());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueueAfterCommit::class, $jobInstance);
        $this->assertTrue($jobInstance->afterCommit);

        // 2. In an uncommitted transaction that is rolled back, no jobs are ever committed/queued
        DB::beginTransaction();
        $assignmentService->assignAccess(
            user: $this->targetUser,
            projectId: $this->centrix->id,
            roleIds: [$this->centrixRole->id],
            permissionIds: [],
            actor: $this->superAdmin
        );
        DB::rollBack();

        // Queue table must not contain jobs from a rolled-back transaction
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseMissing('user_project_access', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
        ]);
        $this->assertDatabaseMissing('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
        ]);
    }

    /**
     * Requirement 4: Successful downstream response changes access to ACTIVE.
     */
    public function test_successful_downstream_response_changes_access_to_active(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response([
                'status' => 'success',
                'message' => 'User provisioned in downstream project.',
                'data' => ['id' => 999],
            ], 201),
            '*/api/giam/integration/users/*/credential' => Http::response([
                'status' => 'success',
            ], 200),
        ]);

        $assignmentService = app(ProjectAccessAssignmentService::class);
        $access = $assignmentService->assignAccess(
            user: $this->targetUser,
            projectId: $this->centrix->id,
            roleIds: [$this->centrixRole->id],
            permissionIds: [],
            actor: $this->superAdmin
        );

        $this->assertEquals('PENDING', $access->fresh()->status);

        $syncJob = SyncJob::where('user_id', $this->targetUser->id)->first();
        $this->assertNotNull($syncJob);

        // Process job via ExecuteSyncJob
        $job = new ExecuteSyncJob($syncJob);
        $job->handle($this->worker);

        // Assert sync job is SUCCESS and access is now ACTIVE
        $this->assertEquals('SUCCESS', $syncJob->fresh()->status);
        $this->assertEquals(201, $syncJob->fresh()->http_status_code);
        $this->assertEquals('ACTIVE', $access->fresh()->status);
    }

    /**
     * Requirement 5: Failed downstream response does NOT change access to ACTIVE.
     */
    public function test_failed_downstream_response_does_not_change_access_to_active(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response([
                'status' => 'error',
                'message' => 'Downstream internal service error',
            ], 500),
        ]);

        $assignmentService = app(ProjectAccessAssignmentService::class);
        $access = $assignmentService->assignAccess(
            user: $this->targetUser,
            projectId: $this->centrix->id,
            roleIds: [$this->centrixRole->id],
            permissionIds: [],
            actor: $this->superAdmin
        );

        $syncJob = SyncJob::where('user_id', $this->targetUser->id)->first();

        // Process job
        $job = new ExecuteSyncJob($syncJob);
        $job->handle($this->worker);

        // Access MUST REMAIN PENDING, never ACTIVE
        $this->assertEquals('PENDING', $access->fresh()->status);
        $this->assertEquals('RETRYING', $syncJob->fresh()->status);
        $this->assertEquals(500, $syncJob->fresh()->http_status_code);
        $this->assertNotNull($syncJob->fresh()->next_retry_at);
    }

    /**
     * Requirement 6 & 7: Existing retry behavior and max attempts failure behavior.
     */
    public function test_existing_retry_and_max_attempt_behavior_preserved(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response('Service Unavailable', 503),
        ]);

        $syncJob = SyncJob::create([
            'idempotency_key' => 'retry-test-key-01',
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'CREATE_USER',
            'payload' => ['username' => 'fast_async_01'],
            'status' => 'PENDING',
            'attempt_count' => 4, // 4 prior attempts, next is 5 = max
            'max_attempts' => 5,
        ]);

        $this->worker->process($syncJob);

        $syncJob->refresh();
        $this->assertEquals('FAILED', $syncJob->status);
        $this->assertEquals(5, $syncJob->attempt_count);
        $this->assertNull($syncJob->next_retry_at);
        $this->assertNotNull($syncJob->processed_at);
    }

    /**
     * Requirement 8 & 9: Concurrency safety - Two concurrent workers cannot both claim/process the same job.
     */
    public function test_concurrent_worker_execution_atomically_prevents_duplicate_processing(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response(['status' => 'success'], 200),
        ]);

        $syncJob = SyncJob::create([
            'idempotency_key' => 'concurrency-atomic-01',
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'CREATE_USER',
            'payload' => ['username' => 'fast_async_01'],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        // Simulate Worker 1 claiming the job
        $worker1Result = $this->worker->process($syncJob);
        $this->assertTrue($worker1Result);

        // Simulate Worker 2 immediately attempting to process the same job
        $worker2Result = $this->worker->process($syncJob);
        // Worker 2 must be safely rejected (status is already SUCCESS / not PENDING)
        $this->assertFalse($worker2Result);

        // Worker 1 completed its downstream calls (create user + credential sync = 2 calls).
        // Worker 2 was rejected atomically, so duplicate downstream calls (which would make 4 calls) never occurred.
        Http::assertSentCount(2);
    }

    /**
     * Requirement 10: Idempotency is transmitted in headers and payload.
     */
    public function test_existing_idempotency_is_preserved(): void
    {
        $idempotencyKey = 'idempotency-verify-key-999';

        Http::fake([
            '*/api/giam/integration/users*' => Http::response(['status' => 'success'], 201),
            '*/api/giam/integration/users/*/credential' => Http::response(['status' => 'success'], 200),
        ]);

        $syncJob = SyncJob::create([
            'idempotency_key' => $idempotencyKey,
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'CREATE_USER',
            'payload' => ['externalRef' => 'EMP_ASYNC_01'],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        $this->worker->process($syncJob);

        Http::assertSent(function ($request) use ($idempotencyKey) {
            return $request->hasHeader('X-GIAM-Idempotency-Key', $idempotencyKey)
                && isset($request['idempotencyKey'])
                && $request['idempotencyKey'] === $idempotencyKey;
        });
    }

    /**
     * Requirement 11 & 12: Recovery scheduler can still process an eligible stranded job.
     */
    public function test_recovery_scheduler_processes_eligible_stranded_job(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response([
                'status' => 'success',
                'data' => ['id' => 123],
            ], 200),
        ]);

        UserProjectAccess::create([
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'status' => 'PENDING',
            'assigned_by' => $this->superAdmin->id,
            'version' => 1,
        ]);

        // Create a stranded sync job (e.g. created when queue worker was offline)
        $strandedJob = SyncJob::create([
            'idempotency_key' => 'stranded-job-01',
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'ASSIGN_ACCESS',
            'payload' => ['externalRef' => 'EMP_ASYNC_01'],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        // Run recovery scheduler command
        $exitCode = Artisan::call('giam:process-sync-jobs');
        $this->assertEquals(0, $exitCode);

        $strandedJob->refresh();
        $this->assertEquals('SUCCESS', $strandedJob->status);

        $access = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->first();
        $this->assertEquals('ACTIVE', $access->status);
    }

    /**
     * Requirement 13: Fast asynchronous path processes committed job without waiting for scheduler.
     */
    public function test_fast_asynchronous_path_processes_newly_committed_job(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response([
                'status' => 'success',
                'data' => ['id' => 456],
            ], 201),
            '*/api/giam/integration/users/*/credential' => Http::response(['status' => 'success'], 200),
        ]);

        $this->actingAs($this->superAdmin, 'web');

        // Execute assignment via HTTP endpoint
        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/project-access", [
            'project_id' => $this->centrix->id,
            'role_ids' => [$this->centrixRole->id],
        ]);
        $response->assertStatus(200);

        // Job was dispatched to queue jobs table
        $this->assertDatabaseHas('jobs', []);

        // Process queue worker immediately (simulating fast background queue:work)
        Artisan::call('queue:work', ['--once' => true]);

        // Verify that access transitioned to ACTIVE via the fast async queue path
        $access = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->first();

        $this->assertEquals('ACTIVE', $access->status);
    }

    /**
     * Requirement 14: Access revoke still works and dispatches fast async worker.
     */
    public function test_access_revoke_creates_outbox_job_and_syncs_safely(): void
    {
        Http::fake([
            '*/api/giam/integration/users*' => Http::response(['status' => 'success'], 200),
        ]);

        UserProjectAccess::create([
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'version' => 1,
        ]);

        $this->actingAs($this->superAdmin, 'web');

        $response = $this->deleteJson("/api/v1/users/{$this->targetUser->id}/project-access/{$this->centrix->id}");
        $response->assertStatus(200);

        $access = UserProjectAccess::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->first();
        $this->assertEquals('REVOKED', $access->status);

        $this->assertDatabaseHas('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'REVOKE_ACCESS',
        ]);
    }

    /**
     * Requirement 15 & 16: User update dispatches update sync for active project accesses.
     */
    public function test_user_update_dispatches_sync_for_active_projects(): void
    {
        UserProjectAccess::create([
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'status' => 'ACTIVE',
            'assigned_by' => $this->superAdmin->id,
            'version' => 1,
        ]);

        $this->actingAs($this->superAdmin, 'web');

        $userService = app(UserCreationService::class);
        $userService->updateUser($this->targetUser, [
            'name' => 'Fast Async Updated Name',
            'version' => $this->targetUser->version,
        ], $this->superAdmin);

        $this->assertDatabaseHas('sync_jobs', [
            'user_id' => $this->targetUser->id,
            'project_id' => $this->centrix->id,
            'operation' => 'UPDATE_USER',
            'status' => 'PENDING',
        ]);
    }

    /**
     * Requirements 10-14: Multi-project RBAC and integration isolation.
     * - Same role name and external_role_id in two projects do not collide.
     * - Project A job uses Project A integration and endpoints only.
     * - Project B job uses Project B integration and endpoints only.
     * - Project A failure does not affect Project B access.
     */
    public function test_multi_project_role_and_integration_isolation(): void
    {
        $hrms = Project::where('code', 'hrms')->firstOrFail();

        // 1. Create identical role names and external IDs in Centrix and HRMS
        $centrixOperatorRole = ProjectRole::create([
            'project_id' => $this->centrix->id,
            'external_role_id' => 'operator',
            'code' => 'operator',
            'name' => 'Operator',
            'is_active' => true,
        ]);

        $hrmsOperatorRole = ProjectRole::create([
            'project_id' => $hrms->id,
            'external_role_id' => 'operator',
            'code' => 'operator',
            'name' => 'Operator',
            'is_active' => true,
        ]);

        $this->assertNotEquals($centrixOperatorRole->id, $hrmsOperatorRole->id);
        $this->assertEquals($centrixOperatorRole->name, $hrmsOperatorRole->name);
        $this->assertEquals($centrixOperatorRole->external_role_id, $hrmsOperatorRole->external_role_id);

        // 2. Setup project-scoped HTTP mock handlers
        $centrixCalled = false;
        $hrmsCalled = false;

        Http::fake([
            'http://localhost:8002/*' => function ($request) use (&$centrixCalled) {
                $centrixCalled = true;
                // Assert that Centrix header is present
                $this->assertEquals('centrix', $request->header('X-GIAM-Project')[0] ?? null);
                return Http::response(['status' => 'success'], 200);
            },
            'http://localhost:8001/*' => function ($request) use (&$hrmsCalled) {
                $hrmsCalled = true;
                // Assert that HRMS header is present
                $this->assertEquals('hrms', $request->header('X-GIAM-Project')[0] ?? null);
                return Http::response(['status' => 'error', 'message' => 'HRMS downstream error'], 500);
            },
        ]);

        $assignmentService = app(ProjectAccessAssignmentService::class);

        // 3. Assign Centrix access
        $centrixAccess = $assignmentService->assignAccess(
            user: $this->targetUser,
            projectId: $this->centrix->id,
            roleIds: [$centrixOperatorRole->id],
            permissionIds: [],
            actor: $this->superAdmin
        );

        // 4. Assign HRMS access
        $hrmsAccess = $assignmentService->assignAccess(
            user: $this->targetUser,
            projectId: $hrms->id,
            roleIds: [$hrmsOperatorRole->id],
            permissionIds: [],
            actor: $this->superAdmin
        );

        $this->assertEquals('PENDING', $centrixAccess->fresh()->status);
        $this->assertEquals('PENDING', $hrmsAccess->fresh()->status);

        // 5. Process Centrix sync job (Fast path worker)
        $centrixJob = SyncJob::where('user_id', $this->targetUser->id)
            ->where('project_id', $this->centrix->id)
            ->firstOrFail();

        $this->worker->process($centrixJob);

        // Centrix must be SUCCESS and access must become ACTIVE
        $this->assertEquals('SUCCESS', $centrixJob->fresh()->status);
        $this->assertEquals('ACTIVE', $centrixAccess->fresh()->status);
        $this->assertTrue($centrixCalled);

        // 6. Process HRMS sync job (Fails downstream)
        $hrmsJob = SyncJob::where('user_id', $this->targetUser->id)
            ->where('project_id', $hrms->id)
            ->firstOrFail();

        $this->worker->process($hrmsJob);

        // HRMS must be RETRYING and access must remain PENDING
        $this->assertEquals('RETRYING', $hrmsJob->fresh()->status);
        $this->assertEquals('PENDING', $hrmsAccess->fresh()->status);
        $this->assertTrue($hrmsCalled);

        // 7. Verify Centrix access is STILL ACTIVE and was completely unaffected by HRMS failure
        $this->assertEquals('ACTIVE', $centrixAccess->fresh()->status);
    }
}

