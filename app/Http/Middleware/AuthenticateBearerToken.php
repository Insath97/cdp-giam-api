<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\SimpleJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearerToken
{
    /**
     * Handle an incoming request with Bearer JWT token support.
     */
    public function handle(Request $request, Closure $next): Response
    {
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
            \Illuminate\Support\Facades\Log::info("AuthenticateBearerToken: bearer found, userId={$userId}");
            if ($userId) {
                $user = User::find($userId);
                if ($user && $user->is_active && $user->can_login) {
                    Auth::guard('web')->setUser($user);
                    Auth::setUser($user);
                    $request->setUserResolver(fn () => $user);
                    \Illuminate\Support\Facades\Log::info("AuthenticateBearerToken: user set for {$user->username}");
                }
            }
        } else {
            \Illuminate\Support\Facades\Log::info("AuthenticateBearerToken: NO bearer token found in request headers: " . json_encode($request->headers->all()));
        }

        return $next($request);
    }
}
