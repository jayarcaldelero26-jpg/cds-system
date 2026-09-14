<?php

namespace App\Services\Authorization;

use App\Models\ProtectedArea;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;

final class OrganizationalAccessService
{
    public const CONSERVATION = 'conservation';
    public const DEVELOPMENT = 'development';
    public const CENRO_RECORDS = 'CENRO_RECORDS';
    public const CENRO_CHIEF = 'CENRO_CDS_CHIEF';
    public const CENRO_FOCAL = 'CENRO_CDS_FOCAL';
    public const PENRO_CHIEF = 'PENRO_CDS_CHIEF';
    public const PENRO_FOCAL = 'PENRO_CDS_FOCAL';
    public const PENRO_RECORDS = 'PENRO_RECORDS';
    public const OFFICE_PENRO = 'OFFICE_OF_THE_PENRO';
    public const PENRO_TSD_CHIEF = 'PENRO_TSD_CHIEF';
    public const PAMO = 'PAMO';
    public const ACCOUNT_ROLE_SUPER_ADMIN = 'Super Admin';
    public const ACCOUNT_ROLE_USER = 'User';
    public const OPERATIONAL_GROUP_CENRO = 'cenro';
    public const OPERATIONAL_GROUP_PENRO = 'penro';
    public const OPERATIONAL_GROUP_PAMO = 'pamo';

    private const OPERATIONAL_CATEGORIES = [self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL, self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF, self::PENRO_CHIEF, self::PENRO_FOCAL];

    /** @var list<string> */
    private const CENRO_FOCAL_PREPARATION_SOURCES = [
        'conservation', 'technical-reports', 'bms', 'bams', 'imea',
        'imea-maintenance', 'aws', 'ipaf', 'ipaf-management', 'ipaf-revenue',
        'management-plans',
    ];

    private const CATEGORY_LABELS = [
        self::CENRO_RECORDS => 'CENRO Records Unit',
        self::CENRO_CHIEF => 'CENRO CDS Chief',
        self::CENRO_FOCAL => 'CENRO CDS Focal Person',
        self::PENRO_CHIEF => 'PENRO CDS Chief',
        self::PENRO_FOCAL => 'PENRO CDS Focal Person',
        self::PENRO_RECORDS => 'PENRO Records Unit',
        self::OFFICE_PENRO => 'Office of the PENRO',
        self::PENRO_TSD_CHIEF => 'PENRO TSD Chief',
        self::PAMO => 'PAMO',
    ];

    private const ROLE_CATEGORY_MAP = [
        'CENRO Records Unit' => self::CENRO_RECORDS,
        'CENRO CDS Chief' => self::CENRO_CHIEF,
        'CENRO CDS Focal Person' => self::CENRO_FOCAL,
        'PENRO CDS Chief' => self::PENRO_CHIEF,
        'PENRO CDS Focal Person' => self::PENRO_FOCAL,
        'PENRO Records Unit' => self::PENRO_RECORDS,
        'Office of the PENRO' => self::OFFICE_PENRO,
        'PENRO TSD Chief' => self::PENRO_TSD_CHIEF,
        'PAMO' => self::PAMO,
    ];

    public function isGlobal(?User $user): bool
    {
        return $user?->hasAnyRole(['CDS Admin', self::ACCOUNT_ROLE_SUPER_ADMIN]) ?? false;
    }

    public function accountRole(?User $user): ?string
    {
        if (! $user) return null;
        return $this->isGlobal($user) ? self::ACCOUNT_ROLE_SUPER_ADMIN : self::ACCOUNT_ROLE_USER;
    }

    public function hasSubmissionTrackingScope(?User $user): bool
    {
        if (! $user || ! $user->is_active) return false;
        $category = $this->effectiveCategory($user);
        if (! in_array($category, self::OPERATIONAL_CATEGORIES, true)) return false;
        if ($this->isPenroCategory($category) && $user->protected_area_id !== null) return false;
        $assignment = $this->normalizeAssignment(['unit_assignment' => $user->unit_assignment, 'section' => $category, 'office_designated' => $user->office_designated, 'protected_area_id' => $user->protected_area_id]);
        try { $this->validateAssignment($assignment['unit_assignment'] ?? null, $category, $assignment['office_designated'] ?? null, $assignment['protected_area_id'] ?? null); } catch (ValidationException) { return false; }
        return $this->effectiveUnits($user) !== [];
    }

    public function canViewSubmissionTracking(?User $user): bool
    {
        if (! $user || ! $user->is_active) return false;
        if ($this->isGlobal($user) || $this->hasSubmissionTrackingScope($user)) return true;
        $legacy = in_array($user->section, ['CDS', 'ENGP'], true) || (blank($user->unit_assignment) && in_array($user->section, self::OPERATIONAL_CATEGORIES, true));
        return $legacy && $user->can('reports.view');
    }

    public function canViewConservationModules(?User $user): bool
    {
        return $this->canBrowseModuleUnit($user, self::CONSERVATION);
    }

    public function canViewDevelopmentModules(?User $user): bool
    {
        return $this->canBrowseModuleUnit($user, self::DEVELOPMENT);
    }

    /** @return list<string> */
    public function effectiveUnits(?User $user): array
    {
        if (! $user || ! $user->is_active) return [];
        if ($this->isGlobal($user)) return [self::CONSERVATION, self::DEVELOPMENT];
        $category = $this->effectiveCategory($user);
        if (in_array($category, [self::CENRO_CHIEF, self::CENRO_RECORDS, self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF, self::PENRO_CHIEF, self::PENRO_FOCAL], true)) return [self::CONSERVATION, self::DEVELOPMENT];
        if ($category === self::CENRO_FOCAL) return [self::CONSERVATION, self::DEVELOPMENT];
        if (in_array($user->unit_assignment, [self::CONSERVATION, self::DEVELOPMENT], true)) return [$user->unit_assignment];
        return $user->section === 'ENGP' ? [self::DEVELOPMENT] : ($user->section === 'CDS' ? [self::CONSERVATION] : []);
    }

    public function canBrowseModuleUnit(?User $user, string $unit): bool
    {
        if (! $user || ! $user->is_active || ! in_array($unit, [self::CONSERVATION, self::DEVELOPMENT], true)) return false;
        if ($this->isGlobal($user)) return true;
        if (! $this->hasSubmissionTrackingScope($user)) return false;
        $category = $this->effectiveCategory($user);
        if (in_array($category, [self::CENRO_RECORDS, self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF], true)) return false;
        return in_array($unit, $this->effectiveUnits($user), true) && in_array($category, [self::CENRO_CHIEF, self::CENRO_FOCAL, self::PENRO_CHIEF, self::PENRO_FOCAL, self::PAMO], true);
    }

    public function canBrowseModule(?User $user, string $source, string $unit): bool
    {
        return $this->canBrowseModuleUnit($user, $unit);
    }

    public function canViewSubmission(?User $user, Model $record): bool
    {
        if (! $user || ! $user->is_active) return false;
        if ($this->isGlobal($user)) return true;
        if ($record instanceof EngpReportSubmission) return $this->canViewDevelopmentRecord($user, $record);
        if ($record->getAttribute('protected_area_id') !== null) return $this->canAccessProtectedArea($user, $record->getAttribute('protected_area_id'));
        if ($this->isCenroCategory($this->effectiveCategory($user))) return $this->same($user->office_designated, $record->getAttribute('target_office') ?: $record->getAttribute('office')) || (blank($record->getAttribute('target_office')) && (int) $record->getAttribute('created_by') === (int) $user->getKey());
        return in_array($this->effectiveCategory($user), self::OPERATIONAL_CATEGORIES, true);
    }

    public function canViewSubmissionAttachment(?User $user, Model $record): bool
    {
        return $this->canViewSubmission($user, $record);
    }

    public function canActOnSubmission(?User $user, Model $record): bool
    {
        return $this->canViewSubmission($user, $record) && $this->hasSubmissionTrackingScope($user);
    }

    public function canUseSubmissionTrackingSource(?User $user, string $source, string $legacyAbility): bool
    {
        if (! $this->canViewSubmissionTracking($user)) return false;
        if ($this->isGlobal($user)) return true;
        if ($source === 'engp') return in_array(self::DEVELOPMENT, $this->effectiveUnits($user), true);
        return in_array(self::CONSERVATION, $this->effectiveUnits($user), true);
    }

    /**
     * Category-based preparation authority for PA sources. This is deliberately
     * separate from module permissions: the route/controller still enforces
     * the source's own validation and protected-area scope.
     */
    public function canPrepareProtectedAreaSource(?User $user, string $source): bool
    {
        if (! $user || ! $user->is_active || $this->isGlobal($user)) return false;
        if (! in_array(self::CONSERVATION, $this->effectiveUnits($user), true)) return false;
        if ($this->effectiveCategory($user) !== self::CENRO_FOCAL) return false;
        if (! in_array(strtolower(trim($source)), self::CENRO_FOCAL_PREPARATION_SOURCES, true)) return false;

        return in_array($this->normalizeOffice($user->office_designated), $this->cenroOffices(), true);
    }

    public function canCreateProtectedAreaSource(?User $user, string $source): bool
    {
        return $this->canPrepareProtectedAreaSource($user, $source);
    }

    public function accountRoleOptions(): array
    {
        return [['value' => self::ACCOUNT_ROLE_USER, 'label' => self::ACCOUNT_ROLE_USER], ['value' => self::ACCOUNT_ROLE_SUPER_ADMIN, 'label' => self::ACCOUNT_ROLE_SUPER_ADMIN]];
    }

    public function unitFor(?User $user): ?string
    {
        if (! $user) return null;
        $explicit = strtolower(trim((string) $user->unit_assignment));
        if (in_array($explicit, [self::CONSERVATION, self::DEVELOPMENT], true)) return $explicit;
        return match ($user->section) {
            'ENGP' => self::DEVELOPMENT,
            'CDS' => self::CONSERVATION,
            default => null,
        };
    }

    public function canAccessUnit(?User $user, string $unit): bool
    {
        if (! $user || ! in_array($unit, [self::CONSERVATION, self::DEVELOPMENT], true)) return false;
        if ($this->isGlobal($user)) return true;
        return in_array($unit, $this->effectiveUnits($user), true);
    }

    public function canAccessProtectedArea(?User $user, mixed $protectedAreaId): bool
    {
        if (! $user || ! is_numeric($protectedAreaId)) return false;
        if ($this->isGlobal($user)) return true;
        if (! $this->canAccessUnit($user, self::CONSERVATION)) return false;
        if ($this->isCenroCategory($this->effectiveCategory($user))) {
            $officeCode = $this->officeCode($user->office_designated);
            if ($officeCode === null) return false;
            $hasAssignment = ProtectedAreaOfficeAssignment::query()->where('protected_area_id', (int) $protectedAreaId)->where('assignment_type', 'supervising')->whereHas('office', fn ($query) => $query->where('code', $officeCode)->where('office_type', 'cenro')->where('is_active', true))->exists();
            if ($hasAssignment) return true;
            return $this->same($this->supervisingOfficeNameForProtectedArea((int) $protectedAreaId), $user->office_designated);
        }
        return $this->isPenroCategory($this->effectiveCategory($user));
    }

    public function assertCanAccessProtectedArea(?User $user, mixed $protectedAreaId): void { abort_unless($this->canAccessProtectedArea($user, $protectedAreaId), 403); }

    public function assertCanUseOptionalProtectedArea(User $user, mixed $protectedAreaId): void
    {
        if ($protectedAreaId === null || $protectedAreaId === '') { abort_unless($this->effectiveCategory($user) !== self::PAMO, 403); return; }
        $this->assertCanAccessProtectedArea($user, $protectedAreaId);
    }

    public function scopeProtectedAreaQuery(Builder $query, User $user, string $column = 'protected_area_id'): Builder
    {
        if (! $this->canAccessUnit($user, self::CONSERVATION)) return $query->whereRaw('1 = 0');
        if ($this->isGlobal($user)) return $query;
        $category = $this->effectiveCategory($user);
        if (! $this->isCenroCategory($category)) return $this->isPenroCategory($category) ? $query : $query->whereRaw('1 = 0');
        $officeCode = $this->officeCode($user->office_designated);
        if ($officeCode === null) return $query->whereRaw('1 = 0');
        $table = $query->getModel()->getTable();
        $assignedProtectedAreaIds = ProtectedAreaOfficeAssignment::query()
            ->join('organizational_offices as oo', 'oo.id', '=', 'protected_area_office_assignments.organizational_office_id')
            ->where('protected_area_office_assignments.assignment_type', 'supervising')
            ->where('oo.code', $officeCode)
            ->where('oo.office_type', 'cenro')
            ->where('oo.is_active', true)
            ->pluck('protected_area_office_assignments.protected_area_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $assignedAnywhereIds = ProtectedAreaOfficeAssignment::query()
            ->where('assignment_type', 'supervising')
            ->pluck('protected_area_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $fallbackProtectedAreaIds = array_values(array_diff($this->fallbackProtectedAreaIdsForOffice($user->office_designated), $assignedAnywhereIds));
        return $query->where(function ($scoped) use ($table, $column, $assignedProtectedAreaIds, $fallbackProtectedAreaIds): void {
            if ($assignedProtectedAreaIds !== []) $scoped->whereIn($table.'.'.$column, $assignedProtectedAreaIds);
            if ($fallbackProtectedAreaIds !== []) {
                $method = $assignedProtectedAreaIds === [] ? 'whereIn' : 'orWhereIn';
                $scoped->{$method}($table.'.'.$column, $fallbackProtectedAreaIds);
            }
            if ($assignedProtectedAreaIds === [] && $fallbackProtectedAreaIds === []) $scoped->whereRaw('1 = 0');
        });
    }
    public function canAccessProtectedAreaRecord(?User $user, Model $record): bool
    {
        if ($record instanceof ProtectedArea) return $this->canAccessProtectedArea($user, $record->getKey());
        $protectedAreaId = $record->getAttribute('protected_area_id');
        if ($protectedAreaId !== null) return $this->canAccessProtectedArea($user, $protectedAreaId);
        if (! $user || ! $this->canAccessUnit($user, self::CONSERVATION)) return false;
        if ($this->isGlobal($user)) return true;
        if ($this->isCenroCategory($this->effectiveCategory($user))) return $this->same($user->office_designated, $record->getAttribute('target_office')) || (blank($record->getAttribute('target_office')) && (int) $record->getAttribute('created_by') === (int) $user->getKey());
        return true;
    }

    public function canActOnProtectedAreaRecord(?User $user, Model $record): bool { return $this->canAccessProtectedAreaRecord($user, $record); }

    public function canAccessOfficeRecord(?User $user, Model|string|null $recordOrOffice): bool
    {
        if (! $user || ! $this->canAccessUnit($user, self::DEVELOPMENT)) return false;
        if ($this->isGlobal($user)) return true;
        $office = is_string($recordOrOffice) ? $recordOrOffice : $recordOrOffice?->getAttribute('office');
        return $this->unitFor($user) === null || $this->same($user->office_designated, $office);
    }

    public function officeOptions(): array
    {
        return OrganizationalOffice::query()->whereIn('name', [...$this->cenroOffices(), ...$this->penroOffices()])->orderByRaw("CASE office_type WHEN 'cenro' THEN 1 ELSE 2 END")->orderBy('name')->get(['id', 'code', 'name', 'office_type'])->map(fn (OrganizationalOffice $office): array => ['id' => $office->id, 'code' => $office->code, 'name' => $office->name, 'label' => $office->office_type === 'penro' ? 'PENRO Davao Oriental' : $office->name, 'office_type' => $office->office_type, 'is_active' => true])->all();
    }

    public function assignSupervisingOffice(ProtectedArea $protectedArea, int $officeId, User $actor): void
    {
        abort_unless($this->isGlobal($actor) || $actor->can('protected-areas.create') || $actor->can('protected-areas.update'), 403);
        $office = OrganizationalOffice::query()->where('is_active', true)->findOrFail($officeId);
        ProtectedAreaOfficeAssignment::updateOrCreate(['protected_area_id' => $protectedArea->getKey(), 'assignment_type' => 'supervising'], ['organizational_office_id' => $office->getKey(), 'assigned_by' => $actor->getKey()]);
    }

    public function supervisingOfficeId(ProtectedArea $protectedArea): ?int { return $protectedArea->supervisingOfficeAssignment?->organizational_office_id; }

    private function isCenroCategory(?string $category): bool { return in_array($category, [self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL], true); }

    private function officeCode(?string $office): ?string
    {
        $normalized = $this->normalizeOffice($office);
        if (! $normalized) return null;
        $code = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $normalized));
        return trim($code, '_') ?: null;
    }

    public function assertAllProtectedAreasAccessible(User $user, iterable $protectedAreaIds): void { foreach ($protectedAreaIds as $id) $this->assertCanAccessProtectedArea($user, $id); }

    /**
     * Canonical registration and account-management presentation catalog.
     * Operational Group is presentation metadata; category, office and unit
     * remain the authoritative effective-access inputs.
     */
    public function operationalGroups(): array
    {
        return [
            ['value' => self::OPERATIONAL_GROUP_CENRO, 'label' => 'CENRO Office', 'categories' => $this->optionsFor([self::CENRO_FOCAL, self::CENRO_CHIEF, self::CENRO_RECORDS]), 'unit_assignment' => null],
            ['value' => self::OPERATIONAL_GROUP_PENRO, 'label' => 'PENRO Office', 'categories' => $this->optionsFor([self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF, self::PENRO_FOCAL, self::PENRO_CHIEF]), 'unit_assignment' => null],
        ];
    }

    public function categoriesForUnit(string $unit): array
    {
        return [];
    }

    public function categoryForRole(?string $role): ?string { return $this->normalizeCategory($role); }
    public function roleForCategory(?string $category): ?string
    {
        $canonical = $this->normalizeCategory($category);
        return $canonical ? (array_search($canonical, self::ROLE_CATEGORY_MAP, true) ?: null) : null;
    }
    public function operationalCategories(): array { return self::OPERATIONAL_CATEGORIES; }

    /**
     * Normalize stored/display category aliases at read time. Existing user
     * values are intentionally left untouched so legacy accounts remain
     * auditable while all authorization and queue checks use one identity.
     */
    public function normalizeCategory(?string $category): ?string
    {
        $value = trim((string) $category);
        if ($value === '') return null;

        $key = strtoupper(trim((string) preg_replace('/[^A-Z0-9]+/i', '_', $value), '_'));
        return match ($key) {
            'CENRO_RECORDS', 'CENRO_RECORDS_UNIT' => self::CENRO_RECORDS,
            'CENRO_CDS_CHIEF', 'CENRO_CHIEF', 'CENRO_CDS_CHIEF_PERSON' => self::CENRO_CHIEF,
            'CENRO_CDS_FOCAL', 'CENRO_FOCAL', 'CENRO_CDS_FOCAL_PERSON' => self::CENRO_FOCAL,
            'PENRO_CDS_CHIEF', 'PENRO_CHIEF', 'PENRO_CDS_CHIEF_PERSON' => self::PENRO_CHIEF,
            'PENRO_CDS_FOCAL', 'PENRO_FOCAL', 'PENRO_CDS_FOCAL_PERSON' => self::PENRO_FOCAL,
            'PENRO_RECORDS', 'PENRO_RECORDS_UNIT' => self::PENRO_RECORDS,
            'OFFICE_OF_THE_PENRO', 'OFFICE_PENRO' => self::OFFICE_PENRO,
            'PENRO_TSD_CHIEF', 'PENRO_TSD' => self::PENRO_TSD_CHIEF,
            'PAMO' => self::PAMO,
            default => null,
        };
    }

    public function categoryOptions(string $unit, bool $includeCustodyCategories = true): array
    {
        return $this->optionsFor($this->categoriesForUnit($unit));
    }

    public function operationalGroupForCategory(?string $category, ?string $unit = null): ?string
    {
        $category = $this->normalizeCategory($category);
        return match ($category) {
            self::CENRO_FOCAL, self::CENRO_CHIEF, self::CENRO_RECORDS => self::OPERATIONAL_GROUP_CENRO,
            self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF, self::PENRO_FOCAL, self::PENRO_CHIEF => self::OPERATIONAL_GROUP_PENRO,
            default => null,
        };
    }

    private function optionsFor(array $categories): array
    {
        return array_map(fn (string $category): array => ['value' => $category, 'label' => self::CATEGORY_LABELS[$category]], $categories);
    }
    public function categoryLabel(?string $category): ?string
    {
        $canonical = $this->normalizeCategory($category);
        return $canonical ? (self::CATEGORY_LABELS[$canonical] ?? null) : null;
    }

    public function permissionProfileForCategory(string $category): array
    {
        return match ($category) {
            self::CENRO_CHIEF, self::CENRO_FOCAL, self::PENRO_CHIEF, self::PENRO_FOCAL => ['reports.view', 'technical-reports.view', 'technical-reports.create', 'technical-reports.update'],
            self::CENRO_RECORDS, self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF => ['reports.view'],
            default => [],
        };
    }

    public function effectiveCategory(User $user): ?string
    {
        $section = $this->normalizeCategory($user->section);
        return in_array($section, self::OPERATIONAL_CATEGORIES, true) ? $section : null;
    }
    public function normalizeAssignment(array $data): array
    {
        $group = $data['operational_group'] ?? null;
        $category = $this->normalizeCategory($data['section'] ?? null);
        if ($category !== null) $data['section'] = $category;
        // PAMO is the only category in its operational group. Derive it when
        // a caller omits the presentation-only category field, while keeping
        // an explicitly tampered non-PAMO value available for validation.
        if ($group === self::OPERATIONAL_GROUP_PAMO && blank($category)) {
            $category = self::PAMO;
            $data['section'] = $category;
        }
        if (array_key_exists('office_designated', $data)) $data['office_designated'] = $this->normalizeOffice($data['office_designated']);
        if ($this->isPenroCategory($category)) {
            $data['unit_assignment'] = null;
            $data['protected_area_id'] = null;
            $data['office_designated'] = $this->penroOffices()[0] ?? null;
            return $data;
        }
        if (in_array($category, [self::CENRO_CHIEF, self::CENRO_RECORDS], true)) {
            $data['unit_assignment'] = null;
            $data['protected_area_id'] = null;
            return $data;
        }
        if ($category === self::CENRO_FOCAL) {
            $data['unit_assignment'] = null;
            $data['protected_area_id'] = null;
            return $data;
        }
        if ($category === self::PAMO) {
            $data['unit_assignment'] = null;
            if (blank($data['office_designated'] ?? null) && filled($data['protected_area_id'] ?? null)) $data['office_designated'] = $this->supervisingOfficeNameForProtectedArea((int) $data['protected_area_id']);
            return $data;
        }
        if (($data['unit_assignment'] ?? null) === self::DEVELOPMENT) $data['protected_area_id'] = null;
        return $data;
    }

    public function supervisingOfficeNameForProtectedArea(?int $protectedAreaId): ?string
    {
        if (! $protectedAreaId) return null;
        $area = ProtectedArea::query()->with('supervisingOfficeAssignment.office')->find($protectedAreaId);
        if (! $area) return null;
        $assigned = $area->supervisingOfficeAssignment?->office?->name;
        if (filled($assigned)) return $this->normalizeOffice($assigned);
        foreach ([
            ['short_names' => ['MHRWS'], 'full_names' => ['Mt. Hamiguitan Range Wildlife Sanctuary', 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)'], 'office' => 'PENRO Davao Oriental'],
            ['short_names' => ['APL'], 'full_names' => ['Aliwagwag Protected Landscape', 'Aliwagwag Protected Landscape (APL)'], 'office' => 'CENRO Baganga'],
            ['short_names' => ['BPL'], 'full_names' => [], 'office' => 'CENRO Baganga'],
            ['short_names' => ['BMSFR'], 'full_names' => [], 'office' => 'CENRO Baganga'],
            ['short_names' => ['MPL'], 'full_names' => ['Mati Protected Landscape', 'Mati Protected Landscape (MPL)'], 'office' => 'CENRO Mati'],
            ['short_names' => ['PBPLS'], 'full_names' => ['Pujada Bay Protected Landscape and Seascape', 'Pujada Bay Protected Landscape and Seascape (PBPLS)'], 'office' => 'CENRO Mati'],
        ] as $identity) {
            if ($this->protectedAreaIdentityMatches($area, $identity['short_names'], $identity['full_names'])) return $identity['office'];
        }
        return null;
    }

    private function protectedAreaIdentityMatches(ProtectedArea $area, array $shortNames, array $fullNames): bool
    {
        $shortName = $this->normalizeProtectedAreaIdentity($area->short_name);
        if ($shortName !== '' && in_array($shortName, array_map(fn (string $value): string => $this->normalizeProtectedAreaIdentity($value), $shortNames), true)) return true;

        $name = $this->normalizeProtectedAreaIdentity($area->name);
        return $name !== '' && in_array($name, array_map(fn (string $value): string => $this->normalizeProtectedAreaIdentity($value), $fullNames), true);
    }

    private function normalizeProtectedAreaIdentity(?string $identity): string
    {
        $normalized = mb_strtolower(trim((string) $identity));
        $normalized = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);
        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    private function fallbackProtectedAreaIdsForOffice(?string $office): array
    {
        $normalizedOffice = $this->normalizeOffice($office);
        if (! $normalizedOffice) return [];
        return ProtectedArea::query()->pluck('id')->filter(fn (mixed $id): bool => $this->same($this->supervisingOfficeNameForProtectedArea((int) $id), $normalizedOffice))->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    public function canonicalOffices(): array
    {
        return ['CENRO Baganga', 'CENRO Lupon', 'CENRO Manay', 'CENRO Mati', 'PENRO Davao Oriental'];
    }

    public function normalizeOffice(?string $office): ?string
    {
        $value = trim((string) $office);
        if ($value === '') return null;
        if (mb_strtolower($value) === 'penro mati') return 'PENRO Davao Oriental';
        return collect($this->canonicalOffices())->first(fn (string $canonical): bool => mb_strtolower($canonical) === mb_strtolower($value)) ?? $value;
    }

    public function activeOfficeNames(): array { return OrganizationalOffice::query()->where('is_active', true)->orderBy('name')->pluck('name')->all(); }
    public function cenroOffices(): array { return ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon']; }
    public function penroOffices(): array { return ['PENRO Davao Oriental']; }

    public function validateAssignment(?string $unit, ?string $category, ?string $office, mixed $protectedAreaId, ?string $role = null, ?string $operationalGroup = null): void
    {
        if ($operationalGroup !== null && $operationalGroup !== '') $this->validateOperationalGroup($operationalGroup, $category, $unit);
        if (! in_array($category, self::OPERATIONAL_CATEGORIES, true)) throw ValidationException::withMessages(['section' => 'Select a valid user category.']);
        $canonicalOffice = $this->normalizeOffice($office);
        if ($category === self::CENRO_FOCAL) {
            if ($unit !== null && $unit !== '') throw ValidationException::withMessages(['unit_assignment' => 'CENRO CDS Focal Person covers both units and does not use a unit assignment.']);
            if (! in_array($canonicalOffice, $this->cenroOffices(), true)) throw ValidationException::withMessages(['office_designated' => 'Select a canonical CENRO office.']);
            if (filled($protectedAreaId)) throw ValidationException::withMessages(['protected_area_id' => 'CENRO Focal assignments do not use a protected-area assignment.']);
        } elseif (in_array($category, [self::CENRO_CHIEF, self::CENRO_RECORDS], true)) {
            if ($unit !== null && $unit !== '') throw ValidationException::withMessages(['unit_assignment' => 'This CENRO category covers both units and does not use a unit assignment.']);
            if (! in_array($canonicalOffice, $this->cenroOffices(), true)) throw ValidationException::withMessages(['office_designated' => 'Select a canonical CENRO office.']);
            if (filled($protectedAreaId)) throw ValidationException::withMessages(['protected_area_id' => 'This CENRO category does not use a protected-area assignment.']);
        } elseif ($this->isPenroCategory($category)) {
            if ($unit !== null && $unit !== '') throw ValidationException::withMessages(['unit_assignment' => 'PENRO categories cover both units and do not use a unit assignment.']);
            if (! in_array($canonicalOffice, $this->penroOffices(), true)) throw ValidationException::withMessages(['office_designated' => 'Select the canonical PENRO office.']);
            if (filled($protectedAreaId)) throw ValidationException::withMessages(['protected_area_id' => 'PENRO categories do not use a protected-area assignment.']);
        } elseif ($category === self::PAMO) {
            if (filled($unit) && $unit !== self::CONSERVATION) throw ValidationException::withMessages(['unit_assignment' => 'PAMO is a Conservation assignment.']);
            if (blank($protectedAreaId)) throw ValidationException::withMessages(['protected_area_id' => 'A protected-area assignment is required for PAMO users.']);
        } elseif (filled($unit) && ! in_array($unit, [self::CONSERVATION, self::DEVELOPMENT], true)) {
            throw ValidationException::withMessages(['unit_assignment' => 'Select a valid operational unit.']);
        }
        if (filled($canonicalOffice) && ! in_array($canonicalOffice, $this->activeOfficeNames(), true)) throw ValidationException::withMessages(['office_designated' => 'Select an active organizational office.']);
        if (filled($protectedAreaId) && ! ProtectedArea::query()->whereKey($protectedAreaId)->exists()) throw ValidationException::withMessages(['protected_area_id' => 'Select a valid protected area.']);
    }

    public function validateOperationalGroup(string $group, ?string $category, ?string $unit): void
    {
        $catalog = collect($this->operationalGroups())->firstWhere('value', $group);
        if (! $catalog) throw ValidationException::withMessages(['operational_group' => 'Select a valid operational group.']);

        $categories = collect($catalog['categories'])->pluck('value')->all();
        if (! in_array($category, $categories, true)) throw ValidationException::withMessages(['section' => 'Select a user category available for the chosen operational group.']);

        if (filled($unit)) throw ValidationException::withMessages(['unit_assignment' => 'Operational groups do not use a single-unit assignment.']);
    }
    public function canViewDevelopmentRecord(User $user, EngpReportSubmission $record): bool
    {
        if (! $this->canAccessUnit($user, self::DEVELOPMENT)) return false;
        if ($this->isGlobal($user) || $this->isPenroCategory($this->effectiveCategory($user))) return true;
        return $this->same($user->office_designated, $record->office);
    }

    public function canUseDevelopmentOffice(User $user, string $office): bool
    {
        return $this->canAccessUnit($user, self::DEVELOPMENT) && ($this->isGlobal($user) || $this->isPenroCategory($this->effectiveCategory($user)) || $this->same($user->office_designated, $office));
    }

    public function scopeDevelopmentQuery($query, User $user)
    {
        if (! $this->canAccessUnit($user, self::DEVELOPMENT)) return $query->whereRaw('1 = 0');
        if ($this->isGlobal($user) || $this->isPenroCategory($this->effectiveCategory($user))) return $query;
        $office = $this->normalizeOffice($user->office_designated) ?: '__no_office_scope__';
        return $query->whereRaw('LOWER(office) = ?', [mb_strtolower($office)]);
    }

    private function same(?string $left, ?string $right): bool { return trim((string) $left) !== '' && mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right)); }

    private function isPenroCategory(?string $category): bool
    {
        return in_array($category, [self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF, self::PENRO_CHIEF, self::PENRO_FOCAL], true);
    }
}
