<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sso\AuthorizeSsoRequest;
use App\Http\Requests\Sso\ExchangeTokenRequest;
use App\Http\Requests\Sso\LogoutTelemetryRequest;
use App\Services\Sso\SsoAuthorizationService;
use Illuminate\Http\JsonResponse;

class SsoController extends Controller
{
    public function __construct(
        protected SsoAuthorizationService $ssoService
    ) {}

    public function authorize(AuthorizeSsoRequest $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $state = $request->input('state');

        try {
            $result = $this->ssoService->issueAuthorizationCode(
                user: $request->user(),
                projectId: $request->integer('project_id'),
                redirectUri: $request->input('redirect_uri'),
                codeChallenge: $request->input('code_challenge'),
                codeChallengeMethod: $request->input('code_challenge_method'),
                state: $state
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // If user access is denied (HTTP 403) and this is a browser GET request,
            // communicate error back via redirect per RFC 6749 Section 4.1.2.1
            if ($e->getStatusCode() === 403 && $request->isMethod('GET')) {
                $redirectUri = $request->input('redirect_uri');
                $separator = str_contains($redirectUri, '?') ? '&' : '?';
                $errorUrl = $redirectUri . $separator . 'error=access_denied&error_description=' . urlencode($e->getMessage()) . ($state ? '&state=' . urlencode($state) : '');

                return redirect()->away($errorUrl);
            }

            throw $e;
        }

        // Top-level browser GET navigation redirects via HTTP 302
        if ($request->isMethod('GET') || ! $request->wantsJson()) {
            return redirect()->away($result['redirect_url']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function token(ExchangeTokenRequest $request): JsonResponse
    {
        $result = $this->ssoService->exchangeCodeForToken(
            code: $request->input('code'),
            codeVerifier: $request->input('code_verifier'),
            clientId: $request->input('client_id'),
            clientSecret: $request->input('client_secret'),
            clientIp: $request->ip() ?? '127.0.0.1'
        );

        return response()->json($result);
    }

    public function logoutTelemetry(LogoutTelemetryRequest $request): JsonResponse
    {
        $result = $this->ssoService->handleLogoutTelemetry(
            clientId: $request->input('client_id'),
            clientSecret: $request->input('client_secret'),
            externalRef: $request->input('external_ref'),
            sessionId: $request->input('session_id')
        );

        return response()->json($result);
    }
}
