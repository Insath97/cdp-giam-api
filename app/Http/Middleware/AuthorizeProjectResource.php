<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeProjectResource
{
    /**
     * Handle an incoming request to verify that the authenticated project
     * has been granted the required resource scope.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $resourceScope
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $resourceScope): Response
    {
        /** @var Project|null $project */
        $project = $request->attributes->get('authenticated_project');

        if (!$project || !$project->integration) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Project integration configuration not found.',
            ], 403);
        }

        $allowedResources = $project->integration->allowed_resources;

        // Deny by default: missing or non-array configuration permits zero resources
        if (!is_array($allowedResources) || !in_array($resourceScope, $allowedResources, true)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => "Project is not authorized to access resource scope '{$resourceScope}'.",
            ], 403);
        }

        return $next($request);
    }
}
