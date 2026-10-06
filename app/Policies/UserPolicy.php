<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
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
        return $user->hasPermissionTo('USER_VIEW', 'web');
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->hasPermissionTo('USER_VIEW', 'web');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('USER_CREATE', 'web');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('USER_UPDATE', 'web');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasPermissionTo('USER_DEACTIVATE', 'web');
    }
}
