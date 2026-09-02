<?php

namespace App\Services\SubmissionTracking;

use App\Models\ConservationReportSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Authorization\OrganizationalAccessService;

/** Category, capability, office, and PA scope for the additive CENRO/PAMO workflow. */
final class PambSubmissionAccessService
{
    public const CENRO_RECORDS = OrganizationalAccessService::CENRO_RECORDS;
    public const CENRO_CHIEF = OrganizationalAccessService::CENRO_CHIEF;
    public const CENRO_FOCAL = OrganizationalAccessService::CENRO_FOCAL;
    public const PENRO_CHIEF = OrganizationalAccessService::PENRO_CHIEF;
    public const PENRO_FOCAL = OrganizationalAccessService::PENRO_FOCAL;
    public const PAMO = OrganizationalAccessService::PAMO;

    /** @var list<string> */
    public const CENRO_CATEGORIES = [self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL];

    /** @var list<string> */
    public const PENRO_CATEGORIES = [self::PENRO_CHIEF, self::PENRO_FOCAL];

    public function isGlobal(User $user): bool
    {
        return app(OrganizationalAccessService::class)->isGlobal($user);
    }

    public function isCenro(User $user): bool
    {
        return in_array(app(OrganizationalAccessService::class)->effectiveCategory($user), self::CENRO_CATEGORIES, true);
    }

    public function isPamo(User $user): bool
    {
        return app(OrganizationalAccessService::class)->effectiveCategory($user) === OrganizationalAccessService::PAMO;
    }

    public function isPenro(User $user): bool
    {
        return in_array(app(OrganizationalAccessService::class)->effectiveCategory($user), self::PENRO_CATEGORIES, true);
    }

    public function canView(User $user, ConservationReportSubmission $submission): bool
    {
        if (! app(OrganizationalAccessService::class)->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) {
            return false;
        }
        if ($this->isGlobal($user) || $this->isPenro($user)) {
            return true;
        }

        if ($this->isCenro($user)) {
            return $this->same($user->office_designated, $submission->target_office)
                && ! app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission);
        }

        if ($this->isPamo($user)) {
            return $user->protected_area_id !== null
                && (int) $user->protected_area_id === (int) $submission->protected_area_id;
        }

        // Preserve existing permission-based visibility for legacy categories.
        return true;
    }

    public function scopeQuery(Builder $query, User $user): Builder
    {
        if (! app(OrganizationalAccessService::class)->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) return $query->whereRaw('1 = 0');
        if ($this->isGlobal($user) || $this->isPenro($user)) return $query;
        if ($this->isCenro($user)) {
            $office = app(OrganizationalAccessService::class)->normalizeOffice($user->office_designated) ?: '__no_office_scope__';
            return $query->whereRaw('LOWER(target_office) = ?', [mb_strtolower($office)]);
        }
        if ($this->isPamo($user)) return $query->where('protected_area_id', $user->protected_area_id ?: 0);
        return $query;
    }

    public function canPerform(User $user, string $action): bool
    {
        if (! app(OrganizationalAccessService::class)->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) return false;
        if ($this->isGlobal($user)) {
            return true;
        }

        return match ($action) {
            'submit' => in_array(app(OrganizationalAccessService::class)->effectiveCategory($user), [OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::PAMO], true),
            'review' => in_array(app(OrganizationalAccessService::class)->effectiveCategory($user), [OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::PENRO_CHIEF], true),
            'release' => app(OrganizationalAccessService::class)->effectiveCategory($user) === OrganizationalAccessService::CENRO_RECORDS,
            default => false,
        };
    }

    public function canUseDownstreamOperations(User $user): bool
    {
        return $this->isGlobal($user) || $this->isPenro($user);
    }

    private function same(?string $left, ?string $right): bool
    {
        return trim((string) $left) !== ''
            && mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }
}
