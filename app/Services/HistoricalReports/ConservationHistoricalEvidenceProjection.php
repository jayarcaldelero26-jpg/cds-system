<?php

namespace App\Services\HistoricalReports;

use App\Models\ConservationReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Services\SubmissionTracking\RoutingStatusPresenter;

/**
 * Projects immutable, manually encoded original dates for Conservation source
 * views without persisting them as live custody dates or events.
 */
final class ConservationHistoricalEvidenceProjection
{
    private const MILESTONE_FIELDS = [
        'date_report_released_cenro',
        'date_received_penro',
        'date_endorsed_regional',
    ];

    public function __construct(private readonly RoutingStatusPresenter $routingStatus) {}

    /**
     * @return array{
     *   record:ConservationReportSubmission,
     *   milestones:array<string,string|null>,
     *   deadline_submission:?string,
     *   days_complied:int|string|null,
     *   timeliness:string,
     *   submission_status:string,
     *   penro_delay:int|string,
     *   timeliness_evaluation:array<string,mixed>,
     *   historical_evidence:array<string,mixed>
     * }
     */
    public function project(ConservationReportSubmission $record, HistoricalReportOrigin $origin): array
    {
        $sourceMilestones = data_get($origin->original_milestones, 'milestones', []);
        $milestones = [];
        $projected = clone $record;

        foreach (self::MILESTONE_FIELDS as $field) {
            $value = $sourceMilestones[$field] ?? null;
            $milestones[$field] = filled($value) ? (string) $value : null;
            $projected->setAttribute($field, $milestones[$field]);
        }

        $receipt = $milestones['date_received_penro'];
        $stage = $this->routingStatus->stage($projected, 'conservation');
        $hasOriginalRoutingEvidence = collect($milestones)->contains(fn (?string $date): bool => filled($date));
        $status = $stage === 'endorsed'
            ? RoutingStatusPresenter::COMPLETED
            : ($hasOriginalRoutingEvidence ? 'Historical evidence incomplete' : 'Missing historical evidence');
        $deadline = $projected->deadline_submission;
        $timeliness = $receipt && $deadline ? $projected->timeliness : 'N/A';
        $days = $receipt ? $projected->days_complied : 'Missing historical evidence';
        $hasDelayDates = $receipt && $milestones['date_endorsed_regional'];
        $penroDelay = $hasDelayDates ? $projected->penro_delay : 'Missing historical evidence';

        $evaluation = $receipt
            ? [
                'status' => $timeliness,
                'deadline' => $deadline,
                'receipt_date' => $receipt,
                'basis' => 'Existing source-module calculator with manually recorded original receipt evidence.',
            ]
            : [
                'status' => 'N/A',
                'reason' => 'No original receipt date was supplied.',
                'deadline' => $deadline,
                'basis' => 'Original historical evidence; no live routing event is inferred.',
            ];

        $historicalEvidence = [
            'basis' => 'Manual historical evidence',
            'submission_status' => $status,
            'timeliness_status' => $timeliness,
            'milestones' => $milestones,
            'note' => 'Original office dates were manually recorded from historical evidence. They do not represent current custody or live routing completion.',
        ];

        return [
            'record' => $projected,
            'milestones' => $milestones,
            'deadline_submission' => $deadline,
            'days_complied' => $days,
            'timeliness' => $timeliness,
            'submission_status' => $status,
            'penro_delay' => $penroDelay,
            'timeliness_evaluation' => $evaluation,
            'historical_evidence' => $historicalEvidence,
        ];
    }

    /** @return array<string,mixed>|null */
    public function forRecord(ConservationReportSubmission $record): ?array
    {
        if (! (bool) $record->getAttribute('is_historical')) return null;

        $origin = HistoricalReportOrigin::query()
            ->where('source', 'conservation')
            ->where('source_record_id', $record->getKey())
            ->first();

        if (! $origin) {
            $projected = clone $record;
            foreach (self::MILESTONE_FIELDS as $field) $projected->setAttribute($field, null);

            return [
                'record' => $projected,
                'milestones' => array_fill_keys(self::MILESTONE_FIELDS, null),
                'deadline_submission' => $projected->deadline_submission,
                'days_complied' => 'Missing historical evidence',
                'timeliness' => 'N/A',
                'submission_status' => 'Missing historical evidence',
                'penro_delay' => 'Missing historical evidence',
                'timeliness_evaluation' => [
                    'status' => 'N/A',
                    'reason' => 'The immutable historical evidence record is unavailable.',
                    'deadline' => $projected->deadline_submission,
                    'basis' => 'Original historical evidence is unavailable; no live routing event is inferred.',
                ],
                'historical_evidence' => [
                    'basis' => 'Manual historical evidence',
                    'submission_status' => 'Missing historical evidence',
                    'timeliness_status' => 'N/A',
                    'milestones' => array_fill_keys(self::MILESTONE_FIELDS, null),
                    'note' => 'The immutable historical evidence record is unavailable. No live custody status is inferred.',
                ],
            ];
        }

        return $this->project($record, $origin);
    }
}
