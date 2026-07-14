<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('CDS Admin');
    }

    public function view(User $user, User $managedUser): bool
    {
        return $user->hasRole('CDS Admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('CDS Admin');
    }

    public function update(User $user, User $managedUser): bool
    {
        return $user->hasRole('CDS Admin');
    }

    public function delete(User $user, User $managedUser): bool
    {
        if (! $user->hasRole('CDS Admin') || $user->is($managedUser)) {
            return false;
        }

        return ! ($managedUser->hasRole('CDS Admin')
            && User::role('CDS Admin')->count() <= 1);
    }
}
