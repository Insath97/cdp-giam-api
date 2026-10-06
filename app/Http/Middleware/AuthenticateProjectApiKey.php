<?php

namespace App\Http\Middleware;

use App\Services\Integration\ProjectApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProjectApiKey
{
    public function __construct(
        protected ProjectApiKeyService $apiKeyService
    ) {}

    /**
     * Handle an incoming request authenticated by Project X-API-KEY.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $request->header('X-API-KEY');

        if (empty($rawKey)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing X-API-KEY header.',
            ], 401);
        }

        $parsed = $this->apiKeyService->parseToken($rawKey);
        if (!$parsed) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key format.',
            ], 401);
        }

        $apiKey = $this->apiKeyService->validateToken($rawKey);
        if (!$apiKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or unverified API key.',
            ], 401);
        }

        if ($apiKey->isRevoked()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API key has been revoked.',
            ], 401);
        }

        if ($apiKey->isExpired()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API key has expired.',
            ], 401);
        }

        $project = $apiKey->project;
        if (!$project || $project->status !== 'active') {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Associated project is inactive or disabled.',
            ], 401);
        }

        if (!$project->integration) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Project integration configuration is not found.',
            ], 401);
        }

        // Update last_used_at without touching updated_at timestamp or firing model events
        $apiKey->updateQuietly([
            'last_used_at' => now(),
        ]);

        // Bind authenticated project context to the request
        $request->attributes->set('authenticated_project', $project);
        $request->attributes->set('authenticated_api_key', $apiKey);

        return $next($request);
    }
}
