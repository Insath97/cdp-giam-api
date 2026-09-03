<?php

namespace App\Services\RbacCatalog;

use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectPermission;
use App\Models\ProjectPermissionGroup;
use App\Models\ProjectRole;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use Illuminate\Support\Facades\DB;

class CatalogSyncHandler
{
    public function __construct(
        protected ProjectRbacDiscoveryService $discoveryService,
        protected AuditLoggerService $auditLogger
    ) {}

    public function sync(Project $project, ?User $actor = null): array
    {
        // 1. Fetch and validate remote access definition outside DB transaction
        $definition = $this->discoveryService->fetchAccessDefinition($project);

        // 2. Perform atomic ingestion inside DB transaction
        return DB::transaction(function () use ($project, $definition, $actor) {
            $stats = [
                'modules_synced' => 0,
                'roles_synced' => 0,
                'groups_synced' => 0,
                'permissions_synced' => 0,
            ];

            // Synchronize Modules (Optional)
            $remoteModuleIds = [];
            foreach ($definition['modules'] as $mod) {
                $extId = (string) ($mod['id'] ?? $mod['code'] ?? null);
                if (! $extId) {
                    continue;
                }
                $remoteModuleIds[] = $extId;
                $code = $mod['code'] ?? $extId;

                ProjectModule::updateOrCreate(
                    ['project_id' => $project->id, 'external_module_id' => $extId],
                    [
                        'code' => $code,
                        'name' => $mod['name'] ?? $extId,
                        'description' => $mod['description'] ?? null,
                        'is_active' => true,
                        'last_synced_at' => now(),
                    ]
                );
                $stats['modules_synced']++;
            }

            // Flag removed modules as inactive
            ProjectModule::where('project_id', $project->id)
                ->whereNotIn('external_module_id', $remoteModuleIds)
                ->update(['is_active' => false]);

            // Synchronize Roles
            $remoteRoleIds = [];
            foreach ($definition['roles'] as $role) {
                $extId = (string) ($role['id'] ?? $role['code'] ?? null);
                if (! $extId) {
                    continue;
                }
                $remoteRoleIds[] = $extId;
                $code = $role['code'] ?? $extId;

                ProjectRole::updateOrCreate(
                    ['project_id' => $project->id, 'external_role_id' => $extId],
                    [
                        'code' => $code,
                        'name' => $role['name'] ?? $extId,
                        'description' => $role['description'] ?? null,
                        'is_active' => true,
                        'last_synced_at' => now(),
                    ]
                );
                $stats['roles_synced']++;
            }

            // Flag removed roles as inactive (never delete to protect historical assignments)
            ProjectRole::where('project_id', $project->id)
                ->whereNotIn('external_role_id', $remoteRoleIds)
                ->update(['is_active' => false]);

            // Synchronize Permission Groups
            $remoteGroupIds = [];
            $groupIdMap = [];
            $groupsData = $definition['permissionGroups'] ?? $definition['permission_groups'] ?? [];
            foreach ($groupsData as $group) {
                $extId = (string) ($group['id'] ?? $group['code'] ?? null);
                if (! $extId) {
                    continue;
                }
                $remoteGroupIds[] = $extId;
                $code = $group['code'] ?? $extId;

                $groupModel = ProjectPermissionGroup::updateOrCreate(
                    ['project_id' => $project->id, 'external_group_id' => $extId],
                    [
                        'code' => $code,
                        'name' => $group['name'] ?? $extId,
                        'description' => $group['description'] ?? null,
                        'is_active' => true,
                        'last_synced_at' => now(),
                    ]
                );
                $groupIdMap[$extId] = $groupModel->id;
                $groupIdMap[$code] = $groupModel->id;
                $stats['groups_synced']++;
            }

            // Flag removed permission groups as inactive
            ProjectPermissionGroup::where('project_id', $project->id)
                ->whereNotIn('external_group_id', $remoteGroupIds)
                ->update(['is_active' => false]);

            // Synchronize Permissions
            $remotePermIds = [];
            foreach ($definition['permissions'] as $perm) {
                $extId = (string) ($perm['id'] ?? $perm['code'] ?? null);
                if (! $extId) {
                    continue;
                }
                $remotePermIds[] = $extId;
                $code = $perm['code'] ?? $extId;

                $groupRef = (string) ($perm['groupId'] ?? $perm['group_id'] ?? $perm['group_code'] ?? '');
                $groupId = $groupRef && isset($groupIdMap[$groupRef]) ? $groupIdMap[$groupRef] : null;

                ProjectPermission::updateOrCreate(
                    ['project_id' => $project->id, 'external_permission_id' => $extId],
                    [
                        'project_permission_group_id' => $groupId,
                        'code' => $code,
                        'name' => $perm['name'] ?? $extId,
                        'description' => $perm['description'] ?? null,
                        'is_active' => true,
                        'last_synced_at' => now(),
                    ]
                );
                $stats['permissions_synced']++;
            }

            // Flag removed permissions as inactive
            ProjectPermission::where('project_id', $project->id)
                ->whereNotIn('external_permission_id', $remotePermIds)
                ->update(['is_active' => false]);

            // Update integration catalog timestamp
            if ($project->integration) {
                $project->integration->update([
                    'last_sync_catalog_at' => now(),
                ]);
            }

            // Audit log
            $this->auditLogger->log(
                action: 'PROJECT_CATALOG_SYNCED',
                entityType: 'Project',
                entityId: $project->code,
                beforeData: null,
                afterData: $stats,
                status: 'SUCCESS',
                projectId: $project->id,
                actorUserId: $actor?->id
            );

            return $stats;
        });
    }
}
