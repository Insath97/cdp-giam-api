<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PROJECT_VIEW', 'web');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('PROJECT_VIEW', 'web');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PROJECT_MANAGE', 'web');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('PROJECT_MANAGE', 'web');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermissionTo('PROJECT_MANAGE', 'web');
    }
}
