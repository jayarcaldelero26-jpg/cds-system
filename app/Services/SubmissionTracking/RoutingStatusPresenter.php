<?php

namespace App\Services\SubmissionTracking;

use App\Models\EngpReportSubmission;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Illuminate\Database\Eloquent\Model;

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
    ) {}

    public function status(Model $record, ?string $sourceKey = null): string
    {
        return match ($this->stage($record, $sourceKey)) {
            'not_ready' => self::NO_ACTIVITY,
            SubmissionTrackingService::CENRO_RELEASE => self::PENDING_CENRO,
            SubmissionTrackingService::PENRO_RECEIPT => self::PENDING_PENRO,
            SubmissionTrackingService::REGIONAL_ENDORSEMENT => self::PENDING_REGIONAL,
            default => self::COMPLETED,
        };
    }

    public function stage(Model $record, ?string $sourceKey = null): string
    {
        $sourceKey ??= $this->sourceKey($record);

        if ($record instanceof EngpReportSubmission || $sourceKey === 'engp') {
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

            return $record->getAttribute('date_received_penro')
                ? 'endorsed'
                : SubmissionTrackingService::PENRO_RECEIPT;
        }

        $hasRoutingDate = $this->date($record, 'date_report_released_cenro')
            || $this->date($record, 'date_received_penro')
            || $this->date($record, 'date_endorsed_regional');
        $readyWithoutAccomplishment = $sourceKey === 'revenue';
        if (! $readyWithoutAccomplishment && ! $record->getAttribute('date_accomplished') && ! $hasRoutingDate) {
            return 'not_ready';
        }

        if (! $this->routingPolicy->isDirectPenro($record) && ! $this->date($record, 'date_report_released_cenro')) {
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
        return $record->getAttribute($field);
    }
}
