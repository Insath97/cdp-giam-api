<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProjectSyncAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appId = $request->header('X-App-Id');
        $apiKey = $request->header('X-Api-Key');

        if (!$appId || !$apiKey) {
            return response()->json(['message' => 'Missing authentication headers (X-App-Id, X-Api-Key)'], 401);
        }

        $credential = \App\Models\ProjectSyncCredential::where('application_id', $appId)->first();

        if (!$credential || $credential->api_key !== $apiKey) {
            return response()->json(['message' => 'Invalid application credentials'], 401);
        }

        // Add application to request so the controller can use it
        $request->merge(['sync_application_id' => $appId]);

        return $next($request);
    }
}
