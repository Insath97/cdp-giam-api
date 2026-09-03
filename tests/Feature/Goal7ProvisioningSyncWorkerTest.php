<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Sync\DataProjectionService;
use App\Services\Sync\ProvisioningSyncWorker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Goal7ProvisioningSyncWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $admin;
    protected Project $hrms;
    protected ProjectRole $hrmsRole;
    protected ProvisioningSyncWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('username', 'user01')->first();
        $this->hrms = Project::where('code', 'hrms')->first();
        $this->worker = app(ProvisioningSyncWorker::class);

        $employee = Employee::create([
            'employee_code' => 'EMP7001',
            'f_name' => 'Sync',
            'l_name' => 'Tester',
            'full_name' => 'Sync Tester',
            'name_with_initials' => 'S. Tester',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '975556667V',
            'date_of_birth' => '1997-07-07',
            'email' => 'sync.tester@example.com',
            'phone' => '+94112345678',
            'address_line_1' => 'Sync Street',
            'city' => 'Colombo',
            'phone_primary' => '+94771122334',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->user = User::create([
            'employee_code' => 'EMP7001',
            'name' => 'Sync Tester',
            'username' => 'sync_tester',
            'email' => 'sync.tester@example.com',
            'password' => 'password123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->hrmsRole = ProjectRole::create([
            'project_id' => $this->hrms->id,
            'external_role_id' => 'officer',
            'code' => 'officer',
            'name' => 'HR Officer',
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Successful CREATE_USER provisioning transitions sync_jobs to SUCCESS and user_project_access to ACTIVE.
     */
    public function test_successful_create_user_downstream_sync(): void
    {
        // 1. Setup local access record in PENDING state
        $access = UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'status' => 'PENDING',
            'assigned_by' => $this->admin->id,
            'version' => 1,
        ]);

        $syncJob = SyncJob::create([
            'idempotency_key' => 'test-create-sync-01',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => [
                'externalRef' => 'EMP7001',
                'username' => 'sync_tester',
                'email' => 'sync.tester@example.com',
                'roles' => ['officer'],
            ],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        // 2. Mock downstream 201 response
        Http::fake([
            'http://localhost:8001/api/giam/integration/users' => Http::response([
                'status' => 'success',
                'downstreamUserId' => 'HRMS-USER-999',
            ], 201),
        ]);

        // 3. Process job
        $result = $this->worker->process($syncJob);

        $this->assertTrue($result);

        // Verify sync_jobs state
        $syncJob->refresh();
        $this->assertEquals('SUCCESS', $syncJob->status);
        $this->assertEquals(201, $syncJob->http_status_code);
        $this->assertNotNull($syncJob->processed_at);
        $this->assertNull($syncJob->next_retry_at);

        // Verify user_project_access state transitioned to ACTIVE
        $access->refresh();
        $this->assertEquals('ACTIVE', $access->status);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PROVISIONING_SYNC_SUCCESS',
            'entity_type' => 'SyncJob',
            'entity_id' => (string) $syncJob->id,
        ]);
    }

    /**
     * Test 2: Successful ASSIGN_ACCESS downstream sync (PATCH /users/{ref}/access).
     */
    public function test_successful_assign_access_downstream_sync(): void
    {
        $access = UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'status' => 'PENDING',
            'assigned_by' => $this->admin->id,
            'version' => 2,
        ]);

        $syncJob = SyncJob::create([
            'idempotency_key' => 'test-assign-sync-02',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'ASSIGN_ACCESS',
            'payload' => [
                'externalRef' => 'EMP7001',
                'roles' => ['officer', 'manager'],
            ],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        Http::fake([
            'http://localhost:8001/api/giam/integration/users/EMP7001/access' => Http::response(['status' => 'updated'], 200),
        ]);

        $result = $this->worker->process($syncJob);
        $this->assertTrue($result);

        $syncJob->refresh();
        $this->assertEquals('SUCCESS', $syncJob->status);
        $this->assertEquals('ACTIVE', $access->fresh()->status);
    }

    /**
     * Test 3: Successful REVOKE_ACCESS downstream sync (DELETE /users/{ref}).
     */
    public function test_successful_revoke_access_downstream_sync(): void
    {
        $access = UserProjectAccess::create([
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'status' => 'REVOKED',
            'assigned_by' => $this->admin->id,
            'version' => 2,
        ]);

        $syncJob = SyncJob::create([
            'idempotency_key' => 'test-revoke-sync-03',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'REVOKE_ACCESS',
            'payload' => [
                'externalRef' => 'EMP7001',
                'reason' => 'Terminated',
            ],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        Http::fake([
            'http://localhost:8001/api/giam/integration/users/EMP7001' => Http::response(['status' => 'deprovisioned'], 200),
        ]);

        $result = $this->worker->process($syncJob);
        $this->assertTrue($result);

        $syncJob->refresh();
        $this->assertEquals('SUCCESS', $syncJob->status);
        $this->assertEquals('REVOKED', $access->fresh()->status);
    }

    /**
     * Test 4: Downstream failure triggers exponential backoff and sets status to RETRYING.
     */
    public function test_downstream_failure_triggers_exponential_backoff_and_retrying_status(): void
    {
        $syncJob = SyncJob::create([
            'idempotency_key' => 'test-fail-sync-04',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => [
                'externalRef' => 'EMP7001',
                'email' => 'sync.tester@example.com',
            ],
            'status' => 'PENDING',
            'attempt_count' => 0,
            'max_attempts' => 5,
        ]);

        // Downstream returns 500 error
        Http::fake([
            'http://localhost:8001/api/giam/integration/users' => Http::response(['error' => 'Database failure downstream'], 500),
        ]);

        $result = $this->worker->process($syncJob);
        $this->assertFalse($result);

        $syncJob->refresh();
        $this->assertEquals(1, $syncJob->attempt_count);
        $this->assertEquals('RETRYING', $syncJob->status);
        $this->assertEquals(500, $syncJob->http_status_code);
        $this->assertNotNull($syncJob->next_retry_at);
        $this->assertTrue($syncJob->next_retry_at->isFuture());
    }

    /**
     * Test 5: Exceeding max attempts marks sync_jobs as FAILED.
     */
    public function test_max_retry_exhaustion_marks_job_failed(): void
    {
        $syncJob = SyncJob::create([
            'idempotency_key' => 'test-exhaust-sync-05',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => [
                'externalRef' => 'EMP7001',
            ],
            'status' => 'RETRYING',
            'attempt_count' => 4, // Next attempt will hit 5 (max_attempts = 5)
            'max_attempts' => 5,
        ]);

        Http::fake([
            'http://localhost:8001/api/giam/integration/users' => Http::response(['error' => 'Fatal Error'], 500),
        ]);

        $result = $this->worker->process($syncJob);
        $this->assertFalse($result);

        $syncJob->refresh();
        $this->assertEquals(5, $syncJob->attempt_count);
        $this->assertEquals('FAILED', $syncJob->status);
        $this->assertNull($syncJob->next_retry_at);
        $this->assertNotNull($syncJob->processed_at);
    }

    /**
     * Test 6: Artisan command giam:process-sync-jobs drains pending jobs.
     */
    public function test_artisan_process_sync_jobs_command(): void
    {
        // Create 2 pending jobs
        $job1 = SyncJob::create([
            'idempotency_key' => 'cmd-sync-01',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => ['externalRef' => 'EMP7001'],
            'status' => 'PENDING',
        ]);

        $job2 = SyncJob::create([
            'idempotency_key' => 'cmd-sync-02',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'ASSIGN_ACCESS',
            'payload' => ['externalRef' => 'EMP7001'],
            'status' => 'PENDING',
        ]);

        Http::fake([
            'http://localhost:8001/api/giam/integration/users' => Http::response(['ok' => true], 200),
            'http://localhost:8001/api/giam/integration/users/EMP7001/access' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('giam:process-sync-jobs', ['--limit' => 10])
            ->expectsOutputToContain('Processing 2 outbox sync job(s)...')
            ->assertExitCode(0);

        $this->assertEquals('SUCCESS', $job1->fresh()->status);
        $this->assertEquals('SUCCESS', $job2->fresh()->status);
    }

    /**
     * Test 7: Manual retry endpoint (POST /api/v1/sync-jobs/{id}/retry) retries failed job.
     */
    public function test_manual_retry_endpoint_retries_failed_job(): void
    {
        $this->actingAs($this->admin, 'web');

        $job = SyncJob::create([
            'idempotency_key' => 'retry-test-01',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => ['externalRef' => 'EMP7001'],
            'status' => 'FAILED',
            'attempt_count' => 5,
        ]);

        Http::fake([
            'http://localhost:8001/api/giam/integration/users' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->postJson("/api/v1/sync-jobs/{$job->id}/retry");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $job->id,
                    'status' => 'SUCCESS',
                ],
            ]);

        $job->refresh();
        $this->assertEquals('SUCCESS', $job->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SYNC_JOB_MANUALLY_RETRIED',
            'entity_type' => 'SyncJob',
            'entity_id' => (string) $job->id,
        ]);
    }

    /**
     * Test 8: Manual retry requires PROJECT_MANAGE permission.
     */
    public function test_manual_retry_requires_project_manage_permission(): void
    {
        // Non-admin staff user
        $this->actingAs($this->user, 'web');

        $job = SyncJob::create([
            'idempotency_key' => 'retry-rbac-01',
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => ['externalRef' => 'EMP7001'],
            'status' => 'FAILED',
        ]);

        $response = $this->postJson("/api/v1/sync-jobs/{$job->id}/retry");
        $response->assertStatus(403);
    }

    /**
     * Test 9: Idempotency key is transmitted in downstream headers and payload.
     */
    public function test_idempotency_key_transmitted_in_headers_and_payload(): void
    {
        $idempotencyKey = 'unique-key-xyz-789';

        $job = SyncJob::create([
            'idempotency_key' => $idempotencyKey,
            'user_id' => $this->user->id,
            'project_id' => $this->hrms->id,
            'operation' => 'CREATE_USER',
            'payload' => ['externalRef' => 'EMP7001'],
            'status' => 'PENDING',
        ]);

        Http::fake([
            'http://localhost:8001/api/giam/integration/users' => function ($request) use ($idempotencyKey) {
                // Verify header
                $this->assertTrue($request->hasHeader('X-GIAM-Idempotency-Key'));
                $this->assertEquals($idempotencyKey, $request->header('X-GIAM-Idempotency-Key')[0]);

                // Verify payload
                $data = $request->data();
                $this->assertArrayHasKey('idempotencyKey', $data);
                $this->assertEquals($idempotencyKey, $data['idempotencyKey']);

                return Http::response(['ok' => true], 200);
            },
        ]);

        $result = $this->worker->process($job);
        $this->assertTrue($result);
        $this->assertEquals('SUCCESS', $job->fresh()->status);
    }
}
