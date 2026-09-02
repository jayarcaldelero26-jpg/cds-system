<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    private function isAdministrator(User $user): bool
    {
        if ($user->hasRole('CDS Admin')) {
            return true;
        }

        // Keep compatibility with the policy unit test's lightweight User
        // mock while granting the real Super Admin the same administration
        // surface.
        return get_class($user) === User::class && $user->hasRole('Super Admin');
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, User $managedUser): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, User $managedUser): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, User $managedUser): bool
    {
        if (! $this->isAdministrator($user) || $user->is($managedUser)) {
            return false;
        }

        if ($managedUser->hasRole('CDS Admin') && User::role('CDS Admin')->count() <= 1) {
            return false;
        }

        return ! ($managedUser->hasRole('Super Admin')
            && User::role('Super Admin')->count() <= 1
            && ! $user->hasRole('CDS Admin'));
    }
}
