<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfLockoutProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function createPrincipalWithPermissions(string $username, array $permissions): User
    {
        $code = 'EMP_' . strtoupper(substr(md5($username), 0, 8));
        Employee::create([
            'employee_code' => $code,
            'f_name' => 'Self',
            'l_name' => 'Manager',
            'full_name' => 'Self Manager',
            'name_with_initials' => 'S. Manager',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => substr(md5($code), 0, 9) . 'V',
            'date_of_birth' => '1990-03-20',
            'email' => "{$username}@example.com",
            'phone' => '+94778889900',
            'address_line_1' => 'HQ',
            'city' => 'Colombo',
            'phone_primary' => '+94778889900',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => $code,
            'name' => 'Self Manager',
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => 'Password@123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
            'version' => 1,
        ]);

        $roleName = 'ManagerRole_' . substr(md5($username), 0, 6);
        $role = Role::create([
            'name' => $roleName,
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);

        $role->syncPermissions($permissions);
        $user->syncRoles([$roleName]);

        return $user;
    }

    /**
     * Proves: Modifying the permissions of the actor's only assigned role to strip GIAM_ROLE_MANAGE is rejected.
     */
    public function test_modifying_assigned_role_to_remove_giam_role_manage_is_rejected(): void
    {
        $actor = $this->createPrincipalWithPermissions('lockout_target', [
            'GIAM_ROLE_MANAGE',
            'GIAM_ROLE_VIEW',
            'USER_VIEW',
        ]);

        $role = $actor->roles->first();

        // Attempt to remove GIAM_ROLE_MANAGE from this role
        $res = $this->actingAs($actor)->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
            'permissions' => ['GIAM_ROLE_VIEW', 'USER_VIEW'],
        ]);

        $res->assertStatus(403);
        $res->assertJsonFragment([
            'message' => 'Self-lockout rejected: this modification would remove your own GIAM_ROLE_MANAGE capability.',
        ]);
    }

    /**
     * Proves: Modifying role A to remove GIAM_ROLE_MANAGE when actor also possesses role B (which preserves GIAM_ROLE_MANAGE) succeeds.
     */
    public function test_modifying_role_a_when_role_b_preserves_giam_role_manage_succeeds(): void
    {
        $actor = $this->createPrincipalWithPermissions('dual_manager', [
            'GIAM_ROLE_MANAGE',
            'GIAM_ROLE_VIEW',
        ]);

        $roleA = $actor->roles->first();

        // Create Role B also holding GIAM_ROLE_MANAGE and assign to actor
        $roleB = Role::create([
            'name' => 'RoleB_' . uniqid(),
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);
        $roleB->syncPermissions(['GIAM_ROLE_MANAGE']);
        $actor->assignRole($roleB->name);

        // Modifying Role A to remove GIAM_ROLE_MANAGE should succeed because Role B retains it
        $res = $this->actingAs($actor)->putJson("/api/v1/rbac/roles/{$roleA->id}/permissions", [
            'permissions' => ['GIAM_ROLE_VIEW'],
        ]);

        $res->assertStatus(200);
    }

    /**
     * Proves: Reassigning actor's own user roles (PUT /api/v1/users/{id}) to remove GIAM_ROLE_MANAGE is rejected.
     */
    public function test_reassigning_own_user_roles_to_remove_giam_role_manage_is_rejected(): void
    {
        $actor = $this->createPrincipalWithPermissions('self_updater', [
            'USER_UPDATE',
            'USER_VIEW',
            'GIAM_ROLE_MANAGE',
            'GIAM_ROLE_VIEW',
        ]);

        // Attempt to update self to Staff (which has no GIAM_ROLE_MANAGE)
        $res = $this->actingAs($actor)->putJson("/api/v1/users/{$actor->id}", [
            'version' => $actor->version,
            'roles' => ['Staff'],
        ]);

        $res->assertStatus(403);
        $res->assertJsonFragment([
            'message' => 'Self-lockout rejected: you cannot remove your own GIAM_ROLE_MANAGE capability.',
        ]);
    }

    /**
     * Proves: Reassigning actor's own user roles to another role preserving GIAM_ROLE_MANAGE succeeds.
     */
    public function test_reassigning_own_user_roles_to_another_role_preserving_giam_role_manage_succeeds(): void
    {
        $actor = $this->createPrincipalWithPermissions('self_swapper', [
            'USER_UPDATE',
            'USER_VIEW',
            'GIAM_ROLE_MANAGE',
            'GIAM_ROLE_VIEW',
        ]);

        // Create another role that also holds GIAM_ROLE_MANAGE and USER_VIEW
        $newRole = Role::create([
            'name' => 'AlternativeAdmin_' . uniqid(),
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);
        $newRole->syncPermissions(['GIAM_ROLE_MANAGE', 'USER_VIEW', 'USER_UPDATE']);

        // Swap to the new role
        $res = $this->actingAs($actor)->putJson("/api/v1/users/{$actor->id}", [
            'version' => $actor->version,
            'roles' => [$newRole->name],
        ]);

        $res->assertStatus(200);
        $this->assertTrue($actor->fresh()->hasRole($newRole->name));
    }

    /**
     * Proves: Modifying an unassigned role does not trigger self-lockout check.
     */
    public function test_modifying_unassigned_role_does_not_trigger_self_lockout(): void
    {
        $actor = $this->createPrincipalWithPermissions('unassigned_modifier', [
            'GIAM_ROLE_MANAGE',
            'GIAM_ROLE_VIEW',
            'USER_VIEW',
        ]);

        // Create a role that is NOT assigned to $actor
        $unassignedRole = Role::create([
            'name' => 'UnassignedRole_' . uniqid(),
            'guard_name' => 'web',
            'is_system_reserved' => false,
        ]);
        $unassignedRole->syncPermissions(['GIAM_ROLE_MANAGE', 'USER_VIEW']);

        // Remove GIAM_ROLE_MANAGE from unassigned role
        $res = $this->actingAs($actor)->putJson("/api/v1/rbac/roles/{$unassignedRole->id}/permissions", [
            'permissions' => ['USER_VIEW'],
        ]);

        $res->assertStatus(200);
    }
}
