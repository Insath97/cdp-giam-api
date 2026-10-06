<?php

namespace App\Services\Sync;

use App\Models\Project;
use App\Models\ProjectPermission;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Integration\ProjectClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Service transforming internal GIAM identity attributes into downstream project payloads
 * adhering strictly to the allowed_user_fields projection allowlist.
 */
class DataProjectionService
{
    public function __construct(
        protected ProjectClientFactory $clientFactory
    ) {}

    /**
     * Transform local user identity and access into a downstream project-specific payload
     * strictly adhering to the configured project_integrations.allowed_user_fields allowlist.
     *
     * @param User $user
     * @param Project $project
     * @param array<int> $roleIds
     * @param array<int> $permissionIds
     * @return array<string, mixed>
     */
    public function project(
        User $user,
        Project $project,
        array $roleIds = [],
        array $permissionIds = []
    ): array {
        $allowed = $project->integration?->allowed_user_fields ?? [];
        $employee = $user->employee;

        // Base standardized identity attributes
        $payload = [
            'id' => $user->id,
            'giam_user_id' => $user->id,
            'external_user_id' => (string) $user->id,
            'externalRef' => $user->employee_code ?: (string) $user->id,
            'employee_code' => $user->employee_code,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
        ];

        // Project only explicitly permitted employee fields
        if ($employee) {
            foreach ($allowed as $field) {
                if (isset($employee->{$field})) {
                    $value = $employee->{$field};
                    $payload[$field] = $value instanceof \DateTimeInterface
                        ? $value->format('Y-m-d')
                        : $value;
                }
            }
        }

        // Project role and permission external references
        if (! empty($roleIds)) {
            $payload['roles'] = ProjectRole::whereIn('id', $roleIds)
                ->where('project_id', $project->id)
                ->pluck('external_role_id')
                ->values()
                ->toArray();
        } else {
            $payload['roles'] = [];
        }

        if (! empty($permissionIds)) {
            $payload['permissions'] = ProjectPermission::whereIn('id', $permissionIds)
                ->where('project_id', $project->id)
                ->pluck('external_permission_id')
                ->values()
                ->toArray();
        } else {
            $payload['permissions'] = [];
        }

        return $payload;
    }

    /**
     * Propagate updated bcrypt credential verifier to all active assigned projects.
     * ZERO plaintext passwords transmitted.
     *
     * @param User $user
     * @param string $passwordHash
     * @return array<string, string>
     */
    public function syncCredentialChange(User $user, string $passwordHash): array
    {
        $activeAccesses = UserProjectAccess::with('project.integration')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->get();

        $results = [];

        foreach ($activeAccesses as $access) {
            $project = $access->project;
            if (! $project || ! $project->integration) {
                continue;
            }

            try {
                $client = $this->clientFactory->make($project, timeoutSeconds: 5);
                $integration = $project->integration;
                $hasNamespace = str_ends_with(rtrim($integration->api_base_url, '/'), '/api/giam/integration');
                $prefix = $hasNamespace ? '' : '/api/giam/integration';

                $res = $client->put("{$prefix}/users/{$user->id}/credential", [
                    'password_verifier' => $passwordHash,
                ]);

                $results[$project->code] = $res->successful() ? 'SUCCESS' : 'FAILED';
            } catch (\Exception $e) {
                Log::warning("Downstream credential propagation deferred for project [{$project->code}]: {$e->getMessage()}");
                $results[$project->code] = 'DEFERRED';
            }
        }

        return $results;
    }
}
