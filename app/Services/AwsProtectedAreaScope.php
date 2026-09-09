<?php

namespace App\Services;

use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AwsProtectedAreaScope
{
    public function __construct(
        private readonly OrganizationalAccessService $organization,
    ) {}

    /** @return list<string> */
    public function activeCodes(): array
    {
        return array_values(config('aws.active_protected_area_codes', []));
    }

    public function query(Builder $query, User $user, string $column = 'protected_area_id'): Builder
    {
        $authorized = $this->organization->scopeProtectedAreaQuery($query, $user, $column);

        $activeIds = $this->activeIds($user);

        return $activeIds === [] && ! $this->hasConfiguredActiveAreas()
            ? $authorized->whereNotIn($column, $this->legacyExcludedIds())
            : $authorized->whereIn($column, $activeIds);
    }

    /** @return Collection<int, ProtectedArea> */
    public function options(User $user): Collection
    {
        return $this->query(ProtectedArea::query(), $user, 'id')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name']);
    }

    public function assertCanAccess(User $user, mixed $protectedAreaId): void
    {
        $isActive = ProtectedArea::query()->whereKey($protectedAreaId)->whereIn('short_name', $this->activeCodes())->exists();
        $hasConfiguredAreas = ProtectedArea::query()->whereIn('short_name', $this->activeCodes())->exists();
        $isLegacyCompatible = ! $hasConfiguredAreas
            && ! ProtectedArea::query()->whereKey($protectedAreaId)->whereIn('name', $this->legacyExcludedNames())->exists();

        abort_unless($this->organization->canAccessProtectedArea($user, $protectedAreaId) && ($isActive || $isLegacyCompatible), 403);
    }

    /** @return list<int> */
    public function activeIds(User $user): array
    {
        $query = $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')
            ->whereIn('short_name', $this->activeCodes())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($query !== [] || $this->hasConfiguredActiveAreas()) return $query;

        return $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')
            ->whereNotIn('name', $this->legacyExcludedNames())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function hasConfiguredActiveAreas(): bool
    {
        return ProtectedArea::query()->whereIn('short_name', $this->activeCodes())->exists();
    }

    /** @return list<string> */
    private function legacyExcludedNames(): array
    {
        return ['Baganga Mangrove Swamp Forest Reserve (BMSFR)', 'Baganga Protected Landscape (BPL)'];
    }

    /** @return list<int> */
    private function legacyExcludedIds(): array
    {
        return ProtectedArea::query()
            ->whereIn('name', $this->legacyExcludedNames())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
