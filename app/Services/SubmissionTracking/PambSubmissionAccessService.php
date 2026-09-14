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
    public const PENRO_RECORDS = OrganizationalAccessService::PENRO_RECORDS;
    public const OFFICE_PENRO = OrganizationalAccessService::OFFICE_PENRO;
    public const PENRO_TSD_CHIEF = OrganizationalAccessService::PENRO_TSD_CHIEF;
    public const PAMO = OrganizationalAccessService::PAMO;

    /** @var list<string> */
    public const CENRO_CATEGORIES = [self::CENRO_RECORDS, self::CENRO_CHIEF, self::CENRO_FOCAL];

    /** @var list<string> */
    public const PENRO_CATEGORIES = [self::PENRO_RECORDS, self::OFFICE_PENRO, self::PENRO_TSD_CHIEF, self::PENRO_CHIEF, self::PENRO_FOCAL];

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
        $organization = app(OrganizationalAccessService::class);
        if (! $organization->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)
            || ! $organization->canAccessProtectedAreaRecord($user, $submission)) {
            return false;
        }
        if ($this->isGlobal($user) || $this->isPenro($user)) {
            return true;
        }

        if ($this->isCenro($user)) {
            return ($this->same($user->office_designated, $submission->target_office) || (blank($submission->target_office) && (int) $submission->created_by === (int) $user->getKey()))
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
        $organization = app(OrganizationalAccessService::class);
        if (! $organization->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) return $query->whereRaw('1 = 0');
        if ($this->isGlobal($user) || $this->isPenro($user)) return $query;
        if ($this->isCenro($user)) {
            $office = $organization->normalizeOffice($user->office_designated) ?: '__no_office_scope__';
            return $query->where(function (Builder $scoped) use ($organization, $user, $office): void {
                $organization->scopeProtectedAreaQuery($scoped, $user);
                $scoped->orWhere(function (Builder $officeScoped) use ($office): void {
                    $officeScoped->whereNull('protected_area_id')
                        ->whereRaw('LOWER(target_office) = ?', [mb_strtolower($office)]);
                });
            })->whereRaw('LOWER(target_office) = ?', [mb_strtolower($office)]);
        }
        if ($this->isPamo($user)) return $organization->scopeProtectedAreaQuery($query, $user);
        return $query;
    }

    public function canPerform(User $user, string $action): bool
    {
        if (! app(OrganizationalAccessService::class)->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) return false;

        return match ($action) {

            'submit' => in_array(app(OrganizationalAccessService::class)->effectiveCategory($user), [self::CENRO_FOCAL, self::PAMO], true),
            'review' => in_array(app(OrganizationalAccessService::class)->effectiveCategory($user), [self::CENRO_CHIEF, self::PENRO_CHIEF], true),
            'release' => app(OrganizationalAccessService::class)->effectiveCategory($user) === self::CENRO_RECORDS,
            default => false,
        };
    }

    /**
     * Authorizes a MOV decision or canonical milestone against the actual
     * protected-area routing context.
     */
    public function canPerformForSubmission(User $user, string $action, ConservationReportSubmission $submission): bool
    {
        if (! $this->canView($user, $submission)) return false;

        $category = app(OrganizationalAccessService::class)->effectiveCategory($user);

        return match ($action) {
            'submit' => in_array($category, [self::CENRO_FOCAL, self::PAMO], true)
                && in_array(app(PambMovProcessingService::class)->status($submission), [PambMovProcessingService::ACTIVITY_CONDUCTED, PambMovProcessingService::NEEDS_CORRECTION], true),
            // Direct-PENRO PAMB submissions enter the explicit PENRO Records
            // receipt queue before the shared PENRO internal chain.
            'review' => ! app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission)
                && $this->reviewCategoryFor($submission) === $category
                && app(PambMovProcessingService::class)->status($submission) === PambMovProcessingService::SUBMITTED_FOR_REVIEW,
            'release' => $category === self::CENRO_RECORDS
                && ! app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission)
                && $this->isAwaitingCenroRelease($submission),
            'penro_receipt', 'penro_records_receive' => $category === self::PENRO_RECORDS
                && $this->isAwaitingPenroReceipt($submission),
            'regional_endorsement', 'penro_records_release_regional' => $category === self::PENRO_RECORDS
                && $this->isNextRegionalStage($submission),
            default => false,
        };
    }

    public function canRecordInternalRouting(User $user, ConservationReportSubmission $submission, string $stage): bool
    {
        if (! $this->canView($user, $submission)) return false;
        $timeline = app(PambRoutingTimelineService::class);
        if (! $timeline->isInternalStageKey($stage)) return false;

        $baseStage = $timeline->canonicalStageKey($stage);
        $category = app(OrganizationalAccessService::class)->effectiveCategory($user);

        if ($baseStage === PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL) {
            return $category === self::OFFICE_PENRO && $timeline->isAwaitingOfficePenroFinalReceipt($submission);
        }

        if (in_array($baseStage, [PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL], true)) {
            return $category === self::OFFICE_PENRO && $timeline->isAwaitingFinalVerdict($submission);
        }

        $authorizedCategory = match ($baseStage) {
            PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
            PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL => self::PENRO_RECORDS,
            PambRoutingTimelineService::RECEIVED_BY_PENRO,
            PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS => self::OFFICE_PENRO,
            PambRoutingTimelineService::RECEIVED_BY_TSD,
            PambRoutingTimelineService::FORWARDED_TSD_TO_CDS => self::PENRO_TSD_CHIEF,
            PambRoutingTimelineService::RECEIVED_BY_CDS,
            PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF => self::PENRO_FOCAL,
            PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF,
            PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO => self::PENRO_CHIEF,
            default => null,
        };

        return $authorizedCategory === $category
            && $this->isNextInternalStage($submission, $stage);
    }

    public function canPerformCanonical(User $user, ConservationReportSubmission $submission, string $stage): bool
    {
        return match ($stage) {
            SubmissionTrackingService::PENRO_RECEIPT => $this->canPerformForSubmission($user, 'penro_receipt', $submission),
            SubmissionTrackingService::REGIONAL_ENDORSEMENT => $this->canPerformForSubmission($user, 'regional_endorsement', $submission),
            SubmissionTrackingService::CENRO_RELEASE => $this->canPerformForSubmission($user, 'release', $submission),
            default => false,
        };
    }

    private function reviewCategoryFor(ConservationReportSubmission $submission): string
    {
        return self::CENRO_CHIEF;
    }

    private function isAwaitingCenroRelease(ConservationReportSubmission $submission): bool
    {
        return ! app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission)
            && $submission->date_report_released_cenro === null
            && $submission->date_endorsed_regional === null
            && app(PambMovProcessingService::class)->status($submission) === PambMovProcessingService::READY_FOR_RELEASE;
    }

    private function isAwaitingPenroReceipt(ConservationReportSubmission $submission): bool
    {
        $direct = app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($submission);

        return $submission->date_received_penro === null
            && ($direct || $submission->date_report_released_cenro !== null);
    }

    private function isNextInternalStage(ConservationReportSubmission $submission, string $stage): bool
    {
        if (! app(PambRoutingTimelineService::class)->applies($submission)) return false;

        $current = collect(app(PambRoutingTimelineService::class)->present($submission)['timeline'])
            ->firstWhere('status', 'current');

        return ($current['key'] ?? null) === $stage;
    }

    private function isNextRegionalStage(ConservationReportSubmission $submission): bool
    {
        if (! app(PambRoutingTimelineService::class)->applies($submission)) {
            return $submission->date_received_penro !== null
                && $submission->date_endorsed_regional === null;
        }

        $current = collect(app(PambRoutingTimelineService::class)->present($submission)['timeline'])
            ->firstWhere('status', 'current');

        return ($current['key'] ?? null) === PambRoutingTimelineService::RELEASED_TO_REGIONAL;
    }

    private function canonicalInternalStage(string $stage): string
    {
        return match ($stage) {
            'records_to_penro' => PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
            'penro_to_tsd' => PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
            'tsd_to_cds' => PambRoutingTimelineService::FORWARDED_TSD_TO_CDS,
            'cds_to_penro' => PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
            'penro_to_records' => PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS,
            default => $stage,
        };
    }

    private function same(?string $left, ?string $right): bool
    {
        return trim((string) $left) !== ''
            && mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }
}
