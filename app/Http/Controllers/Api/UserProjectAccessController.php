<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\AssignProjectAccessRequest;
use App\Http\Requests\Access\RevokeProjectAccessRequest;
use App\Http\Requests\Access\UpdateProjectAccessRequest;
use App\Http\Resources\UserProjectAccessResource;
use App\Http\Resources\UserProjectAccessSummaryResource;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Access\ProjectAccessAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserProjectAccessController extends Controller
{
    public function __construct(
        protected ProjectAccessAssignmentService $accessService
    ) {}

    /**
     * List project access assignments for one Principal.
     * High-performance, paginated summary view (eager loads project & roles only, excludes full permission catalogs).
     */
    public function index(Request $request, int|string $userId): AnonymousResourceCollection
    {
        $user = User::where('id', $userId)
            ->orWhere('username', $userId)
            ->firstOrFail();

        $perPage = (int) $request->input('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        } elseif ($perPage > 100) {
            $perPage = 100;
        }

        $accesses = UserProjectAccess::with(['project', 'roles'])
            ->where('user_id', $user->id)
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return UserProjectAccessSummaryResource::collection($accesses);
    }

    /**
     * Detailed single project access record for one Principal.
     * Distinguishes assigned roles from direct per-user permission overrides without leaking API URLs.
     */
    public function show(Request $request, int|string $userId, int $projectId): JsonResponse
    {
        $user = User::where('id', $userId)
            ->orWhere('username', $userId)
            ->firstOrFail();

        $access = UserProjectAccess::with(['project', 'roles', 'permissions'])
            ->where('user_id', $user->id)
            ->where('project_id', $projectId)
            ->firstOrFail();

        return (new UserProjectAccessResource($access))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Grant or re-grant access to a project for one Principal.
     */
    public function store(AssignProjectAccessRequest $request, int|string $userId): JsonResponse
    {
        $user = User::where('id', $userId)
            ->orWhere('username', $userId)
            ->firstOrFail();

        $access = $this->accessService->assignAccess(
            user: $user,
            projectId: $request->integer('project_id'),
            roleIds: $request->input('role_ids', []),
            permissionIds: $request->input('permission_ids', []),
            actor: $request->user(),
            expectedVersion: $request->input('version') !== null ? $request->integer('version') : null
        );

        return (new UserProjectAccessResource($access))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Change assigned project role(s) or permissions for a specific project assignment.
     * Updates ONLY the selected project's assignment without side-effects on other projects or GIAM roles.
     */
    public function update(
        UpdateProjectAccessRequest $request,
        int|string $userId,
        int $projectId
    ): JsonResponse {
        $user = User::where('id', $userId)
            ->orWhere('username', $userId)
            ->firstOrFail();

        $access = $this->accessService->assignAccess(
            user: $user,
            projectId: $projectId,
            roleIds: $request->input('role_ids', []),
            permissionIds: $request->input('permission_ids', []),
            actor: $request->user(),
            expectedVersion: $request->input('version') !== null ? $request->integer('version') : null
        );

        return (new UserProjectAccessResource($access))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Revoke access to a specific project for one Principal.
     */
    public function destroy(RevokeProjectAccessRequest $request, int|string $userId, int $projectId): JsonResponse
    {
        $user = User::where('id', $userId)
            ->orWhere('username', $userId)
            ->firstOrFail();

        $access = $this->accessService->revokeAccess(
            user: $user,
            projectId: $projectId,
            actor: $request->user(),
            reason: $request->input('reason'),
            expectedVersion: $request->input('version') !== null ? $request->integer('version') : null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Project access has been revoked and queued for de-provisioning.',
            'data' => new UserProjectAccessResource($access),
        ]);
    }
}
