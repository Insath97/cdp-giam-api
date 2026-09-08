<?php

namespace App\Services\RbacCatalog;

use App\Models\Project;
use App\Services\Integration\ProjectClientFactory;
use Exception;
use RuntimeException;

/**
 * Service querying downstream project endpoints to fetch published RBAC definitions.
 */
class ProjectRbacDiscoveryService
{
    public function __construct(
        protected ProjectClientFactory $clientFactory
    ) {}

    /**
     * Fetch the RBAC access definition from downstream project.
     *
     * @param Project $project
     * @return array<string, array<mixed>>
     * @throws RuntimeException
     */
    public function fetchAccessDefinition(Project $project): array
    {
        $integration = $project->integration;

        if (! $integration) {
            throw new RuntimeException("Project [{$project->code}] has no integration configured.");
        }

        $client = $this->clientFactory->make($project, timeoutSeconds: 8);

        // Normalize discovery path
        $endpoint = str_ends_with(rtrim($integration->api_base_url, '/'), '/api/giam/integration')
            ? '/access-definition'
            : '/api/giam/integration/access-definition';

        try {
            $response = $client->get($endpoint);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch access-definition from project [{$project->code}]. HTTP {$response->status()}: {$response->body()}"
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException("Malformed JSON response received from project [{$project->code}].");
            }

            return [
                'modules' => $data['modules'] ?? [],
                'roles' => $data['roles'] ?? [],
                'permissionGroups' => $data['permissionGroups'] ?? $data['permission_groups'] ?? [],
                'permissions' => $data['permissions'] ?? [],
            ];
        } catch (Exception $e) {
            throw new RuntimeException("Downstream discovery error for [{$project->code}]: {$e->getMessage()}", 0, $e);
        }
    }
}
