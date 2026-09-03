<?php

namespace App\Services\Integration;

use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

class ProjectClientFactory
{
    /**
     * Create a pre-configured HTTP client for communication with a downstream project.
     */
    public function make(Project $project, int $timeoutSeconds = 5): PendingRequest
    {
        $integration = $project->integration;

        if (! $integration) {
            throw new InvalidArgumentException("Project [{$project->code}] has no integration configured.");
        }

        $client = Http::baseUrl($integration->api_base_url)
            ->timeout($timeoutSeconds)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'GIAM-Orchestrator/1.0',
                'X-GIAM-Project' => $project->code,
            ]);

        // Attach authentication credentials based on auth_method
        $secret = $integration->getDecryptedClientSecret();

        if ($integration->auth_method === 'bearer_token' && $secret) {
            $client->withToken($secret);
        } elseif ($integration->auth_method === 'api_key' && $secret) {
            $client->withHeaders(['X-API-Key' => $secret]);
        } elseif ($integration->auth_method === 'oauth2' && $integration->client_id && $secret) {
            $client->withBasicAuth($integration->client_id, $secret);
        } elseif ($integration->auth_method === 'hmac' && $secret) {
            $client->withRequestMiddleware(function (RequestInterface $request) use ($secret, $integration) {
                $timestamp = time();
                $body = (string) $request->getBody();
                $payloadToSign = $request->getMethod() . "\n" . $request->getUri()->getPath() . "\n" . $timestamp . "\n" . $body;
                $signature = hash_hmac('sha256', $payloadToSign, $secret);

                return $request->withHeader('X-HMAC-Timestamp', (string) $timestamp)
                               ->withHeader('X-HMAC-Key-Id', $integration->client_id ?? '')
                               ->withHeader('X-HMAC-Signature', $signature);
            });
        }

        return $client;
    }
}
