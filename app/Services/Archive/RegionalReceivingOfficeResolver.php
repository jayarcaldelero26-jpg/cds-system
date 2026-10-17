<?php

namespace App\Services\Archive;

use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Database\Eloquent\Model;

/** Resolves the exact PENRO destination from the report's authoritative office assignment. */
final class RegionalReceivingOfficeResolver
{
    /** The current canonical CENRO catalog routes through its one Davao Oriental PENRO. */
    private const CENRO_TO_PENRO = [
        'cenro_baganga' => 'PENRO Davao Oriental',
        'cenro_lupon' => 'PENRO Davao Oriental',
        'cenro_manay' => 'PENRO Davao Oriental',
        'cenro_mati' => 'PENRO Davao Oriental',
    ];

    public function __construct(private readonly OrganizationalAccessService $organization) {}

    /** @param array<string,mixed> $sourceDefinition */
    public function resolve(string $source, Model $record, array $sourceDefinition): ?string
    {
        $officeAttribute = $source === 'engp'
            ? 'office'
            : ($sourceDefinition['target_office'] ?? 'target_office');
        $rawOrigin = $record->getAttribute($officeAttribute);
        if (! is_string($rawOrigin) || trim($rawOrigin) === '') return null;

        $protectedAreaId = (int) $record->getAttribute('protected_area_id');
        if ($protectedAreaId > 0) {
            $area = $record->relationLoaded('protectedArea')
                ? $record->getRelation('protectedArea')
                : ProtectedArea::query()->find($protectedAreaId);
            if (! $area instanceof ProtectedArea) return null;

            $assignments = ProtectedAreaOfficeAssignment::query()
                ->where('protected_area_id', $protectedAreaId)
                ->where('assignment_type', 'supervising')
                ->with('office')
                ->limit(2)
                ->get();
            if ($assignments->count() !== 1) return null;

            $assignmentOffice = $assignments->first()->office;
            if (! $assignmentOffice || ! $assignmentOffice->is_active) return null;
            $expectedType = app(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class)
                ->isDirectProtectedArea($area) ? 'penro' : 'cenro';
            if ($assignmentOffice->office_type !== $expectedType) return null;

            $originMatches = OrganizationalOffice::query()
                ->whereKey($assignmentOffice->getKey())
                ->where('is_active', true)
                ->where('office_type', $expectedType)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($rawOrigin))])
                ->exists();
            if (! $originMatches) return null;

            if ($expectedType === 'penro') {
                return $this->canonicalActivePenro((string) $assignmentOffice->name);
            }

            if (! $this->provinceListContains((string) $area->province, 'Davao Oriental')) return null;
            return $this->penroForCenro((string) $assignmentOffice->code);
        }

        // ENGP is the only eligible Development source without a Protected Area.
        // Its source office must still resolve to the canonical active office
        // before the current CENRO-to-PENRO route is applied.
        if ($source !== 'engp' || ($sourceDefinition['archive_unit'] ?? null) !== 'Development Unit') return null;

        $origin = OrganizationalOffice::query()
            ->where('is_active', true)
            ->whereIn('office_type', ['cenro', 'penro'])
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($rawOrigin))])
            ->limit(2)
            ->get(['code', 'name', 'office_type']);
        if ($origin->count() !== 1) return null;

        return $origin->first()->office_type === 'penro'
            ? $this->canonicalActivePenro((string) $origin->first()->name)
            : $this->penroForCenro((string) $origin->first()->code);
    }

    private function penroForCenro(string $cenroCode): ?string
    {
        $penro = self::CENRO_TO_PENRO[$cenroCode] ?? null;
        return $penro ? $this->canonicalActivePenro($penro) : null;
    }

    /** Match a canonical province as a complete member of the stored comma/semicolon list. */
    private function provinceListContains(string $provinceValue, string $expectedProvince): bool
    {
        $parts = preg_split('/[,;]/u', $provinceValue);
        if (! is_array($parts) || $parts === [] || count($parts) > 4) return false;

        $members = [];
        foreach ($parts as $part) {
            $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', trim($part))));
            if ($normalized === '' || in_array($normalized, $members, true)) return false;
            $members[] = $normalized;
        }

        return in_array(mb_strtolower(trim($expectedProvince)), $members, true);
    }

    private function canonicalActivePenro(string $officeName): ?string
    {
        $canonical = $this->organization->normalizeOffice($officeName);
        if (! $canonical || ! in_array($canonical, $this->organization->penroOffices(), true)) return null;

        $matches = OrganizationalOffice::query()
            ->where('is_active', true)
            ->where('office_type', 'penro')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($canonical)])
            ->limit(2)
            ->get(['name']);

        return $matches->count() === 1 ? (string) $matches->first()->name : null;
    }
}
