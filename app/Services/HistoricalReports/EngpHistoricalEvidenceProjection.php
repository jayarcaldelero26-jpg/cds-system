<?php

namespace App\Services\HistoricalReports;

use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Services\Engp\EngpMonitoringStatusResolver;
use App\Services\SubmissionTracking\RoutingStatusPresenter;

/**
 * Projects immutable ENGP original-date evidence into source and detail views.
 * It never copies manually recorded dates into stored live routing fields.
 */
final class EngpHistoricalEvidenceProjection
{
    private const MILESTONE_FIELDS = [
        'date_report_released_cenro',
        'date_received_penro',
        'date_endorsed_regional',
    ];

    public function __construct(private readonly EngpMonitoringStatusResolver $monitoringStatuses) {}

    /** @return array<string,mixed> */
    public function project(EngpReportSubmission $record, HistoricalReportOrigin $origin): array
    {
        $sourceMilestones = data_get($origin->original_milestones, 'milestones', []);
        $milestones = [];
        $projected = clone $record;

        foreach (self::MILESTONE_FIELDS as $field) {
            $value = $sourceMilestones[$field] ?? null;
            $milestones[$field] = filled($value) ? (string) $value : null;
            $projected->setAttribute($field, $milestones[$field]);
        }

        return $this->values($projected, $milestones);
    }

    /** @return array<string,mixed>|null */
    public function forRecord(EngpReportSubmission $record): ?array
    {
        if (! (bool) $record->getAttribute('is_historical')) return null;

        $origin = HistoricalReportOrigin::query()
            ->where('source', 'engp')
            ->where('source_record_id', $record->getKey())
            ->first();

        if ($origin) return $this->project($record, $origin);

        $projected = clone $record;
        $milestones = array_fill_keys(self::MILESTONE_FIELDS, null);
        foreach ($milestones as $field => $value) $projected->setAttribute($field, $value);

        return $this->values($projected, $milestones);
    }

    /** @param array<string, string|null> $milestones
     *  @return array<string,mixed>
     */
    private function values(EngpReportSubmission $record, array $milestones): array
    {
        $release = $milestones['date_report_released_cenro'];
        $receipt = $milestones['date_received_penro'];
        $regional = $milestones['date_endorsed_regional'];
        $deadline = $record->deadline_submission?->toDateString();
        $hasAnyEvidence = collect($milestones)->contains(fn (?string $date): bool => filled($date));

        $daysComplied = ! $receipt
            ? 'Missing historical evidence'
            : ($deadline ? $record->days_complied : 'N/A');
        $timeliness = $receipt && $deadline ? ($record->timeliness_rating ?? 'N/A') : 'N/A';
        $monitoring = $receipt
            ? $this->monitoringStatuses->resolve($deadline, $receipt)
            : ['key' => 'historical_evidence_missing', 'label' => 'Missing historical evidence'];
        $submissionStatus = match (true) {
            ! $hasAnyEvidence => 'Missing historical evidence',
            $release && $receipt && $regional => RoutingStatusPresenter::COMPLETED,
            $release && $receipt => RoutingStatusPresenter::PENDING_REGIONAL,
            $release && ! $receipt && ! $regional => RoutingStatusPresenter::PENDING_PENRO,
            default => 'Historical evidence incomplete',
        };

        $evaluation = $receipt && $deadline
            ? [
                'status' => $timeliness,
                'deadline' => $deadline,
                'receipt_date' => $receipt,
                'basis' => 'Existing ENGP source calculator with manually recorded original receipt evidence.',
            ]
            : [
                'status' => 'N/A',
                'reason' => ! $receipt
                    ? 'No original PENRO receipt date was supplied.'
                    : 'The current ENGP source record has no calculated deadline.',
                'deadline' => $deadline,
                'basis' => 'Original historical evidence; no live routing event is inferred.',
            ];

        $historicalEvidence = [
            'basis' => 'Manual historical evidence',
            'submission_status' => $submissionStatus,
            'monitoring_status' => $monitoring['label'],
            'timeliness_status' => $timeliness,
            'milestones' => $milestones,
            'note' => 'Original dates were manually recorded from historical evidence. They do not represent current custody or live routing completion.',
        ];

        return [
            'record' => $record,
            'milestones' => collect($milestones)->filter(fn (?string $date): bool => filled($date))->all(),
            'days_complied' => $daysComplied,
            'timeliness' => $timeliness,
            'monitoring_status_key' => $monitoring['key'],
            'monitoring_status' => $monitoring['label'],
            'submission_status' => $submissionStatus,
            'timeliness_evaluation' => $evaluation,
            'historical_evidence' => $historicalEvidence,
        ];
    }
}
