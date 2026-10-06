<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanLogin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if (! $user->is_active) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'status' => 'error',
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'Your account is inactive. Please contact system administration.',
                ], 403);
            }

            if (! $user->can_login) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'status' => 'error',
                    'code' => 'LOGIN_REVOKED',
                    'message' => 'Your login access is disabled. Please contact system administration.',
                ], 403);
            }
        }

        return $next($request);
    }
}
