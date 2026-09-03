<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireGiamPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication is required to access this resource.',
            ], 401);
        }

        // Super Admin bypasses all specific GIAM permission checks
        if ($user->hasRole('Super Admin')) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermissionTo($permission, 'web')) {
                return $next($request);
            }
        }

        return response()->json([
            'status' => 'error',
            'code' => 'FORBIDDEN',
            'message' => 'You do not have the required permission to perform this action.',
            'required_permissions' => $permissions,
        ], 403);
    }
}
