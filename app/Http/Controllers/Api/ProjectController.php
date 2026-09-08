<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Services\Audit\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function __construct(
        protected AuditLoggerService $auditLogger
    ) {}

    /**
     * Display a listing of projects.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Project::with('integration')
            ->withCount(['modules', 'roles', 'permissions']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $projects = $query->orderBy('name', 'asc')
                          ->paginate($request->integer('per_page', 15));

        return ProjectResource::collection($projects);
    }

    /**
     * Store a newly created project and optional integration.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = DB::transaction(function () use ($request) {
            $project = Project::create([
                'code' => $request->input('code'),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'base_url' => $request->input('base_url'),
                'icon_url' => $request->input('icon_url'),
                'status' => $request->input('status', 'active'),
            ]);

            if ($integrationData = $request->input('integration')) {
                $integration = new ProjectIntegration([
                    'project_id' => $project->id,
                    'api_base_url' => $integrationData['api_base_url'],
                    'auth_method' => $integrationData['auth_method'] ?? 'bearer_token',
                    'client_id' => $integrationData['client_id'] ?? null,
                    'allowed_user_fields' => $integrationData['allowed_user_fields'],
                    'sync_enabled' => $integrationData['sync_enabled'] ?? true,
                    'sso_enabled' => $integrationData['sso_enabled'] ?? true,
                    'status' => 'healthy',
                ]);

                if (! empty($integrationData['client_secret'])) {
                    $integration->setClientSecretAttribute($integrationData['client_secret']);
                }

                $integration->save();
            }

            $this->auditLogger->log(
                action: 'PROJECT_CREATED',
                entityType: 'Project',
                entityId: $project->code,
                beforeData: null,
                afterData: $project->toArray(),
                status: 'SUCCESS',
                projectId: $project->id,
                actorUserId: $request->user()?->id
            );

            return $project->load('integration');
        });

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified project.
     */
    public function show(int|string $id): ProjectResource
    {
        $project = Project::with('integration')
            ->withCount(['modules', 'roles', 'permissions'])
            ->where('id', $id)
            ->orWhere('code', $id)
            ->firstOrFail();

        return new ProjectResource($project);
    }

    /**
     * Update the specified project.
     */
    public function update(UpdateProjectRequest $request, int|string $id): ProjectResource
    {
        $project = Project::where('id', $id)
            ->orWhere('code', $id)
            ->firstOrFail();

        $beforeData = $project->toArray();
        $project->update($request->validated());

        $this->auditLogger->log(
            action: 'PROJECT_UPDATED',
            entityType: 'Project',
            entityId: $project->code,
            beforeData: $beforeData,
            afterData: $project->fresh()->toArray(),
            status: 'SUCCESS',
            projectId: $project->id,
            actorUserId: $request->user()?->id
        );

        return new ProjectResource($project->fresh('integration'));
    }

    /**
     * Disable the specified project.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $project = Project::where('id', $id)
            ->orWhere('code', $id)
            ->firstOrFail();

        $beforeData = $project->toArray();
        $project->update(['status' => 'disabled']);

        $this->auditLogger->log(
            action: 'PROJECT_DISABLED',
            entityType: 'Project',
            entityId: $project->code,
            beforeData: $beforeData,
            afterData: ['status' => 'disabled'],
            status: 'SUCCESS',
            projectId: $project->id,
            actorUserId: $request->user()?->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Project has been disabled successfully.',
        ]);
    }
}
