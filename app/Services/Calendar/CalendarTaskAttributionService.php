<?php

namespace App\Services\Calendar;

use App\Models\OrganizationalOffice;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;

final class CalendarTaskAttributionService
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    /** @return array<string, int|string|null> */
    public function snapshot(User $actor): array
    {
        $area = $actor->protectedArea;
        $office = null;
        $category = $this->organization->effectiveCategory($actor)
            ?: $this->organization->normalizeCategory($actor->section);

        if ($category === OrganizationalAccessService::PAMO && $actor->protected_area_id) {
            $facts = $this->organization->pamoAssignmentForProtectedArea((int) $actor->protected_area_id);
            if ($facts) {
                $office = OrganizationalOffice::query()
                    ->whereKey($facts['pamo_office_id'])
                    ->where('is_active', true)
                    ->first(['id', 'code', 'name']);
                $area = $facts['protected_area'] ?? $area;
            }
        } else {
            $designated = $this->organization->normalizeOffice($actor->office_designated);
            if ($designated && in_array($designated, $this->organization->canonicalOffices(), true)) {
                $office = OrganizationalOffice::query()
                    ->where('name', $designated)
                    ->where('is_active', true)
                    ->first(['id', 'code', 'name']);
            }
        }

        return [
            'actor_id' => (int) $actor->getKey(),
            'actor_name' => (string) $actor->name,
            'office_id' => $office ? (int) $office->getKey() : null,
            'office_code' => $office?->code,
            'office_name' => $office?->name,
            'unit' => $this->organization->unitFor($actor),
            'protected_area_id' => $area ? (int) $area->getKey() : null,
            'protected_area_name' => $area?->name,
        ];
    }
}
