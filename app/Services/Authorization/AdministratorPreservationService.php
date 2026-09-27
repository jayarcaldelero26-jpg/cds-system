<?php

namespace App\Services\Authorization;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Shared definition and transactional guard for preserving administrative access. */
final class AdministratorPreservationService
{
    /** @var list<string> */
    public const ROLE_NAMES = ['Super Admin', 'CDS Admin'];

    public function isAdministrator(User $user): bool
    {
        return $user->hasAnyRole(self::ROLE_NAMES);
    }

    public function isAdministratorRole(?string $roleName): bool
    {
        return is_string($roleName) && in_array($roleName, self::ROLE_NAMES, true);
    }

    public function hasOtherUsableAdministrator(User $target): bool
    {
        return User::query()
            ->where('users.id', '<>', $target->getKey())
            ->where('is_active', true)
            ->where('is_approved', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ROLE_NAMES))
            ->exists();
    }

    /**
     * Lock the current usable administrator rows in stable order. Call before
     * locking a target user in a User Management mutation transaction so
     * concurrent removals serialize on the same rows.
     *
     * @return Collection<int, int>
     */
    public function lockUsableAdministratorIds(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('is_approved', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::ROLE_NAMES))
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get(['users.id'])
            ->map(fn (User $user): int => (int) $user->getKey());
    }

    /** @param Collection<int, int> $lockedUsableAdministratorIds */
    public function assertAnotherUsableAdministrator(
        User $target,
        Collection $lockedUsableAdministratorIds,
        string $errorField = 'account_role',
    ): void {
        if (! $this->isAdministrator($target)) {
            return;
        }

        $hasFallback = $lockedUsableAdministratorIds->contains(
            fn (int $id): bool => $id !== (int) $target->getKey(),
        );

        if (! $hasFallback) {
            throw ValidationException::withMessages([
                $errorField => 'At least one other active, approved administrator must remain.',
            ]);
        }
    }
}
