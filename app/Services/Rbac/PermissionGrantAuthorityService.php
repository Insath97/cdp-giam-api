<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Centralized service governing GIAM Internal RBAC permission grantability,
 * role assignment privilege escalation boundaries, and administrative self-lockout prevention.
 */
class PermissionGrantAuthorityService
{
    /**
     * BOOTSTRAP ROOT IDENTITY: Narrow system invariant for bootstrap root administrative authority.
     * Used exclusively to protect root role assignment and grant full-catalog bootstrap authority.
     * NOT to be used for normal business authorization.
     */
    public const BOOTSTRAP_ROOT_ROLE_NAME = 'Super Admin';

    /**
     * Check if an actor holds the bootstrap root identity.
     */
    public function isRootActor(?User $actor): bool
    {
        if (! $actor) {
            return false;
        }

        return $actor->hasRole(self::BOOTSTRAP_ROOT_ROLE_NAME);
    }

    /**
     * Get all unique effective permission names held by the actor across all assigned roles.
     *
     * @param User $actor
     * @return Collection<int, string>
     */
    public function getEffectivePermissions(User $actor): Collection
    {
        return $actor->getAllPermissions()->pluck('name')->unique()->values();
    }

    /**
     * Determine if an actor has grant authority for a single permission.
     */
    public function canGrantPermission(?User $actor, string $permissionName): bool
    {
        if (! $actor) {
            return false;
        }

        if ($this->isRootActor($actor)) {
            return true;
        }

        return $this->getEffectivePermissions($actor)->contains($permissionName);
    }

    /**
     * Determine if an actor has grant authority for a set of permissions.
     * For a non-root actor: requested_permissions ⊆ actor_effective_permissions.
     *
     * @param User|null $actor
     * @param array<int, string> $requestedPermissionNames
     * @return bool
     */
    public function canGrantPermissions(?User $actor, array $requestedPermissionNames): bool
    {
        if (! $actor) {
            return false;
        }

        if ($this->isRootActor($actor)) {
            return true;
        }

        $effective = $this->getEffectivePermissions($actor)->toArray();
        $unheld = array_diff($requestedPermissionNames, $effective);

        return empty($unheld);
    }

    /**
     * Validate that the actor has the authority to grant the requested permissions.
     * Throws 403 Forbidden if privilege escalation is detected.
     *
     * @param User|null $actor
     * @param array<int, string> $requestedPermissionNames
     * @throws HttpException
     */
    public function validatePermissionGrant(?User $actor, array $requestedPermissionNames): void
    {
        if (! $actor) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        if ($this->isRootActor($actor)) {
            return;
        }

        $effective = $this->getEffectivePermissions($actor)->toArray();
        $unheld = array_diff($requestedPermissionNames, $effective);

        if (! empty($unheld)) {
            throw new HttpException(
                403,
                'Privilege escalation rejected: you cannot grant permissions beyond your own authority: ' . implode(', ', $unheld)
            );
        }
    }

    /**
     * Determine if an actor is authorized to assign a collection of canonical Role models.
     *
     * @param User|null $actor
     * @param Collection<int, Role> $targetRoles
     * @return bool
     */
    public function canAssignRoles(?User $actor, Collection $targetRoles): bool
    {
        if (! $actor) {
            return false;
        }

        if ($this->isRootActor($actor)) {
            return true;
        }

        $effective = $this->getEffectivePermissions($actor)->toArray();

        foreach ($targetRoles as $role) {
            if ($role->name === self::BOOTSTRAP_ROOT_ROLE_NAME) {
                return false;
            }

            if (! $role->relationLoaded('permissions')) {
                $role->load('permissions');
            }

            $rolePerms = $role->permissions->pluck('name')->toArray();
            if (! empty(array_diff($rolePerms, $effective))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate role assignment to prevent privilege escalation.
     * For non-root actors:
     * 1. Cannot assign BOOTSTRAP_ROOT_ROLE_NAME.
     * 2. For every target role, role_permissions ⊆ actor_effective_permissions.
     *
     * @param User|null $actor
     * @param Collection<int, Role> $targetRoles
     * @param User|null $targetUser
     * @throws HttpException
     */
    public function validateRoleAssignment(?User $actor, Collection $targetRoles, ?User $targetUser = null): void
    {
        if (! $actor) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        if ($this->isRootActor($actor)) {
            return;
        }

        $effective = $this->getEffectivePermissions($actor)->toArray();

        foreach ($targetRoles as $role) {
            if ($role->name === self::BOOTSTRAP_ROOT_ROLE_NAME) {
                throw new HttpException(
                    403,
                    'Privilege escalation rejected: only Super Admin can grant the Super Admin role.'
                );
            }

            // Ensure permissions relation is loaded
            if (! $role->relationLoaded('permissions')) {
                $role->load('permissions');
            }

            $rolePerms = $role->permissions->pluck('name')->toArray();
            $unheld = array_diff($rolePerms, $effective);

            if (! empty($unheld)) {
                throw new HttpException(
                    403,
                    "Privilege escalation rejected: target role [{$role->name}] contains permissions beyond your authority: " . implode(', ', $unheld)
                );
            }
        }
    }

    /**
     * Prevent self-lockout when modifying permissions of an existing role.
     * If the actor is assigned to the role being edited and currently possesses GIAM_ROLE_MANAGE,
     * the resulting effective permissions (combining other assigned roles + new role permissions)
     * must still contain GIAM_ROLE_MANAGE.
     *
     * @param User $actor
     * @param Role $targetRole
     * @param array<int, string> $newPermissionNames
     * @throws HttpException
     */
    public function validatePermissionSelfLockout(User $actor, Role $targetRole, array $newPermissionNames): void
    {
        if (! $actor->hasRole($targetRole->name)) {
            return;
        }

        if (! $actor->hasPermissionTo('GIAM_ROLE_MANAGE', 'web')) {
            return;
        }

        $otherRoles = $actor->roles()->where('id', '!=', $targetRole->id)->with('permissions')->get();
        $otherPerms = $otherRoles->flatMap(fn ($r) => $r->permissions->pluck('name'))->unique();

        $resultingPerms = $otherPerms->merge($newPermissionNames)->unique();

        if (! $resultingPerms->contains('GIAM_ROLE_MANAGE')) {
            throw new HttpException(
                403,
                'Self-lockout rejected: this modification would remove your own GIAM_ROLE_MANAGE capability.'
            );
        }
    }

    /**
     * Prevent self-lockout when an actor updates their own assigned user roles (PUT /users/{id}).
     * If the actor currently possesses GIAM_ROLE_MANAGE, the newly assigned roles must retain
     * GIAM_ROLE_MANAGE across their combined permissions.
     *
     * @param User $actor
     * @param User|null $targetUser
     * @param Collection<int, Role> $newRoles
     * @throws HttpException
     */
    public function validateUserRoleSelfLockout(User $actor, ?User $targetUser, Collection $newRoles): void
    {
        if (! $targetUser || $actor->id !== $targetUser->id) {
            return;
        }

        if (! $actor->hasPermissionTo('GIAM_ROLE_MANAGE', 'web')) {
            return;
        }

        foreach ($newRoles as $role) {
            if (! $role->relationLoaded('permissions')) {
                $role->load('permissions');
            }
        }

        $resultingPerms = $newRoles->flatMap(fn ($r) => $r->permissions->pluck('name'))->unique();

        if (! $resultingPerms->contains('GIAM_ROLE_MANAGE')) {
            throw new HttpException(
                403,
                'Self-lockout rejected: you cannot remove your own GIAM_ROLE_MANAGE capability.'
            );
        }
    }
}
