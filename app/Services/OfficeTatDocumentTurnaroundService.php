<?php

namespace App\Services;

use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OfficeTatDocumentTurnaroundService
{
    private const TIMEZONE = 'Asia/Manila';

    /** @var array<string,string>|null */
    private ?array $routeActionLabels = null;

    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly PamoNativeSourceRegistry $nativeSources,
        private readonly DocumentRoutingProfileRegistry $routingProfiles,
        private readonly BusinessCalendarService $calendar,
        private readonly PambRoutingTimelineService $pambTimeline,
        private readonly OfficeTatProcessedDocumentSourceService $processedSources,
    ) {}

    /**
     * Project document turnaround from the already-scoped processed-report details.
     * Source identities originate in OfficeTatComplianceAssessmentService, so this
     * projection does not widen PA or office access and does not create obligations.
     *
     * @param array<string,mixed> $assessment
     * @return array<string,mixed>
     */
    public function forAssessment(User $user, array $assessment): array
    {
        $unit = (string) ($assessment['unit'] ?? '');
        abort_unless($user->can('office-tat.view'), 403);
        abort_unless(in_array($unit, $this->organization->effectiveUnits($user), true), 403);
        abort_unless(in_array($unit, ['conservation', 'development'], true), 422);

        $unitAssessment = is_array($assessment['office_tat_assessment'] ?? null)
            ? $assessment['office_tat_assessment']
            : [];
        $cutoffValue = $unitAssessment['as_of_date'] ?? $assessment['as_of_date'] ?? null;
        $cutoffDate = $this->dateValue($cutoffValue);
        $cutoff = CarbonImmutable::parse($cutoffDate ?? now(self::TIMEZONE)->toDateString(), self::TIMEZONE)->endOfDay();
        $documents = $this->documentsFromRows($unitAssessment['rows'] ?? [], $unit);
        foreach ($this->documentsFromRows($this->processedSources->forAssessment($user, $assessment), $unit) as $key => $document) {
            $documents[$key] = $document;
        }
        $eventsByDocument = $this->loadRouteEvents($documents, $unit);
        $documents = array_filter($documents, fn (array $document, string $key): bool =>
            $this->hasProcessingEvidence($document, $eventsByDocument[$key] ?? [], $cutoff), ARRAY_FILTER_USE_BOTH,
        );

        $participantRows = [];
        $allReports = [];
        foreach (($unitAssessment['rows'] ?? []) as $participant) {
            if (! is_array($participant) || ! is_string($participant['participant_key'] ?? null)) continue;
            $participantRows[$participant['participant_key']] = [
                'participant_key' => $participant['participant_key'],
                'name' => (string) ($participant['name'] ?? 'Office'),
                'office_binding' => $participant['office_binding'] ?? null,
                'reports' => [],
            ];
        }

        foreach ($documents as $key => $document) {
            $projected = $this->projectDocument(
                $document,
                $eventsByDocument[$key] ?? [],
                $cutoff,
                $this->routeActionLabels(),
            );
            $allReports[] = $projected;
            $participantKey = (string) $document['participant_key'];
            if (! isset($participantRows[$participantKey])) continue;
            $participantRows[$participantKey]['reports'][] = $projected;
        }

        $rows = [];
        foreach ($participantRows as $row) {
            $stats = $this->stats($row['reports']);
            if ($stats['reports'] === 0) continue;
            $rows[] = [
                ...$row,
                'documents' => $row['reports'],
                ...$stats,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'unit' => $unit,
            'period_key' => (string) ($assessment['period_key'] ?? $unitAssessment['period_key'] ?? ''),
            'as_of_date' => $cutoff->toDateString(),
            ...$this->stats($allReports),
            'rows' => $rows,
        ];
    }

    /** Registered source keys accepted by the processed-report projection. */
    public function sourceKeysFor(string $unit): array
    {
        abort_unless(in_array($unit, ['conservation', 'development'], true), 422);

        if ($unit === 'development') return ['engp'];

        return array_values(array_unique([
            'conservation',
            ...array_map(static fn (array $source): string => (string) $source['source'], $this->nativeSources->all()),
        ]));
    }

    /** @param mixed $rows @return array<string,array<string,mixed>> */
    private function documentsFromRows(mixed $rows, string $unit): array
    {
        if (! is_array($rows)) return [];
        $allowedSources = array_fill_keys($this->sourceKeysFor($unit), true);
        $documents = [];

        foreach ($rows as $participant) {
            if (! is_array($participant) || ! is_string($participant['participant_key'] ?? null)) continue;
            $details = [
                ...(is_array($participant['obligation_details'] ?? null) ? $participant['obligation_details'] : []),
                ...(is_array($participant['recorded_obligation_details'] ?? null) ? $participant['recorded_obligation_details'] : []),
            ];

            foreach ($details as $detail) {
                if (! is_array($detail)) continue;
                $identity = is_array($detail['source_identity'] ?? null) ? $detail['source_identity'] : [];
                $source = trim((string) ($identity['source_key'] ?? ''));
                if ($source === '' || ! isset($allowedSources[$source])) continue;

                $sourceIds = isset($identity['source_id'])
                    ? [(int) $identity['source_id']]
                    : array_values(array_unique(array_map('intval', (array) ($identity['source_ids'] ?? []))));
                if ($sourceIds === [] && preg_match('/^Report #(\d+)$/i', (string) ($detail['source_reference'] ?? ''), $match)) {
                    $sourceIds = [(int) $match[1]];
                }

                foreach ($sourceIds as $sourceId) {
                    if ($sourceId < 1) continue;
                    $key = $source.':'.$sourceId;
                    if (isset($documents[$key])) continue;

                    $name = trim((string) ($identity['submitted_activity_name']
                        ?? $identity['activity_name']
                        ?? $detail['label']
                        ?? $identity['workflow_label']
                        ?? 'Report'));
                    $calendarOffice = $identity['calendar_office'] ?? ($participant['office_binding'] ?? null);
                    $documents[$key] = [
                        'key' => $key,
                        'source_type' => $source,
                        'source_id' => $sourceId,
                        'participant_key' => $participant['participant_key'],
                        'participant_name' => (string) ($participant['name'] ?? 'Office'),
                        'office_binding' => $participant['office_binding'] ?? null,
                        'calendar_office' => is_string($calendarOffice) && trim($calendarOffice) !== '' ? trim($calendarOffice) : null,
                        'name' => $name !== '' ? $name : 'Report',
                        'workflow_label' => (string) ($identity['workflow_label'] ?? ''),
                        'activity_name' => (string) ($identity['submitted_activity_name'] ?? $identity['activity_name'] ?? ''),
                        'document_type' => (string) ($identity['document_type'] ?? ''),
                        'period_label' => (string) ($identity['period_label'] ?? $identity['period_key'] ?? $identity['reporting_period'] ?? ''),
                        'reporting_year' => $identity['reporting_year'] ?? null,
                        'deadline' => $this->dateValue($detail['deadline'] ?? null),
                        'legacy_received' => $this->dateValue($detail['received'] ?? null),
                        'processed_evidence' => ($detail['processed_evidence'] ?? false) === true,
                        'reference' => 'Report #'.$sourceId,
                    ];
                }
            }
        }

        return $documents;
    }

    /** @param array<string,array<string,mixed>> $documents @return array<string,list<array<string,mixed>>> */
    private function loadRouteEvents(array $documents, string $unit): array
    {
        if ($documents === []) return [];
        $keysBySource = [];
        foreach ($documents as $key => $document) $keysBySource[$document['source_type']][(int) $document['source_id']] = $key;
        $events = [];

        if (Schema::hasTable('document_routing_events')) {
            $sourceIds = array_map(static fn (string $source, array $ids): array => [$source, array_keys($ids)], array_keys($keysBySource), array_values($keysBySource));
            $query = DB::table('document_routing_events')
                ->select(['id', 'source_type', 'source_id', 'event_key', 'from_stage', 'to_stage', 'from_office', 'to_office', 'occurred_at', 'metadata'])
                ->where(function (Builder $sources) use ($sourceIds): void {
                    foreach ($sourceIds as [$source, $ids]) {
                        if ($ids === []) continue;
                        $sources->orWhere(fn (Builder $oneSource) => $oneSource->where('source_type', $source)->whereIn('source_id', $ids));
                    }
                });
            $query->chunkById(500, function ($batch) use (&$events): void {
                foreach ($batch as $event) {
                    $key = (string) $event->source_type.':'.(int) $event->source_id;
                    $events[$key][] = [
                        'kind' => 'document', 'id' => (int) $event->id,
                        'event_key' => (string) $event->event_key,
                        'from_stage' => (string) $event->from_stage,
                        'to_stage' => (string) $event->to_stage,
                        'from_office' => $event->from_office,
                        'to_office' => $event->to_office,
                        'occurred_at' => $event->occurred_at,
                        'metadata' => $this->metadata($event->metadata),
                    ];
                }
            });
        }

        if ($unit === 'conservation' && Schema::hasTable('pamb_routing_events')) {
            $ids = array_keys($keysBySource['conservation'] ?? []);
            if ($ids !== []) {
                DB::table('pamb_routing_events')
                    ->select(['id', 'conservation_report_submission_id', 'stage_key', 'occurred_at'])
                    ->whereIn('conservation_report_submission_id', $ids)
                    ->chunkById(500, function ($batch) use (&$events): void {
                        foreach ($batch as $event) {
                            $key = 'conservation:'.(int) $event->conservation_report_submission_id;
                            $events[$key][] = [
                                'kind' => 'pamb', 'id' => (int) $event->id,
                                'stage_key' => (string) $event->stage_key,
                                'occurred_at' => $event->occurred_at,
                                'metadata' => [],
                            ];
                        }
                    });
            }
        }

        return $events;
    }

    /** @param array<string,mixed> $document @param list<array<string,mixed>> $events @param array<string,string> $actionLabels @return array<string,mixed> */
    private function projectDocument(array $document, array $events, CarbonImmutable $cutoff, array $actionLabels): array
    {
        $cutoffTimestamp = $cutoff->getTimestamp();
        $starts = [];
        $receiptTimes = [];
        $invalidStartTimestamp = false;
        $invalidReceiptTimestamp = false;
        $legacyPambEventIds = [];
        $timeline = [];

        foreach ($events as $event) {
            if (($event['kind'] ?? null) !== 'document') continue;
            $legacyId = $event['metadata']['legacy_pamb_event_id'] ?? null;
            if ($legacyId !== null) $legacyPambEventIds[(int) $legacyId] = true;
        }

        foreach ($events as $event) {
            $time = $this->timestampValue($event['occurred_at'] ?? null);
            if (($event['kind'] ?? null) === 'document') {
                if ($this->isStartEvent($document, $event)) {
                    if (($event['metadata']['state_source'] ?? null) === 'imported_existing_milestones') {
                        // An imported state is not evidence of the successful submission action.
                    } elseif ($time === null) {
                        $invalidStartTimestamp = true;
                    } else {
                        $starts[] = $time;
                    }
                }
                if ($this->isReceiptEvent($event)) {
                    if ($time === null) $invalidReceiptTimestamp = true;
                    else $receiptTimes[] = $time;
                }
                if ($time !== null && $time->getTimestamp() <= $cutoffTimestamp) {
                    $label = $this->documentEventLabel($event, $actionLabels);
                    if ($label !== '') $timeline[] = ['label' => $label, 'occurred_at' => $time?->toIso8601String(), '_sort' => $time?->getTimestamp() ?? 0, '_id' => $event['id'] ?? 0];
                }
            } elseif (($event['kind'] ?? null) === 'pamb') {
                if (isset($legacyPambEventIds[(int) ($event['id'] ?? 0)])) continue;
                $stage = $this->pambTimeline->canonicalStageKey((string) ($event['stage_key'] ?? ''));
                if ($stage === PambRoutingTimelineService::RECORDS_RECEIVED) {
                    if ($time === null) $invalidReceiptTimestamp = true;
                    else $receiptTimes[] = $time;
                }
                if ($time !== null && $time->getTimestamp() <= $cutoffTimestamp) {
                    $label = $this->pambEventLabel($stage);
                    if ($label !== '') $timeline[] = ['label' => $label, 'occurred_at' => $time?->toIso8601String(), '_sort' => $time?->getTimestamp() ?? 0, '_id' => $event['id'] ?? 0];
                }
            }
        }

        usort($starts, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());
        usort($receiptTimes, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());
        usort($timeline, static fn (array $a, array $b): int => [$a['_sort'], $a['_id']] <=> [$b['_sort'], $b['_id']]);
        $timeline = array_map(static fn (array $event): array => ['label' => $event['label'], 'occurred_at' => $event['occurred_at']], $timeline);

        $firstStart = $starts[0] ?? null;
        $firstReceipt = $receiptTimes[0] ?? null;
        $startAtCutoff = $firstStart instanceof CarbonImmutable && $firstStart->getTimestamp() <= $cutoffTimestamp;
        $receiptEventAtCutoff = $firstReceipt instanceof CarbonImmutable && $firstReceipt->getTimestamp() <= $cutoffTimestamp;
        $legacyReceived = $document['legacy_received'] ?? null;
        $legacyReceivedAtCutoff = is_string($legacyReceived) && $legacyReceived <= $cutoff->toDateString();
        $received = $receiptEventAtCutoff || $legacyReceivedAtCutoff;
        $receivedAt = $receiptEventAtCutoff ? $firstReceipt : ($legacyReceivedAtCutoff ? $legacyReceived : null);
        $deadline = $document['deadline'] ?? null;
        $overdue = ! $received && is_string($deadline) && $deadline <= $cutoff->toDateString();

        $elapsedWorkingDays = null;
        $timingStatus = 'unavailable';
        if ($received) {
            if (! $invalidStartTimestamp && ! $invalidReceiptTimestamp && $startAtCutoff && $receiptEventAtCutoff
                && $firstStart instanceof CarbonImmutable && $firstReceipt instanceof CarbonImmutable
                && $firstReceipt->getTimestamp() >= $firstStart->getTimestamp()) {
                $elapsedWorkingDays = $this->turnaroundWorkingDays($firstStart, $firstReceipt, $document['calendar_office'] ?? null);
                $timingStatus = 'completed';
            }
        } elseif (! $invalidStartTimestamp && $startAtCutoff && $firstStart instanceof CarbonImmutable) {
            if ($cutoffTimestamp >= $firstStart->getTimestamp() && ! $invalidReceiptTimestamp) {
                $elapsedWorkingDays = $this->turnaroundWorkingDays($firstStart, $cutoff, $document['calendar_office'] ?? null);
                $timingStatus = 'in_progress';
            }
        }

        $status = $received ? 'Received at PENRO' : ($overdue ? 'Overdue' : 'In progress');
        return [
            'key' => (string) $document['key'],
            'name' => (string) $document['name'],
            'workflow_label' => (string) ($document['workflow_label'] ?? ''),
            'activity_name' => (string) ($document['activity_name'] ?? ''),
            'document_type' => (string) ($document['document_type'] ?? ''),
            'period_label' => (string) ($document['period_label'] ?? ''),
            'reporting_year' => $document['reporting_year'] ?? null,
            'reference' => (string) $document['reference'],
            'deadline' => $deadline,
            'status' => $status,
            'submission_at' => $startAtCutoff ? $firstStart?->toIso8601String() : null,
            'received_at' => $receivedAt instanceof CarbonImmutable ? $receivedAt->toIso8601String() : $receivedAt,
            'elapsed_working_days' => $elapsedWorkingDays,
            'timing_status' => $timingStatus,
            'elapsed_as_of' => $timingStatus === 'in_progress' ? $cutoff->toIso8601String() : null,
            'route_timeline' => $timeline,
        ];
    }

    private function turnaroundWorkingDays(CarbonImmutable $start, CarbonImmutable $end, ?string $office): int
    {
        return $this->calendar->workingDaysBetween(
            $start,
            $end,
            'after_through',
            $office,
            BusinessCalendarService::PAMB_WORKING_WEEKDAYS,
        );
    }

    /** @param list<array<string,mixed>> $events */
    private function hasProcessingEvidence(array $document, array $events, CarbonImmutable $cutoff): bool
    {
        if (($document['processed_evidence'] ?? false) === true) return true;
        $legacyReceived = $document['legacy_received'] ?? null;
        if (is_string($legacyReceived) && $legacyReceived <= $cutoff->toDateString()) return true;

        $cutoffTimestamp = $cutoff->getTimestamp();
        foreach ($events as $event) {
            $time = $this->timestampValue($event['occurred_at'] ?? null);
            if (! $time || $time->getTimestamp() > $cutoffTimestamp) continue;
            if (($event['kind'] ?? null) === 'document'
                && in_array($event['event_key'] ?? null, ['forwarded', 'received', 'endorsed', 'recommended', 'approved', 'released', 'returned_for_correction', 'correction_received'], true)
                && trim((string) ($event['to_stage'] ?? '')) !== '') return true;
            if (($event['kind'] ?? null) === 'pamb' && trim((string) ($event['stage_key'] ?? '')) !== '') return true;
        }

        return false;
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $event */
    private function isStartEvent(array $document, array $event): bool
    {
        if (($event['event_key'] ?? null) !== 'forwarded') return false;
        $action = (string) ($event['metadata']['action_key'] ?? '');
        if (($document['source_type'] ?? null) === 'engp') {
            return $action === 'forward_to_cenro_chief'
                && ($event['from_stage'] ?? null) === DocumentRoutingProfileRegistry::PREPARATION
                && ($event['to_stage'] ?? null) === DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF;
        }

        return ($event['from_stage'] ?? null) === DocumentRoutingProfileRegistry::PAMO_ORIGIN
            && in_array($event['to_stage'] ?? null, [DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL], true)
            && in_array($action, ['forward_from_pamo', 'forward_from_pamo_to_cenro_focal'], true);
    }

    /** @param array<string,mixed> $event */
    private function isReceiptEvent(array $event): bool
    {
        return ($event['event_key'] ?? null) === 'received'
            && ($event['from_stage'] ?? null) === DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS
            && ($event['to_stage'] ?? null) === DocumentRoutingProfileRegistry::PENRO_RECORDS;
    }

    /** @param array<string,mixed> $event @param array<string,string> $actionLabels */
    private function documentEventLabel(array $event, array $actionLabels): string
    {
        $action = (string) ($event['metadata']['action_key'] ?? '');
        if ($action !== '' && isset($actionLabels[$action])) return $actionLabels[$action];

        $toOffice = trim((string) ($event['to_office'] ?? ''));
        return match ((string) ($event['event_key'] ?? '')) {
            'received' => $toOffice !== '' ? 'Received by '.$toOffice : 'Received',
            'correction_received' => 'Correction received',
            'returned_for_correction' => 'Returned for correction',
            'forwarded' => $toOffice !== '' ? 'Forwarded to '.$toOffice : 'Forwarded',
            'endorsed' => $toOffice !== '' ? 'Endorsed to '.$toOffice : 'Endorsed',
            'recommended' => $toOffice !== '' ? 'Recommended to '.$toOffice : 'Recommended',
            'approved' => 'Approved for release',
            'released' => $toOffice !== '' ? 'Released to '.$toOffice : 'Released',
            default => '',
        };
    }

    private function pambEventLabel(string $stage): string
    {
        return match ($stage) {
            PambRoutingTimelineService::RECORDS_RECEIVED => 'Received by PENRO Records',
            PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO => 'Forwarded by PENRO Records',
            PambRoutingTimelineService::RECEIVED_BY_PENRO => 'Received by PENRO',
            PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD => 'Forwarded to TSD',
            PambRoutingTimelineService::RECEIVED_BY_TSD => 'Received by TSD',
            PambRoutingTimelineService::FORWARDED_TSD_TO_CDS => 'Forwarded to CDS',
            PambRoutingTimelineService::RECEIVED_BY_CDS => 'Received by CDS',
            PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF => 'Forwarded to CDS Chief',
            PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF => 'Received by CDS Chief',
            PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO => 'Forwarded to PENRO',
            PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL => 'Received for final PENRO review',
            PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION => 'Returned for correction',
            PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL => 'Approved for regional release',
            PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS => 'Forwarded to PENRO Records',
            PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL => 'Received by PENRO Records for regional release',
            PambRoutingTimelineService::RELEASED_TO_REGIONAL => 'Released to the Regional Office',
            default => $stage === '' ? '' : ucwords(str_replace('_', ' ', $stage)),
        };
    }

    /** @return array<string,string> */
    private function routeActionLabels(): array
    {
        if ($this->routeActionLabels !== null) return $this->routeActionLabels;
        $sources = ['conservation', 'engp', ...$this->sourceKeysFor('conservation')];
        $labels = [];
        foreach (array_unique($sources) as $source) {
            foreach ($this->routingProfiles->actionProfile((string) $source)['actions'] as $action) {
                $key = (string) ($action['key'] ?? '');
                $label = (string) ($action['label'] ?? '');
                if ($key !== '' && $label !== '') $labels[$key] = $label;
            }
        }
        return $this->routeActionLabels = $labels;
    }

    /** @param list<array<string,mixed>> $reports @return array<string,mixed> */
    private function stats(array $reports): array
    {
        $received = count(array_filter($reports, static fn (array $report): bool => $report['status'] === 'Received at PENRO'));
        $inProgress = count($reports) - $received;
        $overdue = count(array_filter($reports, static fn (array $report): bool => $report['status'] === 'Overdue'));
        $completed = array_values(array_filter($reports, static fn (array $report): bool => $report['timing_status'] === 'completed' && is_numeric($report['elapsed_working_days'])));
        $average = $completed !== []
            ? array_sum(array_map(static fn (array $report): float => (float) $report['elapsed_working_days'], $completed)) / count($completed)
            : null;
        $timingUnavailable = count(array_filter($reports, static fn (array $report): bool => $report['status'] === 'Received at PENRO' && $report['timing_status'] !== 'completed'));

        return [
            'reports' => count($reports),
            'received_count' => $received,
            'in_progress_count' => $inProgress,
            'overdue_count' => $overdue,
            'average_working_days' => $average,
            'average_supporting_documents' => count($completed),
            'timing_unavailable_count' => $timingUnavailable,
        ];
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        try {
            $date = CarbonImmutable::parse($value, self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
        return $date->format('Y-m-d') === $value ? $value : null;
    }

    private function timestampValue(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') return null;
        try {
            return CarbonImmutable::parse($value, self::TIMEZONE)->setTimezone(self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }
}
