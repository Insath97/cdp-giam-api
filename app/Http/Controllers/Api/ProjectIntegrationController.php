<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectIntegrationRequest;
use App\Http\Resources\ProjectIntegrationResource;
use App\Models\Project;
use App\Services\Audit\AuditLoggerService;
use App\Services\Integration\ProjectHealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectIntegrationController extends Controller
{
    public function __construct(
        protected ProjectHealthCheckService $healthCheckService,
        protected AuditLoggerService $auditLogger
    ) {}

    public function show(int|string $projectId): ProjectIntegrationResource
    {
        $project = Project::where('id', $projectId)
            ->orWhere('code', $projectId)
            ->firstOrFail();

        $integration = $project->integration;

        if (! $integration) {
            abort(404, 'Project integration not configured.');
        }

        return new ProjectIntegrationResource($integration);
    }

    public function update(UpdateProjectIntegrationRequest $request, int|string $projectId): ProjectIntegrationResource
    {
        $project = Project::where('id', $projectId)
            ->orWhere('code', $projectId)
            ->firstOrFail();

        $integration = $project->integration ?? $project->integration()->create([
            'api_base_url' => $request->input('api_base_url', $project->base_url . '/api/giam/integration'),
            'allowed_user_fields' => $request->input('allowed_user_fields', ['employee_code', 'full_name', 'email']),
        ]);

        $beforeData = $integration->toArray();

        $data = $request->validated();
        if (! empty($data['client_secret'])) {
            $integration->setClientSecretAttribute($data['client_secret']);
            unset($data['client_secret']);
        }

        $integration->update($data);

        $this->auditLogger->log(
            action: 'PROJECT_INTEGRATION_UPDATED',
            entityType: 'ProjectIntegration',
            entityId: (string) $integration->id,
            beforeData: $beforeData,
            afterData: $integration->fresh()->toArray(),
            status: 'SUCCESS',
            projectId: $project->id,
            actorUserId: $request->user()?->id
        );

        return new ProjectIntegrationResource($integration->fresh());
    }

    public function healthCheck(int|string $projectId): JsonResponse
    {
        $project = Project::with('integration')
            ->where('id', $projectId)
            ->orWhere('code', $projectId)
            ->firstOrFail();

        $result = $this->healthCheckService->check($project);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
