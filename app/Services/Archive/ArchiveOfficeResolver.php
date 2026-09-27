<?php

namespace App\Services\Archive;

use App\Models\OrganizationalOffice;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Resolves a submission's stable originating CENRO from source ownership. */
final class ArchiveOfficeResolver
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    /** @param array<string, mixed> $source */
    public function resolve(array $source, Model $record): string
    {
        $officeResolver = $source['archive_office'] ?? null;
        $officeAttribute = $source['archive_office_attribute'] ?? $source['target_office'] ?? 'target_office';
        $sourceOffice = is_callable($officeResolver)
            ? $officeResolver($record)
            : (is_string($officeAttribute) ? $record->getAttribute($officeAttribute) : null);
        $assignedOffice = $this->supervisingCenroFor($record);

        if (filled($sourceOffice) && $assignedOffice && strcasecmp(trim((string) $sourceOffice), $assignedOffice->name) !== 0) {
            $this->unmapped();
        }

        $name = filled($sourceOffice) ? trim((string) $sourceOffice) : $assignedOffice?->name;
        if (! $name) $this->unmapped();

        $matches = OrganizationalOffice::query()
            ->where('is_active', true)
            ->where('office_type', 'cenro')
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->limit(2)
            ->get(['id', 'name']);

        if ($matches->count() !== 1 || ($assignedOffice && (int) $matches->first()->id !== (int) $assignedOffice->id)) {
            $this->unmapped();
        }

        return (string) $matches->first()->name;
    }

    private function supervisingCenroFor(Model $record): ?OrganizationalOffice
    {
        if (! filled($record->getAttribute('protected_area_id'))) {
            return null;
        }

        $officeName = $this->organization->supervisingOfficeNameForProtectedArea((int) $record->getAttribute('protected_area_id'));
        if (blank($officeName)) return null;

        $matches = OrganizationalOffice::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($officeName))])
            ->limit(2)->get(['id', 'name', 'office_type', 'is_active']);
        if ($matches->count() !== 1 || ! $matches->first()->is_active || $matches->first()->office_type !== 'cenro') {
            $this->unmapped();
        }

        return $matches->first();
    }

    private function unmapped(): never
    {
        throw ValidationException::withMessages([
            'archive' => 'ARCHIVE OFFICE UNMAPPED: this submission has no verified originating CENRO office.',
        ]);
    }
}
