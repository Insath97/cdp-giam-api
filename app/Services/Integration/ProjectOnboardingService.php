<?php

namespace App\Services\Integration;

use App\Models\Project;
use App\Models\ProjectIntegration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generic, capability-driven service for onboarding and updating approved downstream projects.
 *
 * This service is the single standard for:
 *   1. Operational single-project onboarding (php artisan giam:onboard-project {code})
 *   2. Fresh-environment and CI bootstrap seeding (ProjectRegistrySeeder)
 *
 * SECURITY INVARIANTS:
 * - Never supports hardcoded plaintext credentials.
 * - Resolves required credentials from environment-backed configuration keys.
 * - Never logs, dumps, serializes, or exposes secret values.
 * - Never touches or alters ProjectApiKey records.
 * - Strictly transactional and idempotent.
 */
class ProjectOnboardingService
{
    /**
     * Onboard or update a single approved downstream project and its integration.
     *
     * @param string $code Canonical project code (e.g. credix, stockly, centrix)
     * @return Project The freshly persisted project model with its integration loaded
     *
     * @throws InvalidArgumentException When the project code is not in config/projects.php
     * @throws RuntimeException When required integration credentials are unavailable
     */
    public function onboard(string $code): Project
    {
        $normalizedCode = strtolower(trim($code));

        $definition = config("projects.definitions.{$normalizedCode}");

        if (empty($definition) || ! is_array($definition)) {
            throw new InvalidArgumentException(
                "Unknown or unapproved project code: [{$normalizedCode}]. " .
                "Only approved projects registered in config/projects.php can be onboarded."
            );
        }

        // 1. Resolve project core attributes generically
        $baseUrl = $this->resolveBaseUrl($definition);

        $projectData = [
            'name' => (string) ($definition['name'] ?? ucfirst($normalizedCode)),
            'description' => $definition['description'] ?? null,
            'base_url' => $baseUrl,
            'icon_url' => $definition['icon_url'] ?? null,
            'status' => (string) ($definition['status'] ?? 'active'),
        ];

        // 2. Resolve project integration attributes generically
        $integ = $definition['integration'] ?? [];

        $apiUrl = $this->resolveApiUrl($integ);
        $clientId = $this->resolveClientId($integ);
        $clientSecret = $this->resolveClientSecret($integ);

        // Validate required environment-backed credentials
        $this->validateRequiredCredentials($integ, $clientId, $clientSecret);

        $redirectUris = $this->resolveRedirectUris($integ, $baseUrl);

        $integrationData = [
            'api_base_url' => $apiUrl,
            'auth_method' => (string) ($integ['auth_method'] ?? 'bearer_token'),
            'client_id' => $clientId,
            'status' => (string) ($integ['status'] ?? 'healthy'),
            'sync_enabled' => (bool) ($integ['sync_enabled'] ?? false),
            'sso_enabled' => (bool) ($integ['sso_enabled'] ?? false),
        ];

        if ($clientSecret !== null) {
            $integrationData['client_secret'] = $clientSecret;
        }

        if ($redirectUris !== null) {
            $integrationData['redirect_uris'] = $redirectUris;
        }

        if (array_key_exists('allowed_user_fields', $integ)) {
            $integrationData['allowed_user_fields'] = $integ['allowed_user_fields'];
        }

        if (array_key_exists('allowed_resources', $integ)) {
            $integrationData['allowed_resources'] = $integ['allowed_resources'];
        }

        if (array_key_exists('allowed_resource_fields', $integ)) {
            $integrationData['allowed_resource_fields'] = $integ['allowed_resource_fields'];
        }

        // 3. Persist atomically inside database transaction
        return DB::transaction(function () use ($normalizedCode, $projectData, $integrationData) {
            $project = Project::updateOrCreate(
                ['code' => $normalizedCode],
                $projectData
            );

            ProjectIntegration::updateOrCreate(
                ['project_id' => $project->id],
                $integrationData
            );

            return $project->fresh('integration');
        });
    }

    /**
     * Resolve the base URL for the project.
     */
    protected function resolveBaseUrl(array $definition): string
    {
        if (! empty($definition['base_url_config_key'])) {
            $configured = config($definition['base_url_config_key']);
            if (! empty($configured)) {
                return rtrim((string) $configured, '/');
            }
            return (string) ($definition['base_url_fallback'] ?? '');
        }

        return (string) ($definition['base_url'] ?? '');
    }

    /**
     * Resolve the integration API base URL.
     */
    protected function resolveApiUrl(array $integ): string
    {
        if (! empty($integ['api_url_config_key'])) {
            $configured = config($integ['api_url_config_key']);
            if (! empty($configured)) {
                return (string) $configured;
            }
            return (string) ($integ['api_base_url_fallback'] ?? '');
        }

        return (string) ($integ['api_base_url'] ?? '');
    }

    /**
     * Resolve client ID from configuration reference key or direct null.
     */
    protected function resolveClientId(array $integ): ?string
    {
        if (! empty($integ['client_id_config_key'])) {
            $configured = config($integ['client_id_config_key']);
            return ! empty($configured) ? trim((string) $configured) : null;
        }

        return array_key_exists('client_id', $integ) && $integ['client_id'] !== null
            ? trim((string) $integ['client_id'])
            : null;
    }

    /**
     * Resolve client secret from configuration reference key or direct null.
     */
    protected function resolveClientSecret(array $integ): ?string
    {
        if (! empty($integ['client_secret_config_key'])) {
            $configured = config($integ['client_secret_config_key']);
            return ! empty($configured) ? trim((string) $configured) : null;
        }

        return array_key_exists('client_secret', $integ) && $integ['client_secret'] !== null
            ? trim((string) $integ['client_secret'])
            : null;
    }

    /**
     * Resolve redirect URIs, replacing {base_url} placeholders when present.
     */
    protected function resolveRedirectUris(array $integ, string $baseUrl): ?array
    {
        if (! empty($integ['redirect_uris_patterns']) && is_array($integ['redirect_uris_patterns'])) {
            $trimmedBase = rtrim($baseUrl, '/');
            return array_map(function (string $uri) use ($trimmedBase) {
                return str_replace('{base_url}', $trimmedBase, $uri);
            }, $integ['redirect_uris_patterns']);
        }

        if (array_key_exists('redirect_uris', $integ)) {
            return $integ['redirect_uris'];
        }

        return null;
    }

    /**
     * Validate that all declared required credentials are present.
     *
     * SECURITY NOTE: Never logs or prints resolved credential values; only identifies missing key names.
     */
    protected function validateRequiredCredentials(array $integ, ?string $clientId, ?string $clientSecret): void
    {
        $required = $integ['required_credentials'] ?? [];

        if (empty($required) || ! is_array($required)) {
            return;
        }

        foreach ($required as $field => $credentialIdentifier) {
            $value = match ($field) {
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                default => null,
            };

            if (empty($value) || trim((string) $value) === '') {
                throw new RuntimeException("Missing required integration credential: [{$credentialIdentifier}]");
            }
        }
    }
}
