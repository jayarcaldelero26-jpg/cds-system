<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Authorization\AdministratorPreservationService;

class UserPolicy
{
    private function isAdministrator(User $user): bool
    {
        return app(AdministratorPreservationService::class)->isAdministrator($user);
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

    public function deactivate(User $user, User $managedUser): bool
    {
        if (! $this->isAdministrator($user) || $user->is($managedUser)) {
            return false;
        }

        $administrators = app(AdministratorPreservationService::class);
        if ($administrators->isAdministrator($managedUser)
            && ! $administrators->hasOtherUsableAdministrator($managedUser)) {
            return false;
        }

        return true;
    }

    public function delete(User $user, User $managedUser): bool
    {
        if (! $this->isAdministrator($user) || $user->is($managedUser)) {
            return false;
        }

        $administrators = app(AdministratorPreservationService::class);
        return ! ($administrators->isAdministrator($managedUser)
            && ! $administrators->hasOtherUsableAdministrator($managedUser));
    }
}
