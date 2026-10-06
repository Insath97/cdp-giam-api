<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SimpleJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGiamSessionOrBearer
{
    /**
     * Authenticate request via either Bearer JWT or Web Session cookie.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check Bearer Token
        $bearer = $request->bearerToken();
        if (! $bearer && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                $bearer = $matches[1];
            }
        }
        if (! $bearer && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
            if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
                $bearer = $matches[1];
            }
        }

        if ($bearer) {
            $userId = SimpleJwtService::validateToken($bearer);
            if ($userId) {
                $user = User::find($userId);
                if ($user && $user->is_active && $user->can_login) {
                    Auth::guard('web')->setUser($user);
                    Auth::setUser($user);
                    $request->setUserResolver(fn () => $user);
                    return $next($request);
                }
            }
        }

        // 2. Check Web Session
        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
