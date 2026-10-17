<?php

namespace App\Services\SubmissionTracking;

use App\Models\EngpReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use App\Support\DatePresentationNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The single presentation contract for report routing status.
 *
 * This deliberately answers a routing question only. Deadline/timeliness
 * calculations remain owned by each report workflow.
 */
final class RoutingStatusPresenter
{
    public const NO_ACTIVITY = 'No Activity Conducted';
    public const PENDING_CENRO = 'Pending Submission by CENRO';
    public const PENDING_PENRO = 'Pending Receipt by PENRO';
    public const PENDING_REGIONAL = 'Pending Regional Endorsement';
    public const COMPLETED = 'Completed';

    public function __construct(
        private readonly EngpReportWorkflowRegistry $engpWorkflows,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
        private readonly PambComplianceCalculator $pambCompliance,
        private readonly DocumentRoutingTransitionService $genericRouting,
        private readonly PamoOriginSchemaReadiness $pamoOrigins,
    ) {}

    public function status(Model $record, ?string $sourceKey = null, ?Collection $routingEvents = null, ?string $routeProfile = null): string
    {
        $sourceKey ??= $this->sourceKey($record);
        $isPamoOrigin = $this->pamoOrigins->isPamoConservationOrigin($record, $sourceKey);
        if ($isPamoOrigin) {
            $routing = $this->genericRouting->state($record, $sourceKey, $routingEvents);
            if (($routing['route_position']['origin_kind'] ?? null) === 'pamo_pamb') {
                $action = collect($routing['actions'] ?? [])->first(fn (array $item): bool =>
                    ($item['from'] ?? null) === ($routing['stage'] ?? null)
                    && ! ($item['internal_only'] ?? false)
                    && ! ($item['correction'] ?? false)
                );
                if ($action) {
                    $direct = ($routing['route_profile'] ?? null) === 'direct';
                    $canonicalActions = app(DocumentRoutingProfileRegistry::class)->actionProfile('conservation', $direct)['actions'];
                    return $this->pamoPendingContext($action, $canonicalActions);
                }
            }
        }

        return match ($this->stage($record, $sourceKey, $routingEvents, $routeProfile)) {
            'not_ready' => self::NO_ACTIVITY,
            SubmissionTrackingService::CENRO_RELEASE => self::PENDING_CENRO,
            SubmissionTrackingService::PENRO_RECEIPT => self::PENDING_PENRO,
            SubmissionTrackingService::REGIONAL_ENDORSEMENT => self::PENDING_REGIONAL,
            default => self::COMPLETED,
        };
    }

    /** @param array<string,mixed> $action */
    private function pamoPendingContext(array $action, array $routeActions): string
    {
        $key = (string) ($action['key'] ?? '');
        $canonical = collect($routeActions)->firstWhere('key', $key);
        $office = trim((string) ($canonical['to_office'] ?? $action['to_office'] ?? ''));
        $label = trim((string) ($action['action_label'] ?? $canonical['action_label'] ?? ''));
        if (str_starts_with($key, 'receive_at_')) return 'Awaiting receipt by '.($office ?: 'the assigned office');
        if (($action['correction_cycle'] ?? false) === true) return 'Awaiting resubmission to '.($office ?: 'the assigned office');
        if (preg_match('/^Forward to (.+)$/i', $label, $matches)) return 'Awaiting forward to '.trim($matches[1]);
        if (preg_match('/^Release to (.+)$/i', $label, $matches)) return 'Awaiting release to '.trim($matches[1]);
        if (preg_match('/^Assign to (.+)$/i', $label, $matches)) return 'Awaiting assignment to '.trim($matches[1]);
        if (preg_match('/^Recommend to (.+)$/i', $label, $matches)) return 'Awaiting recommendation to '.trim($matches[1]);
        if (preg_match('/^Approve for (.+)$/i', $label, $matches)) return 'Awaiting approval for '.mb_strtolower(trim($matches[1]));
        return 'Awaiting '.mb_strtolower($label ?: 'the next routing action');
    }

    public function stage(Model $record, ?string $sourceKey = null, ?Collection $routingEvents = null, ?string $routeProfile = null): string
    {
        $sourceKey ??= $this->sourceKey($record);

        if ($record instanceof EngpReportSubmission || $sourceKey === 'engp') {
            $routingStage = $this->genericRouting->state($record, 'engp', $routingEvents)['stage'];
            if ($routingStage === DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return 'endorsed';
            if (in_array($routingStage, [
                DocumentRoutingProfileRegistry::PENRO_RECORDS,
                DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO,
                DocumentRoutingProfileRegistry::OFFICE_PENRO,
                DocumentRoutingProfileRegistry::TRANSIT_TSD,
                DocumentRoutingProfileRegistry::TSD,
                DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL,
                DocumentRoutingProfileRegistry::CDS_FOCAL,
                DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF,
                DocumentRoutingProfileRegistry::CDS_CHIEF,
                DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN,
                DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN,
                DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL,
                DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
            ], true)) return SubmissionTrackingService::REGIONAL_ENDORSEMENT;

            $components = $this->engpWorkflows->releaseComponents(
                (string) $record->getAttribute('workflow_key'),
                (int) $record->getAttribute('reporting_year'),
                (string) $record->getAttribute('period_key'),
            );
            $released = $record->relationLoaded('releaseEvents')
                ? $record->getRelation('releaseEvents')->pluck('period_component')->all()
                : $record->releaseEvents()->pluck('period_component')->all();

            if (count($released) < count($components)) {
                return SubmissionTrackingService::CENRO_RELEASE;
            }

            return $this->date($record, 'date_received_penro')
                ? 'endorsed'
                : SubmissionTrackingService::PENRO_RECEIPT;
        }

        $hasRoutingDate = $this->date($record, 'date_report_released_cenro')
            || $this->date($record, 'date_received_penro')
            || $this->date($record, 'date_endorsed_regional');
        $readyWithoutAccomplishment = $sourceKey === 'revenue';
        $activityDate = $record instanceof ConservationReportSubmission && $this->pambCompliance->applies($record->workflow_key)
            ? $this->pambCompliance->authoritativeDate($record)
            : $this->date($record, 'date_accomplished');
        if (! $readyWithoutAccomplishment && ! $activityDate && ! $hasRoutingDate) {
            return 'not_ready';
        }

        $isDirectPenro = $routeProfile === 'direct'
            || ($routeProfile === null && $this->routingPolicy->isDirectPenro($record));
        if (! $isDirectPenro && ! $this->date($record, 'date_report_released_cenro')) {
            return SubmissionTrackingService::CENRO_RELEASE;
        }
        if (! $this->date($record, 'date_received_penro')) {
            return SubmissionTrackingService::PENRO_RECEIPT;
        }

        return $this->date($record, 'date_endorsed_regional')
            ? 'endorsed'
            : SubmissionTrackingService::REGIONAL_ENDORSEMENT;
    }

    public function sourceKey(Model $record): string
    {
        return match (true) {
            $record instanceof EngpReportSubmission => 'engp',
            $record::class === \App\Models\ConservationReportSubmission::class => 'conservation',
            $record::class === \App\Models\BmsReportSubmission::class => 'bms',
            $record::class === \App\Models\BamsReportSubmission::class => 'bams',
            $record::class === \App\Models\ImeaReportSubmission::class => 'imea',
            $record::class === \App\Models\ImeaFacilityMaintenanceReport::class => 'imea-maintenance',
            $record::class === \App\Models\Aws::class => 'aws',
            $record::class === \App\Models\IpafManagementReport::class => 'ipaf-management',
            $record::class === \App\Models\IpafRevenueCollection::class => 'revenue',
            $record::class === \App\Models\ManagementPlan::class => 'management-plans',
            default => null,
        } ?? 'conservation';
    }

    private function date(Model $record, string $field): mixed
    {
        return DatePresentationNormalizer::toDateString($record->getRawOriginal($field) ?? $record->getAttribute($field));
    }
}
