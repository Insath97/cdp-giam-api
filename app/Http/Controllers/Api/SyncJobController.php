<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncJobResource;
use App\Models\SyncJob;
use App\Services\Audit\AuditLoggerService;
use App\Services\Sync\ProvisioningSyncWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SyncJobController extends Controller
{
    public function __construct(
        protected ProvisioningSyncWorker $worker,
        protected AuditLoggerService $auditLogger
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SyncJob::with(['project', 'user'])->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $jobs = $query->paginate($request->integer('per_page', 20));

        return SyncJobResource::collection($jobs);
    }

    public function show(int $id): SyncJobResource
    {
        $job = SyncJob::with(['project', 'user'])->findOrFail($id);

        return new SyncJobResource($job);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $job = SyncJob::with(['project.integration', 'user'])->findOrFail($id);

        if ($job->status === 'SUCCESS') {
            throw new HttpException(422, 'This sync job has already completed successfully and cannot be retried.');
        }

        if ($job->status === 'PROCESSING') {
            throw new HttpException(422, 'This sync job is currently being processed by a worker.');
        }

        // Reset to PENDING for immediate processing
        $job->update([
            'status' => 'PENDING',
            'next_retry_at' => null,
            'last_error' => null,
        ]);

        $this->auditLogger->log(
            action: 'SYNC_JOB_MANUALLY_RETRIED',
            entityType: 'SyncJob',
            entityId: (string) $job->id,
            beforeData: ['status' => $job->getOriginal('status')],
            afterData: ['status' => 'PENDING'],
            status: 'SUCCESS',
            projectId: $job->project_id,
            actorUserId: $request->user()?->id
        );

        // Process synchronously
        $this->worker->process($job);
        $job->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Sync job retried.',
            'data' => new SyncJobResource($job),
        ]);
    }
}
