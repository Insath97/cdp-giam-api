<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the browser to authenticate with the HttpOnly auth_token cookie.
 * The JWT itself is never exposed to frontend JavaScript.
 */
class JwtCookieMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->bearerToken() && $request->cookie('auth_token')) {
            $request->headers->set('Authorization', 'Bearer '.$request->cookie('auth_token'));
        }

        return $next($request);
    }
}
