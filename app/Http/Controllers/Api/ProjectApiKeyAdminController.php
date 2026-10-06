<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\Audit\AuditLoggerService;
use App\Services\Integration\ProjectApiKeyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectApiKeyAdminController extends Controller
{
    public function __construct(
        protected ProjectApiKeyService $apiKeyService,
        protected AuditLoggerService $auditLogger
    ) {}

    /**
     * List all API keys configured for a specific project.
     *
     * Never returns plaintext keys or secret hashes.
     *
     * @param int $projectId
     * @return JsonResponse
     */
    public function index(int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $keys = $project->apiKeys()
            ->select(['id', 'project_id', 'name', 'key_id', 'last_used_at', 'expires_at', 'revoked_at', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $keys,
        ]);
    }

    /**
     * Issue a new structured API key for the project.
     *
     * Displays/returns the plaintext API key exactly ONCE in the response.
     *
     * @param Request $request
     * @param int $projectId
     * @return JsonResponse
     */
    public function store(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $expiresAt = !empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;

        $generated = $this->apiKeyService->generateKey(
            project: $project,
            name: $validated['name'],
            expiresAt: $expiresAt
        );

        /** @var ProjectApiKey $apiKey */
        $apiKey = $generated['api_key'];
        $plainTextKey = $generated['plain_text_key'];

        $this->auditLogger->log(
            action: 'PROJECT_API_KEY_CREATED',
            entityType: 'ProjectApiKey',
            entityId: (string) $apiKey->id,
            beforeData: null,
            afterData: [
                'project_id' => $project->id,
                'project_code' => $project->code,
                'name' => $apiKey->name,
                'key_id' => $apiKey->key_id,
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
            ],
            status: 'SUCCESS',
            projectId: $project->id,
            metadata: [
                'project_code' => $project->code,
                'key_id' => $apiKey->key_id,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'API key created successfully. Store the plain_text_key securely now; it will never be displayed again.',
            'data' => [
                'id' => $apiKey->id,
                'project_id' => $apiKey->project_id,
                'name' => $apiKey->name,
                'key_id' => $apiKey->key_id,
                'expires_at' => $apiKey->expires_at,
                'created_at' => $apiKey->created_at,
            ],
            'plain_text_key' => $plainTextKey,
        ], 201);
    }

    /**
     * Revoke an active API key immediately.
     *
     * @param Request $request
     * @param int $projectId
     * @param int $keyId
     * @return JsonResponse
     */
    public function revoke(Request $request, int $projectId, int $keyId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $apiKey = $project->apiKeys()->findOrFail($keyId);

        if ($apiKey->isRevoked()) {
            return response()->json([
                'status' => 'error',
                'message' => 'API key is already revoked.',
            ], 422);
        }

        $this->apiKeyService->revokeKey($apiKey);

        $this->auditLogger->log(
            action: 'PROJECT_API_KEY_REVOKED',
            entityType: 'ProjectApiKey',
            entityId: (string) $apiKey->id,
            beforeData: ['revoked_at' => null],
            afterData: ['revoked_at' => $apiKey->revoked_at?->toIso8601String()],
            status: 'SUCCESS',
            projectId: $project->id,
            metadata: [
                'project_code' => $project->code,
                'key_id' => $apiKey->key_id,
            ],
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'API key revoked successfully.',
            'data' => [
                'id' => $apiKey->id,
                'key_id' => $apiKey->key_id,
                'revoked_at' => $apiKey->revoked_at,
            ],
        ]);
    }
}
