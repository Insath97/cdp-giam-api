<?php

namespace App\Services\Access;

use App\Exceptions\OptimisticLockException;
use App\Models\Project;
use App\Models\ProjectPermission;
use App\Models\ProjectRole;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\UserProjectAccess;
use App\Services\Audit\AuditLoggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProjectAccessAssignmentService
{
    public function __construct(
        protected AuditLoggerService $auditLogger,
        protected \App\Services\Sync\DataProjectionService $dataProjectionService
    ) {}

    public function assignAccess(
        User $user,
        int $projectId,
        array $roleIds,
        array $permissionIds,
        User $actor,
        ?int $expectedVersion = null
    ): UserProjectAccess {
        // 1. Business Validation: User and employee must have active records
        if (! $user->is_active || ! $user->can_login) {
            throw new HttpException(422, 'Cannot assign project access to an inactive or login-disabled user account.');
        }

        if ($user->employee && ! $user->employee->is_active) {
            throw new HttpException(422, 'Cannot assign project access to an inactive employee record.');
        }

        // 2. Business Validation: Project must be active and sync-enabled
        $project = Project::with('integration')->findOrFail($projectId);
        if ($project->status !== 'active') {
            throw new HttpException(422, "Cannot assign access: Project [{$project->code}] is {$project->status}.");
        }

        if (! $project->integration || ! $project->integration->sync_enabled) {
            throw new HttpException(422, "Cannot assign access: Project [{$project->code}] synchronization is disabled.");
        }

        // 3. Authoritative Backend Validation: Roles must exist, be active, and belong to specified project
        if (! empty($roleIds)) {
            $uniqueRoleIds = array_values(array_unique($roleIds));
            $roles = ProjectRole::whereIn('id', $uniqueRoleIds)->get();

            if ($roles->count() !== count($uniqueRoleIds)) {
                throw new HttpException(422, 'One or more selected roles do not exist in the catalog.');
            }

            foreach ($roles as $role) {
                if ((int) $role->project_id !== (int) $project->id) {
                    throw new HttpException(
                        422,
                        "Cross-project isolation violation: Role [{$role->name}] (ID: {$role->id}) does not belong to project [{$project->code}]."
                    );
                }

                if (! $role->is_active) {
                    throw new HttpException(
                        422,
                        "Cannot assign inactive role [{$role->name}] (ID: {$role->id}) for project [{$project->code}]."
                    );
                }
            }
        }

        // Strict Cross-Project Isolation: Permissions must exist, be active, and belong to specified project
        if (! empty($permissionIds)) {
            $uniquePermIds = array_values(array_unique($permissionIds));
            $perms = ProjectPermission::whereIn('id', $uniquePermIds)->get();

            if ($perms->count() !== count($uniquePermIds)) {
                throw new HttpException(422, 'One or more selected permissions do not exist in the catalog.');
            }

            foreach ($perms as $perm) {
                if ((int) $perm->project_id !== (int) $project->id) {
                    throw new HttpException(
                        422,
                        "Cross-project isolation violation: Permission [{$perm->name}] (ID: {$perm->id}) does not belong to project [{$project->code}]."
                    );
                }

                if (! $perm->is_active) {
                    throw new HttpException(
                        422,
                        "Cannot assign inactive permission [{$perm->name}] (ID: {$perm->id}) for project [{$project->code}]."
                    );
                }
            }
        }

        // 4. Server-Side Data Projection via unified DataProjectionService
        $projectedPayload = $this->dataProjectionService->project(
            user: $user,
            project: $project,
            roleIds: $roleIds,
            permissionIds: $permissionIds
        );

        // 5. Transactional Atomicity: Mutate access and write outbox sync_jobs in ONE transaction
        return DB::transaction(function () use (
            $user,
            $project,
            $roleIds,
            $permissionIds,
            $actor,
            $expectedVersion,
            $projectedPayload
        ) {
            $existing = UserProjectAccess::where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->first();

            $isNew = ($existing === null);
            $wasRevoked = false;
            $previousStatus = null;

            if ($existing) {
                // Optimistic concurrency check
                if ($expectedVersion !== null && (int) $existing->version !== (int) $expectedVersion) {
                    throw new OptimisticLockException(
                        "Concurrency conflict on project access ID {$existing->id}: expected version {$expectedVersion}, but found {$existing->version}."
                    );
                }

                $wasRevoked = ($existing->status === 'REVOKED');
                $previousStatus = $existing->status;

                $existing->status = 'PENDING'; // Reset status to PENDING for outbox sync
                $existing->assigned_by = $actor->id;
                $existing->assigned_at = now();
                $existing->revoked_by = null;
                $existing->revoked_at = null;
                $existing->revocation_reason = null;
                $existing->save();

                $access = $existing;
            } else {
                $access = UserProjectAccess::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'status' => 'PENDING',
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                    'version' => 1,
                ]);
            }

            // Sync assigned roles with external_role_id pivot
            $roles = ProjectRole::whereIn('id', $roleIds)->get();
            $syncRoles = [];
            foreach ($roles as $role) {
                $syncRoles[$role->id] = [
                    'external_role_id' => $role->external_role_id,
                    'assigned_at' => now(),
                ];
            }
            $access->roles()->sync($syncRoles);

            // Sync assigned permissions with external_permission_id pivot
            $perms = ProjectPermission::whereIn('id', $permissionIds)->get();
            $syncPerms = [];
            foreach ($perms as $perm) {
                $syncPerms[$perm->id] = [
                    'external_permission_id' => $perm->external_permission_id,
                    'is_granted' => true,
                    'assigned_at' => now(),
                ];
            }
            $access->permissions()->sync($syncPerms);

            // Write transactional outbox sync_jobs record
            SyncJob::create([
                'idempotency_key' => (string) Str::uuid(),
                'user_id' => $user->id,
                'project_id' => $project->id,
                'operation' => $isNew ? 'CREATE_USER' : 'ASSIGN_ACCESS',
                'payload' => $projectedPayload,
                'status' => 'PENDING',
                'attempt_count' => 0,
                'max_attempts' => 5,
            ]);

            // Audit log
            $auditAction = $isNew
                ? 'PROJECT_ACCESS_GRANTED'
                : ($wasRevoked ? 'PROJECT_ACCESS_REGRANTED' : 'PROJECT_ACCESS_UPDATED');

            $this->auditLogger->log(
                action: $auditAction,
                entityType: 'UserProjectAccess',
                entityId: (string) $access->id,
                beforeData: $isNew ? null : ['version' => $existing->version - 1, 'previous_status' => $previousStatus],
                afterData: [
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'roles_count' => count($roleIds),
                    'permissions_count' => count($permissionIds),
                    'status' => 'PENDING',
                    'version' => $access->version,
                ],
                status: 'SUCCESS',
                projectId: $project->id,
                actorUserId: $actor->id
            );

            return $access->load(['project', 'roles', 'permissions']);
        });
    }

    public function revokeAccess(
        User $user,
        int $projectId,
        User $actor,
        ?string $reason = null,
        ?int $expectedVersion = null
    ): UserProjectAccess {
        $access = UserProjectAccess::where('user_id', $user->id)
            ->where('project_id', $projectId)
            ->firstOrFail();

        return DB::transaction(function () use ($user, $projectId, $access, $actor, $reason, $expectedVersion) {
            // Optimistic concurrency check
            if ($expectedVersion !== null && (int) $access->version !== (int) $expectedVersion) {
                throw new OptimisticLockException(
                    "Concurrency conflict on project access ID {$access->id}: expected version {$expectedVersion}, but found {$access->version}."
                );
            }

            $beforeData = $access->toArray();

            $access->status = 'REVOKED';
            $access->revoked_by = $actor->id;
            $access->revoked_at = now();
            $access->revocation_reason = $reason;
            $access->save();

            // Transactional outbox sync_jobs record for downstream de-provisioning
            SyncJob::create([
                'idempotency_key' => (string) Str::uuid(),
                'user_id' => $user->id,
                'project_id' => $projectId,
                'operation' => 'REVOKE_ACCESS',
                'payload' => [
                    'externalRef' => $user->employee_code,
                    'username' => $user->username,
                    'revocation_reason' => $reason,
                ],
                'status' => 'PENDING',
                'attempt_count' => 0,
                'max_attempts' => 5,
            ]);

            // Audit log
            $this->auditLogger->log(
                action: 'PROJECT_ACCESS_REVOKED',
                entityType: 'UserProjectAccess',
                entityId: (string) $access->id,
                beforeData: $beforeData,
                afterData: $access->fresh()->toArray(),
                status: 'SUCCESS',
                projectId: $projectId,
                actorUserId: $actor->id
            );

            return $access->load(['project', 'roles', 'permissions']);
        });
    }
}
