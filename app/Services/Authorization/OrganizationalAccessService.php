<?php

namespace App\Services\Authorization;

use App\Models\ProtectedArea;
use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Illuminate\Validation\ValidationException;

/** Central policy for organizational unit and assignment dimensions. */
final class OrganizationalAccessService
{
    public const CONSERVATION = 'conservation';
    public const DEVELOPMENT = 'development';
    public const CENRO_RECORDS = 'CENRO_RECORDS';
    public const CENRO_CHIEF = 'CENRO_CDS_CHIEF';
    public const CENRO_FOCAL = 'CENRO_CDS_FOCAL';
    public const PENRO_CHIEF = 'PENRO_CDS_CHIEF';
    public const PENRO_FOCAL = 'PENRO_CDS_FOCAL';
    public const PAMO = 'PAMO';

    private const OPERATIONAL_CATEGORIES = [self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL, self::PENRO_CHIEF, self::PENRO_FOCAL, self::PAMO];

    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        self::CENRO_RECORDS => 'CENRO Records Unit',
        self::CENRO_CHIEF => 'CENRO CDS Chief',
        self::CENRO_FOCAL => 'CENRO CDS Focal Person',
        self::PENRO_CHIEF => 'PENRO CDS Chief',
        self::PENRO_FOCAL => 'PENRO CDS Focal Person',
        self::PAMO => 'PAMO',
    ];

    /** @var array<string, string> */
    private const ROLE_CATEGORY_MAP = [
        'CENRO Records Unit' => self::CENRO_RECORDS,
        'CENRO CDS Chief' => self::CENRO_CHIEF,
        'CENRO CDS Focal Person' => self::CENRO_FOCAL,
        'PENRO CDS Chief' => self::PENRO_CHIEF,
        'PENRO CDS Focal Person' => self::PENRO_FOCAL,
        'PAMO' => self::PAMO,
    ];

    public function isGlobal(?User $user): bool
    {
        return $user?->hasAnyRole(['CDS Admin', 'Super Admin']) ?? false;
    }

    public function unitFor(?User $user): ?string
    {
        if (! $user) return null;
        $explicit = strtolower(trim((string) $user->unit_assignment));
        if (in_array($explicit, [self::CONSERVATION, self::DEVELOPMENT], true)) return $explicit;
        return match ($user->section) {
            'ENGP' => self::DEVELOPMENT,
            // Legacy CDS rows have an unambiguous Conservation ownership. The
            // value remains a compatibility fallback only; new accounts must
            // persist unit_assignment explicitly.
            'CDS' => self::CONSERVATION,
            'PAMO', self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL, self::PENRO_CHIEF, self::PENRO_FOCAL => self::CONSERVATION,
            default => null,
        };
    }

    public function canAccessUnit(?User $user, string $unit): bool
    {
        if (! $user || ! in_array($unit, [self::CONSERVATION, self::DEVELOPMENT], true)) return false;
        if ($this->isGlobal($user)) return true;
        $assigned = $this->unitFor($user);
        // Preserve access for legacy rows that predate unit_assignment. New
        // organizational accounts always persist an explicit unit and are
        // restricted by it; ambiguous legacy rows remain an admin-cleanup
        // concern rather than changing existing operational behavior here.
        return $assigned === null || $assigned === $unit;
    }

    public function categoriesForUnit(string $unit): array
    {
        return $unit === self::DEVELOPMENT ? array_values(array_filter(self::OPERATIONAL_CATEGORIES, fn (string $category): bool => $category !== self::PAMO)) : self::OPERATIONAL_CATEGORIES;
    }

    public function categoryForRole(?string $role): ?string
    {
        return $role ? (self::ROLE_CATEGORY_MAP[$role] ?? null) : null;
    }

    public function roleForCategory(?string $category): ?string
    {
        return $category ? array_search($category, self::ROLE_CATEGORY_MAP, true) ?: null : null;
    }

    /** @return list<string> */
    public function operationalCategories(): array
    {
        return self::OPERATIONAL_CATEGORIES;
    }

    /** @return list<array{value: string, label: string}> */
    public function categoryOptions(string $unit): array
    {
        return array_map(
            fn (string $category): array => ['value' => $category, 'label' => self::CATEGORY_LABELS[$category]],
            $this->categoriesForUnit($unit),
        );
    }

    public function categoryLabel(?string $category): ?string
    {
        return $category ? (self::CATEGORY_LABELS[$category] ?? null) : null;
    }

    /**
     * These are technical permission groups only. Workflow identity comes from
     * the category; the internal Spatie role is synchronized from it.
     *
     * @return list<string>
     */
    public function permissionProfileForCategory(string $category): array
    {
        return match ($category) {
            self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL, self::PENRO_CHIEF, self::PENRO_FOCAL, self::PAMO => [
                'reports.view',
                'technical-reports.view',
                'technical-reports.create',
                'technical-reports.update',
            ],
            default => [],
        };
    }

    public function effectiveCategory(User $user): ?string
    {
        // section is the persisted User Category/business identity. The role
        // fallback is only for legacy rows that predate the category model.
        return in_array($user->section, self::OPERATIONAL_CATEGORIES, true)
            ? $user->section
            : ($this->categoryForRole($user->roles()->first()?->name) ?? $user->section);
    }

    /** Normalize role-owned dependent fields before validation/persistence. */
    public function normalizeAssignment(array $data): array
    {
        $category = $this->categoryForRole($data['role'] ?? null) ?? ($data['section'] ?? null);
        if (array_key_exists('office_designated', $data)) {
            $data['office_designated'] = $this->normalizeOffice($data['office_designated']);
        }
        if ($category) {
            if ($category !== self::PAMO) $data['protected_area_id'] = null;
        } elseif (($data['unit_assignment'] ?? null) === self::DEVELOPMENT) {
            $data['protected_area_id'] = null;
        }

        return $data;
    }

    public function canonicalOffices(): array
    {
        $cenro = collect(app(EngpReportWorkflowRegistry::class)->all())->flatMap(fn (array $workflow): array => $workflow['offices'] ?? [])->filter(fn (mixed $office): bool => is_string($office) && str_starts_with($office, 'CENRO '));
        return $cenro->merge(['PENRO Davao Oriental', 'PENRO Mati'])->unique()->sort()->values()->all();
    }

    public function normalizeOffice(?string $office): ?string
    {
        $value = trim((string) $office);
        if ($value === '') return null;

        return collect($this->canonicalOffices())
            ->first(fn (string $canonical): bool => mb_strtolower($canonical) === mb_strtolower($value))
            ?? $value;
    }

    public function cenroOffices(): array
    {
        return array_values(array_filter($this->canonicalOffices(), fn (string $office): bool => str_starts_with($office, 'CENRO ')));
    }

    public function penroOffices(): array
    {
        return array_values(array_filter($this->canonicalOffices(), fn (string $office): bool => str_starts_with($office, 'PENRO ')));
    }

    public function validateAssignment(?string $unit, ?string $category, ?string $office, mixed $protectedAreaId, ?string $role = null): void
    {
        if (blank($unit)) return;
        if (! in_array($unit, [self::CONSERVATION, self::DEVELOPMENT], true)) throw ValidationException::withMessages(['unit_assignment' => 'Select a valid operational unit.']);
        if (! in_array($category, $this->categoriesForUnit($unit), true)) throw ValidationException::withMessages(['section' => 'That user category is not available for the selected unit.']);
        if (($expectedCategory = $this->categoryForRole($role)) && $category !== $expectedCategory) throw ValidationException::withMessages(['section' => 'The user category must match the assigned access role.']);
        if ($unit === self::DEVELOPMENT && filled($protectedAreaId)) throw ValidationException::withMessages(['protected_area_id' => 'Development users cannot be assigned to a protected area.']);
        if ($category === self::PAMO && blank($protectedAreaId)) throw ValidationException::withMessages(['protected_area_id' => 'A protected-area assignment is required for PAMO users.']);
        $canonicalOffice = $this->normalizeOffice($office);
        if (str_starts_with((string) $category, 'CENRO_') && ! in_array($canonicalOffice, $this->cenroOffices(), true)) throw ValidationException::withMessages(['office_designated' => 'Select a canonical CENRO office for this category.']);
        if (str_starts_with((string) $category, 'PENRO_') && ! in_array($canonicalOffice, $this->penroOffices(), true)) throw ValidationException::withMessages(['office_designated' => 'Select a canonical PENRO office for this category.']);
        if (filled($protectedAreaId) && ! ProtectedArea::query()->whereKey($protectedAreaId)->exists()) throw ValidationException::withMessages(['protected_area_id' => 'Select a valid protected area.']);
    }

    public function canViewDevelopmentRecord(User $user, EngpReportSubmission $record): bool
    {
        if (! $this->canAccessUnit($user, self::DEVELOPMENT)) return false;
        if ($this->isGlobal($user) || $this->unitFor($user) === null) return true;
        return $this->same($user->office_designated, $record->office);
    }

    public function canUseDevelopmentOffice(User $user, string $office): bool
    {
        return $this->canAccessUnit($user, self::DEVELOPMENT)
            && ($this->isGlobal($user) || $this->unitFor($user) === null || $this->same($user->office_designated, $office));
    }

    public function scopeDevelopmentQuery($query, User $user)
    {
        if ($this->isGlobal($user) || $this->unitFor($user) === null) return $query;
        $office = $this->normalizeOffice($user->office_designated) ?: '__no_office_scope__';
        return $query->whereRaw('LOWER(office) = ?', [mb_strtolower($office)]);
    }

    private function same(?string $left, ?string $right): bool
    {
        return trim((string) $left) !== '' && mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }
}
