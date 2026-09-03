<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCatalogResource;
use App\Models\Project;
use App\Services\RbacCatalog\CatalogSyncHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectCatalogController extends Controller
{
    public function __construct(
        protected CatalogSyncHandler $syncHandler
    ) {}

    public function show(Request $request, int|string $projectId): ProjectCatalogResource
    {
        $activeOnly = $request->boolean('active_only') || $request->boolean('assignable');

        $project = Project::with([
            'integration',
            'modules' => fn ($q) => $activeOnly ? $q->where('is_active', true) : $q,
            'roles' => fn ($q) => $activeOnly ? $q->where('is_active', true) : $q,
            'permissionGroups' => fn ($q) => $activeOnly ? $q->where('is_active', true) : $q,
            'permissionGroups.permissions' => fn ($q) => $activeOnly ? $q->where('is_active', true) : $q,
            'permissions' => fn ($q) => $activeOnly ? $q->where('is_active', true) : $q,
        ])
        ->where('id', $projectId)
        ->orWhere('code', $projectId)
        ->firstOrFail();

        return new ProjectCatalogResource($project);
    }

    public function sync(Request $request, int|string $projectId): JsonResponse
    {
        $project = Project::with('integration')
            ->where('id', $projectId)
            ->orWhere('code', $projectId)
            ->firstOrFail();

        $stats = $this->syncHandler->sync($project, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Successfully synchronized RBAC catalog for project [{$project->name}].",
            'data' => array_merge(['project_code' => $project->code], $stats),
        ]);
    }
}
