<?php

namespace App\Services\SubmissionTracking;

use App\Models\AuditLog;
use App\Models\ConservationReportSubmission;
use App\Models\PambRoutingEvent;
use App\Services\AuditLogService;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\PambComplianceCalculator;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Additive, informational routing details for the three PAMB meeting workflows.
 * Canonical submission fields remain the source of truth for the first, second,
 * and final milestones.
 */
final class PambRoutingTimelineService
{
    public const RECORDS_RECEIVED = 'penro_records_received';
    public const FORWARDED_RECORDS_TO_PENRO = 'forwarded_records_to_penro';
    public const RECEIVED_BY_PENRO = 'received_by_penro';
    public const FORWARDED_PENRO_TO_TSD = 'forwarded_penro_to_tsd';
    public const RECEIVED_BY_TSD = 'received_by_tsd';
    public const FORWARDED_TSD_TO_CDS = 'forwarded_tsd_to_cds';
    public const RECEIVED_BY_CDS = 'received_by_cds';
    public const FORWARDED_CDS_TO_PENRO = 'forwarded_cds_to_penro';
    public const RECEIVED_BY_PENRO_FINAL = 'received_by_penro_final';
    public const FORWARDED_PENRO_TO_RECORDS = 'forwarded_penro_to_records';
    public const RECEIVED_BY_RECORDS_FINAL = 'received_by_records_final';
    public const RELEASED_TO_REGIONAL = 'released_to_regional';

    // Source-compatible aliases for callers that used the first additive model.
    public const RECORDS_TO_PENRO = self::FORWARDED_RECORDS_TO_PENRO;
    public const PENRO_TO_TSD = self::FORWARDED_PENRO_TO_TSD;
    public const TSD_TO_CDS = self::FORWARDED_TSD_TO_CDS;
    public const CDS_TO_PENRO = self::FORWARDED_CDS_TO_PENRO;
    public const PENRO_TO_RECORDS = self::FORWARDED_PENRO_TO_RECORDS;
    public const RECORDS_TO_REGIONAL = self::RELEASED_TO_REGIONAL;

    /** @var list<string> */
    private const INTERNAL_STAGE_KEYS = [
        self::FORWARDED_RECORDS_TO_PENRO,
        self::RECEIVED_BY_PENRO,
        self::FORWARDED_PENRO_TO_TSD,
        self::RECEIVED_BY_TSD,
        self::FORWARDED_TSD_TO_CDS,
        self::RECEIVED_BY_CDS,
        self::FORWARDED_CDS_TO_PENRO,
        self::RECEIVED_BY_PENRO_FINAL,
        self::FORWARDED_PENRO_TO_RECORDS,
        self::RECEIVED_BY_RECORDS_FINAL,
    ];

    /** @var array<string, string> */
    private const LEGACY_STAGE_ALIASES = [
        'records_to_penro' => self::FORWARDED_RECORDS_TO_PENRO,
        'penro_to_tsd' => self::FORWARDED_PENRO_TO_TSD,
        'tsd_to_cds' => self::FORWARDED_TSD_TO_CDS,
        'cds_to_penro' => self::FORWARDED_CDS_TO_PENRO,
        'penro_to_records' => self::FORWARDED_PENRO_TO_RECORDS,
    ];

    public function __construct(
        private readonly BusinessCalendarService $calendar,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function applies(ConservationReportSubmission $report): bool
    {
        return in_array($report->workflow_key, PambComplianceCalculator::MEETING_WORKFLOWS, true);
    }

    /** @return list<string> */
    public function internalStageKeys(): array
    {
        return array_values(array_unique([...self::INTERNAL_STAGE_KEYS, ...array_keys(self::LEGACY_STAGE_ALIASES)]));
    }

    public function record(
        ConservationReportSubmission $report,
        string $stageKey,
        string $occurredAt,
        ?int $userId = null,
        ?string $remarks = null,
    ): PambRoutingEvent {
        if (! $this->applies($report)) {
            throw ValidationException::withMessages(['stage' => 'Detailed routing is available only for Regular PAMB, Special PAMB, and TWC/TWG PAMB workflows.']);
        }
        $stageKey = $this->canonicalStageKey($stageKey);
        if (! in_array($stageKey, self::INTERNAL_STAGE_KEYS, true)) {
            throw ValidationException::withMessages(['stage' => 'This routing stage is not an internal PAMB routing action.']);
        }

        $existing = $this->eventsByStage($report->routingEvents()->get());
        if ($existing->has($stageKey)) {
            throw ValidationException::withMessages(['stage' => 'This internal routing event has already been recorded. Use the existing correction process for authorized historical repairs.']);
        }

        $date = $this->parseDateTime($occurredAt);
        $milestones = $this->milestoneDates($report, $existing);
        $previousKey = $this->previousKey($stageKey);
        $previous = $previousKey ? ($milestones[$previousKey] ?? null) : null;
        if ($this->nextKey($this->definitions($report), $milestones) !== $stageKey) {
            throw ValidationException::withMessages(['stage' => 'Record the preceding receipt or forwarding event before this routing action.']);
        }
        if (! $previous) {
            throw ValidationException::withMessages(['stage' => 'This routing event is out of order. Record the preceding routing milestone first.']);
        }
        if ($date->lessThan($previous)) {
            throw ValidationException::withMessages(['occurred_at' => 'A routing event cannot occur before its preceding milestone.']);
        }

        $regional = $milestones[self::RECORDS_TO_REGIONAL] ?? null;
        if ($regional && $date->greaterThan($regional)) {
            throw ValidationException::withMessages(['occurred_at' => 'An internal routing event cannot occur after Regional Endorsement.']);
        }

        $event = DB::transaction(function () use ($report, $stageKey, $date, $userId, $remarks): PambRoutingEvent {
            return $report->routingEvents()->create([
                'workflow_key' => $report->workflow_key,
                'stage_key' => $stageKey,
                'occurred_at' => $date->toDateTimeString(),
                'recorded_by' => $userId,
                'remarks' => filled($remarks) ? trim($remarks) : null,
            ]);
        });

        $metadata = $this->stageDefinition($stageKey);
        $this->auditLogs->record(
            'submission_tracking',
            'PAMB Internal Routing Event Recorded',
            'conservation',
            $report->getKey(),
            $report->workflow_key,
            'Recorded '.$metadata['label'].' for conservation record #'.$report->getKey().'.',
            [
            'stage' => $stageKey,
                'occurred_at' => $date->toIso8601String(),
                'remarks' => filled($remarks) ? trim($remarks) : null,
                'workflow_key' => $report->workflow_key,
            ],
            $userId,
        );

        return $event->load('recordedBy');
    }

    /** @return array<string, mixed> */
    public function present(ConservationReportSubmission $report, ?CarbonInterface $asOf = null): array
    {
        if (! $this->applies($report)) {
            return [
                'applicable' => false,
                'timeline' => [],
                'current_document_location' => null,
                'current_processing_status' => null,
                'routing_summary' => [],
                'summary_metrics' => [],
            ];
        }

        $report->loadMissing('routingEvents.recordedBy');
        $events = $this->eventsByStage($report->routingEvents);
        $dates = $this->milestoneDates($report, $events);
        $definitions = $this->definitions($report);
        $hasRegionalEndorsement = isset($dates[self::RELEASED_TO_REGIONAL]);
        $nextKey = $hasRegionalEndorsement ? null : $this->nextKey($definitions, $dates);
        $canonicalActors = $this->canonicalActors($report);
        $timeline = [];

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            $date = $dates[$key] ?? null;
            $notApplicable = $key === SubmissionTrackingService::CENRO_RELEASE && $this->routingPolicy->isDirectPenro($report);
            $status = $notApplicable
                ? 'not_applicable'
                : ($date ? 'completed' : ($hasRegionalEndorsement && in_array($key, self::INTERNAL_STAGE_KEYS, true) ? 'not_recorded' : ($nextKey === $key ? 'current' : 'pending')));
            $previousDate = $this->previousDate($key, $dates, $report);
            $previousKey = $this->previousKey($key);
            $previousEvent = $previousKey ? $events->get($previousKey) : null;
            $elapsed = $date && $previousDate
                ? $this->workingDaysBetween($previousDate, $date, $report)
                : null;
            $pending = $status === 'current' && $previousDate
                ? $this->workingDaysBetween($previousDate, $asOf ? CarbonImmutable::instance($asOf) : CarbonImmutable::now(BusinessCalendarService::TIMEZONE), $report)
                : null;
            $event = $events->get($key);
            $actor = $event?->recordedBy?->name ?? $canonicalActors[$key] ?? null;

            $timeline[] = [
                'key' => $key,
                'label' => $status === 'current' && isset($definition['awaiting_label']) ? $definition['awaiting_label'] : $definition['label'],
                'held_at' => $definition['held_at'],
                'destination' => $definition['destination'] ?? null,
                'occurred_at' => $date?->toIso8601String(),
                'previous_occurred_at' => $previousEvent?->occurred_at?->toIso8601String() ?? $previousDate?->toIso8601String(),
                'status' => $status,
                'elapsed_working_days' => $elapsed,
                'pending_working_days' => $pending,
                'delay_type' => $this->delayType($key),
                'recorded_by' => $actor,
                'remarks' => $event?->remarks,
                'is_internal' => in_array($key, self::INTERNAL_STAGE_KEYS, true),
                'can_record' => $key === $nextKey && in_array($key, self::INTERNAL_STAGE_KEYS, true),
                'action_label' => $definition['action_label'] ?? null,
            ];
        }

        $regional = $dates[self::RELEASED_TO_REGIONAL] ?? null;
        $currentLocation = $this->currentLocation($report, $dates);
        $currentStatus = $this->currentStatus($definitions, $dates, $nextKey, $report);
        $currentStage = $nextKey ? collect($timeline)->firstWhere('key', $nextKey) : null;
        $lastAction = collect($timeline)
            ->filter(fn (array $item): bool => filled($item['occurred_at']))
            ->sortBy('occurred_at')
            ->last();
        $routingSummary = [
            'current_location' => $currentLocation,
            'current_status' => $currentStatus,
            'responsible_office' => $currentStage['held_at'] ?? ($hasRegionalEndorsement ? 'Regional Office / Completed' : null),
            'pending_since' => $currentStage['previous_occurred_at'] ?? null,
            'working_days_pending' => $currentStage['pending_working_days'] ?? null,
            'next_expected_action' => $this->nextExpectedAction($nextKey, $hasRegionalEndorsement),
            'last_action' => $lastAction ? [
                'label' => $lastAction['label'],
                'occurred_at' => $lastAction['occurred_at'],
                'recorded_by' => $lastAction['recorded_by'],
                'remarks' => $lastAction['remarks'],
            ] : null,
            'last_updated' => $lastAction['occurred_at'] ?? null,
        ];

        return [
            'applicable' => true,
            'workflow_key' => $report->workflow_key,
            'current_document_location' => $currentLocation,
            'current_processing_status' => $currentStatus,
            'routing_summary' => $routingSummary,
            'timeline' => $timeline,
            'summary_metrics' => [
                'cenro_to_penro' => $this->summaryMetric(
                    $this->routingPolicy->isDirectPenro($report) ? null : ($dates[SubmissionTrackingService::CENRO_RELEASE] ?? null),
                    $dates[self::RECORDS_RECEIVED] ?? null,
                    $report,
                    $this->routingPolicy->isDirectPenro($report) ? 'N/A' : null,
                ),
                'penro_to_regional' => $this->summaryMetric($dates[self::RECORDS_RECEIVED] ?? null, $regional, $report),
                'cenro_to_regional' => $this->summaryMetric(
                    $this->routingPolicy->isDirectPenro($report) ? null : ($dates[SubmissionTrackingService::CENRO_RELEASE] ?? null),
                    $regional,
                    $report,
                    $this->routingPolicy->isDirectPenro($report) ? 'N/A' : null,
                ),
                'total_working_days_pending_at_penro' => $this->summaryMetric(
                    $dates[self::RECORDS_RECEIVED] ?? null,
                    $regional ?? ($asOf ? CarbonImmutable::instance($asOf) : CarbonImmutable::now(BusinessCalendarService::TIMEZONE)),
                    $report,
                ),
            ],
        ];
    }

    /** @return array<string, CarbonImmutable> */
    private function milestoneDates(ConservationReportSubmission $report, mixed $events): array
    {
        $dates = [];
        if (! $this->routingPolicy->isDirectPenro($report) && $report->date_report_released_cenro) {
            $dates[SubmissionTrackingService::CENRO_RELEASE] = $this->date($report->date_report_released_cenro);
        }
        if ($report->date_received_penro) {
            $dates[self::RECORDS_RECEIVED] = $this->date($report->date_received_penro);
        }
        foreach (self::INTERNAL_STAGE_KEYS as $key) {
            $event = $events instanceof \Illuminate\Support\Collection ? $events->get($key) : null;
            if ($event?->occurred_at) $dates[$key] = $this->date($event->occurred_at);
        }
        if ($report->date_endorsed_regional) {
            $dates[self::RELEASED_TO_REGIONAL] = $this->date($report->date_endorsed_regional);
        }
        return $dates;
    }

    /** @return list<array{key:string,label:string,held_at:string}> */
    private function definitions(ConservationReportSubmission $report): array
    {
        $definitions = [
            ['key' => SubmissionTrackingService::CENRO_RELEASE, 'label' => 'Released by CENRO', 'held_at' => 'CENRO'],
            ['key' => self::RECORDS_RECEIVED, 'label' => 'Received by PENRO Records', 'awaiting_label' => 'Awaiting PENRO Receipt', 'action_label' => 'Record PENRO Receipt', 'held_at' => 'PENRO Records', 'destination' => 'PENRO Records'],
            ['key' => self::FORWARDED_RECORDS_TO_PENRO, 'label' => 'Forwarded to Office of the PENRO', 'awaiting_label' => 'Forwarded to Office of the PENRO', 'action_label' => 'Record Forwarding to Office of the PENRO', 'held_at' => 'PENRO Records', 'destination' => 'Office of the PENRO'],
            ['key' => self::RECEIVED_BY_PENRO, 'label' => 'Received by Office of the PENRO', 'awaiting_label' => 'Awaiting Receipt by Office of the PENRO', 'action_label' => 'Record Receipt by Office of the PENRO', 'held_at' => 'Office of the PENRO', 'destination' => 'Office of the PENRO'],
            ['key' => self::FORWARDED_PENRO_TO_TSD, 'label' => 'Forwarded to TSD', 'awaiting_label' => 'Forwarded to TSD', 'action_label' => 'Record Forwarding to TSD', 'held_at' => 'Office of the PENRO', 'destination' => 'TSD'],
            ['key' => self::RECEIVED_BY_TSD, 'label' => 'Received by TSD', 'awaiting_label' => 'Awaiting Receipt by TSD', 'action_label' => 'Record Receipt by TSD', 'held_at' => 'TSD', 'destination' => 'TSD'],
            ['key' => self::FORWARDED_TSD_TO_CDS, 'label' => 'Forwarded to CDS', 'awaiting_label' => 'Forwarded to CDS', 'action_label' => 'Record Forwarding to CDS', 'held_at' => 'TSD', 'destination' => 'CDS'],
            ['key' => self::RECEIVED_BY_CDS, 'label' => 'Received by CDS', 'awaiting_label' => 'Awaiting Receipt by CDS', 'action_label' => 'Record Receipt by CDS', 'held_at' => 'CDS', 'destination' => 'CDS'],
            ['key' => self::FORWARDED_CDS_TO_PENRO, 'label' => 'Returned/Forwarded to Office of the PENRO', 'awaiting_label' => 'Forwarded to Office of the PENRO', 'action_label' => 'Record Return to Office of the PENRO', 'held_at' => 'CDS', 'destination' => 'Office of the PENRO'],
            ['key' => self::RECEIVED_BY_PENRO_FINAL, 'label' => 'Received by Office of the PENRO', 'awaiting_label' => 'Awaiting Receipt by Office of the PENRO', 'action_label' => 'Record Receipt by Office of the PENRO', 'held_at' => 'Office of the PENRO', 'destination' => 'Office of the PENRO'],
            ['key' => self::FORWARDED_PENRO_TO_RECORDS, 'label' => 'Forwarded to PENRO Records', 'awaiting_label' => 'Forwarded to PENRO Records', 'action_label' => 'Record Forwarding to PENRO Records', 'held_at' => 'Office of the PENRO', 'destination' => 'PENRO Records'],
            ['key' => self::RECEIVED_BY_RECORDS_FINAL, 'label' => 'Received by PENRO Records', 'awaiting_label' => 'Awaiting Receipt by PENRO Records', 'action_label' => 'Record Receipt by PENRO Records', 'held_at' => 'PENRO Records', 'destination' => 'PENRO Records'],
            ['key' => self::RELEASED_TO_REGIONAL, 'label' => 'Released/Endorsed to Regional Office', 'action_label' => 'Release / Endorse to Regional Office', 'held_at' => 'PENRO Records', 'destination' => 'Regional Office'],
        ];
        return $this->routingPolicy->isDirectPenro($report) ? array_values(array_filter($definitions, fn (array $item): bool => $item['key'] !== SubmissionTrackingService::CENRO_RELEASE)) : $definitions;
    }

    /** @param array<string, CarbonImmutable> $dates */
    private function nextKey(array $definitions, array $dates): ?string
    {
        foreach ($definitions as $definition) if (! isset($dates[$definition['key']])) return $definition['key'];
        return null;
    }

    private function previousKey(string $stageKey): ?string
    {
        return match ($stageKey) {
            self::FORWARDED_RECORDS_TO_PENRO => self::RECORDS_RECEIVED,
            self::RECEIVED_BY_PENRO => self::FORWARDED_RECORDS_TO_PENRO,
            self::FORWARDED_PENRO_TO_TSD => self::RECEIVED_BY_PENRO,
            self::RECEIVED_BY_TSD => self::FORWARDED_PENRO_TO_TSD,
            self::FORWARDED_TSD_TO_CDS => self::RECEIVED_BY_TSD,
            self::RECEIVED_BY_CDS => self::FORWARDED_TSD_TO_CDS,
            self::FORWARDED_CDS_TO_PENRO => self::RECEIVED_BY_CDS,
            self::RECEIVED_BY_PENRO_FINAL => self::FORWARDED_CDS_TO_PENRO,
            self::FORWARDED_PENRO_TO_RECORDS => self::RECEIVED_BY_PENRO_FINAL,
            self::RECEIVED_BY_RECORDS_FINAL => self::FORWARDED_PENRO_TO_RECORDS,
            default => null,
        };
    }

    /** @param array<string, CarbonImmutable> $dates */
    private function previousDate(string $key, array $dates, ConservationReportSubmission $report): ?CarbonImmutable
    {
        $previous = match ($key) {
            SubmissionTrackingService::CENRO_RELEASE => null,
            self::RECORDS_RECEIVED => $dates[SubmissionTrackingService::CENRO_RELEASE] ?? null,
            self::FORWARDED_RECORDS_TO_PENRO => $dates[self::RECORDS_RECEIVED] ?? null,
            self::RECEIVED_BY_PENRO => $dates[self::FORWARDED_RECORDS_TO_PENRO] ?? null,
            self::FORWARDED_PENRO_TO_TSD => $dates[self::RECEIVED_BY_PENRO] ?? null,
            self::RECEIVED_BY_TSD => $dates[self::FORWARDED_PENRO_TO_TSD] ?? null,
            self::FORWARDED_TSD_TO_CDS => $dates[self::RECEIVED_BY_TSD] ?? null,
            self::RECEIVED_BY_CDS => $dates[self::FORWARDED_TSD_TO_CDS] ?? null,
            self::FORWARDED_CDS_TO_PENRO => $dates[self::RECEIVED_BY_CDS] ?? null,
            self::RECEIVED_BY_PENRO_FINAL => $dates[self::FORWARDED_CDS_TO_PENRO] ?? null,
            self::FORWARDED_PENRO_TO_RECORDS => $dates[self::RECEIVED_BY_PENRO_FINAL] ?? null,
            self::RECEIVED_BY_RECORDS_FINAL => $dates[self::FORWARDED_PENRO_TO_RECORDS] ?? null,
            self::RELEASED_TO_REGIONAL => $dates[self::RECEIVED_BY_RECORDS_FINAL] ?? null,
            default => null,
        };
        return $previous;
    }

    private function currentLocation(ConservationReportSubmission $report, array $dates): string
    {
        if (isset($dates[self::RELEASED_TO_REGIONAL])) return 'Regional Office';
        if (isset($dates[self::RECEIVED_BY_RECORDS_FINAL])) return 'PENRO Records — For Regional Release';
        if (isset($dates[self::FORWARDED_PENRO_TO_RECORDS])) return 'For Receipt by PENRO Records';
        if (isset($dates[self::PENRO_TO_RECORDS])) return 'PENRO Records — For Regional Release';
        if (isset($dates[self::RECEIVED_BY_PENRO_FINAL])) return 'Office of the PENRO';
        if (isset($dates[self::FORWARDED_CDS_TO_PENRO])) return 'For Receipt by Office of the PENRO';
        if (isset($dates[self::RECEIVED_BY_CDS])) return 'CDS';
        if (isset($dates[self::FORWARDED_TSD_TO_CDS])) return 'For Receipt by CDS';
        if (isset($dates[self::RECEIVED_BY_TSD])) return 'TSD';
        if (isset($dates[self::FORWARDED_PENRO_TO_TSD])) return 'For Receipt by TSD';
        if (isset($dates[self::RECEIVED_BY_PENRO])) return 'Office of the PENRO';
        if (isset($dates[self::FORWARDED_RECORDS_TO_PENRO])) return 'For Receipt by Office of the PENRO';
        if (isset($dates[self::RECORDS_RECEIVED])) return 'PENRO Records';
        if (isset($dates[SubmissionTrackingService::CENRO_RELEASE])) return 'Awaiting PENRO Receipt';
        return $this->routingPolicy->isDirectPenro($report) ? 'Awaiting PENRO Receipt' : 'CENRO';
    }

    private function currentStatus(array $definitions, array $dates, ?string $nextKey, ConservationReportSubmission $report): string
    {
        if (isset($dates[self::RELEASED_TO_REGIONAL])) return 'Released to Regional Office';
        if (! isset($dates[self::RECORDS_RECEIVED])) return 'Awaiting PENRO Receipt';
        if ($nextKey === null) return 'For Regional Release';
        $definition = collect($definitions)->firstWhere('key', $nextKey);
        if (! $definition) return 'Processing at PENRO';
        return $nextKey === self::RECORDS_RECEIVED || str_starts_with($nextKey, 'received_')
            ? ($definition['awaiting_label'] ?? $definition['label'])
            : 'At '.$definition['held_at'];
    }

    private function nextExpectedAction(?string $nextKey, bool $complete): string
    {
        if ($complete || $nextKey === null) return 'No further routing action';

        return match ($nextKey) {
            SubmissionTrackingService::CENRO_RELEASE => 'Record CENRO Release',
            self::RECORDS_RECEIVED => 'Record PENRO Receipt',
            self::FORWARDED_RECORDS_TO_PENRO => 'Forward to Office of the PENRO',
            self::RECEIVED_BY_PENRO, self::RECEIVED_BY_PENRO_FINAL => 'Record Receipt by Office of the PENRO',
            self::FORWARDED_PENRO_TO_TSD => 'Forward to TSD',
            self::RECEIVED_BY_TSD => 'Record Receipt by TSD',
            self::FORWARDED_TSD_TO_CDS => 'Forward to CDS',
            self::RECEIVED_BY_CDS => 'Record Receipt by CDS',
            self::FORWARDED_CDS_TO_PENRO => 'Return / Forward to Office of the PENRO',
            self::FORWARDED_PENRO_TO_RECORDS => 'Forward to PENRO Records',
            self::RECEIVED_BY_RECORDS_FINAL => 'Record Receipt by PENRO Records',
            self::RELEASED_TO_REGIONAL => 'Release / Endorse to Regional Office',
            default => 'Continue routing',
        };
    }

    private function delayType(string $key): ?string
    {
        return str_starts_with($key, 'received_by_') ? 'receipt' : (str_starts_with($key, 'forwarded_') ? 'processing' : null);
    }

    /** @param \Illuminate\Support\Collection<int, PambRoutingEvent> $events */
    private function eventsByStage(\Illuminate\Support\Collection $events): \Illuminate\Support\Collection
    {
        return $events->sortBy('id')->mapWithKeys(function (PambRoutingEvent $event): array {
            return [$this->canonicalStageKey((string) $event->stage_key) => $event];
        });
    }

    public function canonicalStageKey(string $stageKey): string
    {
        return self::LEGACY_STAGE_ALIASES[$stageKey] ?? $stageKey;
    }

    private function workingDaysBetween(CarbonImmutable $start, CarbonImmutable $end, ConservationReportSubmission $report): int
    {
        return $this->calendar->workingDaysBetween($start, $end, 'after_through', $report->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS);
    }

    /** @return array{value:int|string|null,status:string} */
    private function summaryMetric(?CarbonImmutable $start, ?CarbonImmutable $end, ConservationReportSubmission $report, ?string $fallback = null): array
    {
        if ($fallback !== null) return ['value' => $fallback, 'status' => 'not_applicable'];
        return $start && $end
            ? ['value' => $this->workingDaysBetween($start, $end, $report), 'status' => 'ready']
            : ['value' => null, 'status' => 'pending'];
    }

    /** @return array<string, string> */
    private function canonicalActors(ConservationReportSubmission $report): array
    {
        return AuditLog::query()
            ->where('event_type', 'submission_tracking')
            ->where('entity_type', 'conservation')
            ->where('entity_id', (string) $report->getKey())
            ->with('user:id,name')
            ->latest('id')
            ->get()
            ->filter(fn (AuditLog $log): bool => filled($log->metadata['stage'] ?? null) && filled($log->user?->name))
            ->groupBy(function (AuditLog $log): string {
                return match ($log->metadata['stage']) {
                    SubmissionTrackingService::PENRO_RECEIPT => self::RECORDS_RECEIVED,
                    SubmissionTrackingService::REGIONAL_ENDORSEMENT => self::RECORDS_TO_REGIONAL,
                    default => (string) $log->metadata['stage'],
                };
            })
            ->mapWithKeys(fn ($logs, string $stage): array => [$stage => $logs->first()->user->name])
            ->all();
    }

    private function parseDateTime(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['occurred_at' => 'Enter a valid real-world event date and time.']);
        }
    }

    private function date(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->startOfDay();
    }

    /** @return array{key:string,label:string} */
    private function stageDefinition(string $key): array
    {
        return collect([
            self::FORWARDED_RECORDS_TO_PENRO => 'Forwarded to Office of the PENRO',
            self::RECEIVED_BY_PENRO => 'Received by Office of the PENRO',
            self::FORWARDED_PENRO_TO_TSD => 'Forwarded to TSD',
            self::RECEIVED_BY_TSD => 'Received by TSD',
            self::FORWARDED_TSD_TO_CDS => 'Forwarded to CDS',
            self::RECEIVED_BY_CDS => 'Received by CDS',
            self::FORWARDED_CDS_TO_PENRO => 'Returned/Forwarded to Office of the PENRO',
            self::RECEIVED_BY_PENRO_FINAL => 'Received by Office of the PENRO',
            self::FORWARDED_PENRO_TO_RECORDS => 'Forwarded to PENRO Records',
            self::RECEIVED_BY_RECORDS_FINAL => 'Received by PENRO Records',
        ])->map(fn (string $label, string $stage): array => ['key' => $stage, 'label' => $label])->get($key);
    }
}
