<?php

namespace App\Services\SubmissionTracking;

use App\Models\AuditLog;
use App\Models\ConservationReportSubmission;
use App\Models\PambRoutingEvent;
use App\Services\AuditLogService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Notifications\EdatsInAppNotificationService;
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
    public const FORWARDED_CDS_FOCAL_TO_CHIEF = 'forwarded_cds_focal_to_chief';
    public const RECEIVED_BY_CDS_CHIEF = 'received_by_cds_chief';
    public const FORWARDED_CDS_TO_PENRO = 'forwarded_cds_to_penro';
    public const RECEIVED_BY_PENRO_FINAL = 'received_by_penro_final';
    public const PENRO_FINAL_RETURNED_FOR_CORRECTION = 'penro_final_returned_for_correction';
    public const PENRO_FINAL_APPROVED_FOR_REGIONAL = 'penro_final_approved_for_regional';
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
        self::FORWARDED_CDS_FOCAL_TO_CHIEF,
        self::RECEIVED_BY_CDS_CHIEF,
        self::FORWARDED_CDS_TO_PENRO,
        self::RECEIVED_BY_PENRO_FINAL,
        self::PENRO_FINAL_RETURNED_FOR_CORRECTION,
        self::PENRO_FINAL_APPROVED_FOR_REGIONAL,
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
        private readonly OrganizationalAccessService $organization,
        private readonly EdatsInAppNotificationService $notifications,
        private readonly RoutingAttachmentService $routingAttachments,
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

    public function isInternalStageKey(string $stageKey): bool
    {
        [$baseStage] = $this->parseStageKey($stageKey);
        return in_array($baseStage, self::INTERNAL_STAGE_KEYS, true);
    }

    public function nextStageKey(ConservationReportSubmission $report): ?string
    {
        return $this->nextStageKeyFor($report, $report->routingEvents()->get()->sortBy('id')->values());
    }

    public function currentCycle(\Illuminate\Support\Collection $events): int
    {
        $max = $events->map(fn (PambRoutingEvent $event): int => $this->cycleForStageKey((string) $event->stage_key))->max() ?: 1;
        $last = $events->sortBy('id')->last();
        if ($last && $this->canonicalStageKey((string) $last->stage_key) === self::PENRO_FINAL_RETURNED_FOR_CORRECTION
            && $this->cycleForStageKey((string) $last->stage_key) === $max) {
            return $max + 1;
        }
        return $max;
    }

    public function isAwaitingOfficePenroFinalReceipt(ConservationReportSubmission $report): bool
    {
        $events = $report->routingEvents()->get()->sortBy('id')->values();
        $cycleEvents = $this->eventsForCycle($events, $this->currentCycle($events));
        $dates = $this->milestoneDates($report, $cycleEvents);

        return $this->canonicalStageKey((string) $this->nextStageKeyFor($report, $events)) === self::RECEIVED_BY_PENRO_FINAL
            && ! isset($dates[self::RECEIVED_BY_PENRO_FINAL]);
    }

    public function isAwaitingFinalVerdict(ConservationReportSubmission $report): bool
    {
        $events = $report->routingEvents()->get()->sortBy('id')->values();
        $cycleEvents = $this->eventsForCycle($events, $this->currentCycle($events));
        $dates = $this->milestoneDates($report, $cycleEvents);

        return $this->canonicalStageKey((string) $this->nextStageKeyFor($report, $events)) === self::RECEIVED_BY_PENRO_FINAL
            && isset($dates[self::RECEIVED_BY_PENRO_FINAL])
            && $this->finalVerdictForCycle($cycleEvents) === null;
    }

    public function stageCycle(string $stageKey): int
    {
        return $this->cycleForStageKey($stageKey);
    }

    public function record(
        ConservationReportSubmission $report,
        string $stageKey,
        string $occurredAt,
        ?int $userId = null,
        ?string $remarks = null,
    ): PambRoutingEvent {
        if (! $this->applies($report)) {
            throw ValidationException::withMessages(['stage' => 'Detailed routing is available only to PAMB workflows.']);
        }
        if (! $this->isInternalStageKey($stageKey)) {
            throw ValidationException::withMessages(['stage' => 'This routing stage is not an internal PAMB routing action.']);
        }

        [$baseStage, $requestedCycle] = $this->parseStageKey($stageKey);
        $allEvents = $report->routingEvents()->get()->sortBy('id')->values();
        $cycle = $requestedCycle ?? $this->currentCycle($allEvents);
        if ($requestedCycle !== null && $requestedCycle !== $cycle) {
            throw ValidationException::withMessages(['stage' => 'This routing cycle is no longer current.']);
        }

        $eventKey = $this->eventKey($baseStage, $cycle);
        $cycleEvents = $this->eventsForCycle($allEvents, $cycle);
        if ($cycleEvents->has($baseStage)) {
            throw ValidationException::withMessages(['stage' => 'This internal routing event has already been recorded.']);
        }

        $expected = $this->nextStageKeyFor($report, $allEvents);
        $isVerdict = in_array($baseStage, [self::PENRO_FINAL_RETURNED_FOR_CORRECTION, self::PENRO_FINAL_APPROVED_FOR_REGIONAL], true);
        if ($isVerdict) {
            $expectedBase = $this->canonicalStageKey((string) $expected);
            if ($expectedBase !== self::RECEIVED_BY_PENRO_FINAL || $this->finalVerdictForCycle($cycleEvents) !== null) {
                throw ValidationException::withMessages(['stage' => 'The Office of the PENRO final verdict is not currently available.']);
            }
        } elseif ((string) $expected !== $eventKey && (string) $expected !== $baseStage) {
            throw ValidationException::withMessages(['stage' => 'Record the preceding receipt or forwarding event before this routing action.']);
        }

        $date = $this->parseDateTime($occurredAt);
        $milestones = $this->milestoneDates($report, $cycleEvents);
        $previous = $this->previousDateFor($report, $baseStage, $cycle, $milestones, $allEvents);
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

        $cleanRemarks = filled($remarks) ? trim($remarks) : null;
        $event = DB::transaction(function () use ($report, $eventKey, $baseStage, $date, $userId, $cleanRemarks, $isVerdict, $cycle): PambRoutingEvent {
            $persistReport = $report;
            if ($isVerdict) {
                $persistReport = ConservationReportSubmission::query()->lockForUpdate()->findOrFail($report->getKey());
                $lockedEvents = $persistReport->routingEvents()->get()->sortBy('id')->values();
                $lockedCycle = $this->currentCycle($lockedEvents);
                $lockedCycleEvents = $this->eventsForCycle($lockedEvents, $lockedCycle);
                $lockedExpected = $this->nextStageKeyFor($persistReport, $lockedEvents);
                if ($lockedCycle !== $cycle
                    || $this->canonicalStageKey((string) $lockedExpected) !== self::RECEIVED_BY_PENRO_FINAL
                    || $this->finalVerdictForCycle($lockedCycleEvents) !== null) {
                    throw ValidationException::withMessages(['stage' => 'The Office of the PENRO final verdict is no longer available.']);
                }
            }

            $created = $persistReport->routingEvents()->create([
                'workflow_key' => $persistReport->workflow_key,
                'stage_key' => $eventKey,
                'occurred_at' => $date->toDateTimeString(),
                'recorded_by' => $userId,
                'remarks' => $cleanRemarks,
            ]);

            if ($isVerdict && $baseStage === self::PENRO_FINAL_APPROVED_FOR_REGIONAL) {
                $persistReport->routingEvents()->create([
                    'workflow_key' => $persistReport->workflow_key,
                    'stage_key' => $this->eventKey(self::FORWARDED_PENRO_TO_RECORDS, $cycle),
                    'occurred_at' => $date->toDateTimeString(),
                    'recorded_by' => $userId,
                    'remarks' => null,
                ]);
            }

            return $created;
        });

        $metadata = $this->stageDefinition($baseStage);
        try {
        $this->auditLogs->record(
            'submission_tracking',
            'PAMB Internal Routing Event Recorded',
            'conservation',
            $report->getKey(),
            $report->workflow_key,
            'Recorded '.$metadata['label'].' for conservation record #'.$report->getKey().'.',
            [
                'stage' => $baseStage,
                'stage_key' => $eventKey,
                'cycle' => $cycle,
                'occurred_at' => $date->toIso8601String(),
                'remarks' => $cleanRemarks,
                'workflow_key' => $report->workflow_key,
            ],
            $userId,
        );
        } catch (\Throwable $exception) {
            report($exception);
        }

        $event = $event->load('recordedBy');
        try {
            $this->notifications->notifyPambTransition($report, $event, $baseStage);
        } catch (\Throwable $exception) {
            report($exception);
        }
        return $event;
    }

    /** Persist the immutable event occurrence behind a canonical PAMB date milestone. */
    public function recordCanonical(ConservationReportSubmission $report, string $stage, string $date, ?int $userId, ?CarbonImmutable $occurredAt = null): PambRoutingEvent
    {
        $stageKey = match ($stage) {
            SubmissionTrackingService::CENRO_RELEASE => SubmissionTrackingService::CENRO_RELEASE,
            SubmissionTrackingService::PENRO_RECEIPT => self::RECORDS_RECEIVED,
            SubmissionTrackingService::REGIONAL_ENDORSEMENT => self::RELEASED_TO_REGIONAL,
            default => throw ValidationException::withMessages(['stage' => 'Unsupported canonical PAMB routing action.']),
        };

        $eventTimestamp = ($occurredAt ?? $this->date($date))->setTimezone(BusinessCalendarService::TIMEZONE);

        return $report->routingEvents()->create([
            'workflow_key' => $report->workflow_key,
            'stage_key' => $stageKey,
            'occurred_at' => $eventTimestamp->toDateTimeString(),
            'recorded_by' => $userId,
        ]);
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
        $allEvents = $report->routingEvents->sortBy('id')->values();
        $activeCycle = $this->currentCycle($allEvents);
        if ($activeCycle > 1) {
            return $this->presentCorrectionCycle($report, $allEvents, $asOf, $activeCycle);
        }
        $events = $this->eventsByStage($allEvents);
        $attachments = $this->routingAttachments->forPambEvents($allEvents->pluck('id'));
        $dates = $this->milestoneDates($report, $events);
        $definitions = $this->definitions($report);
        $hasRegionalEndorsement = isset($dates[self::RELEASED_TO_REGIONAL]);
        $legacyRegionalDate = $report->date_endorsed_regional && ! $hasRegionalEndorsement ? $this->date($report->date_endorsed_regional) : null;
        $nextKey = $hasRegionalEndorsement ? null : $this->nextStageKeyFor($report, $allEvents);
        $canonicalActors = $this->canonicalActors($report);
        $timeline = [];

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            $date = $dates[$key] ?? null;
            $notApplicable = $key === SubmissionTrackingService::CENRO_RELEASE && $this->routingPolicy->isDirectPenro($report);
            $status = $notApplicable
                ? 'not_applicable'
                : (($key === self::RECEIVED_BY_PENRO_FINAL && $nextKey === $key)
                    ? 'current'
                    : ($date ? 'completed' : ($hasRegionalEndorsement && in_array($key, self::INTERNAL_STAGE_KEYS, true) ? 'not_recorded' : ($nextKey === $key ? 'current' : 'pending'))));
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
            $actorInfo = $canonicalActors[$key] ?? [];
            $actor = $event?->recordedBy?->name ?? ($actorInfo['name'] ?? null);
            $actorContext = $this->actorContext($key, $event, $report);

            $timeline[] = [
                'key' => $key,
                'label' => $status === 'current' && $key === self::RECEIVED_BY_PENRO_FINAL
                    ? ($date ? 'For Final Review' : 'Awaiting Receipt by Office of the PENRO')
                    : ($status === 'current' && isset($definition['awaiting_label']) ? $definition['awaiting_label'] : $definition['label']),
                'held_at' => $definition['held_at'],
                'destination' => $definition['destination'] ?? null,
                'occurred_at' => $this->presentationTimestamp($key, $date, $event),
                'business_date' => $this->businessDate($key, $date),
                'previous_occurred_at' => $this->presentationTimestamp($previousKey, $previousDate, $previousEvent),
                'status' => $status,
                'elapsed_working_days' => $elapsed,
                'pending_working_days' => $pending,
                'delay_type' => $this->delayType($key),
                'recorded_by' => $actor,
                'actor_category' => $actorContext['category'] ?? ($actorInfo['category'] ?? null),
                'actor_category_label' => $actorContext['category_label'] ?? null,
                'actor_office' => $actorContext['office'] ?? ($actorInfo['office'] ?? null),
                'remarks' => $event?->remarks,
                'routing_event_id' => $event?->id,
                'attachment' => $event && isset($attachments[$event->id]) ? $this->routingAttachments->descriptor($attachments[$event->id]) : null,
                'is_internal' => in_array($key, self::INTERNAL_STAGE_KEYS, true),
                'can_record' => $key === $nextKey && in_array($key, self::INTERNAL_STAGE_KEYS, true) && ! ($key === self::RECEIVED_BY_PENRO_FINAL && $date),
                'action_label' => $key === self::RECEIVED_BY_PENRO_FINAL ? ($date ? 'Review and select an action' : 'Record Receipt') : ($definition['action_label'] ?? null),
                'actions' => $key === self::RECORDS_RECEIVED && $status === 'current' ? [
                    [
                        'key' => 'penro_receipt',
                        'label' => 'Receive',
                        'action_label' => 'Receive',
                        'correction' => false,
                        'attachment_allowed' => true,
                    ],
                    [
                        'key' => 'return_for_correction_penro_records',
                        'label' => 'Return for Correction',
                        'action_label' => 'Return for Correction',
                        'correction' => true,
                        'correction_reference_allowed' => true,
                        'receipt_correction_context' => 'penro_records',
                        'attachment_allowed' => false,
                    ],
                ] : [],
            ];
        }

        foreach ($allEvents->filter(fn (PambRoutingEvent $event): bool => in_array($this->canonicalStageKey((string) $event->stage_key), [self::PENRO_FINAL_RETURNED_FOR_CORRECTION, self::PENRO_FINAL_APPROVED_FOR_REGIONAL], true)) as $verdictEvent) {
            $verdict = $this->canonicalStageKey((string) $verdictEvent->stage_key);
            $timeline[] = [
                'key' => $verdictEvent->stage_key,
                'stage_key' => $verdictEvent->stage_key,
                'label' => $this->stageDefinition($verdict)['label'],
                'held_at' => 'Office of the PENRO',
                'destination' => $verdict === self::PENRO_FINAL_RETURNED_FOR_CORRECTION ? 'PENRO CDS Focal Person' : 'PENRO Records',
                'occurred_at' => $this->eventTimestamp($verdictEvent),
                'business_date' => null,
                'previous_occurred_at' => null,
                'status' => 'completed',
                'elapsed_working_days' => null,
                'pending_working_days' => null,
                'delay_type' => 'processing',
                'recorded_by' => $verdictEvent->recordedBy?->name,
                'actor_category' => $this->actorContext($verdict, $verdictEvent, $report)['category'] ?? null,
                'actor_category_label' => $this->actorContext($verdict, $verdictEvent, $report)['category_label'] ?? null,
                'actor_office' => $this->actorContext($verdict, $verdictEvent, $report)['office'] ?? null,
                'remarks' => $verdictEvent->remarks,
                'routing_event_id' => $verdictEvent->id,
                'attachment' => isset($attachments[$verdictEvent->id]) ? $this->routingAttachments->descriptor($attachments[$verdictEvent->id]) : null,
                'is_internal' => true,
                'can_record' => false,
                'action_label' => null,
            ];
        }
        $timelineOrder = array_flip([
            SubmissionTrackingService::CENRO_RELEASE, self::RECORDS_RECEIVED, self::FORWARDED_RECORDS_TO_PENRO,
            self::RECEIVED_BY_PENRO, self::FORWARDED_PENRO_TO_TSD, self::RECEIVED_BY_TSD,
            self::FORWARDED_TSD_TO_CDS, self::RECEIVED_BY_CDS, self::FORWARDED_CDS_FOCAL_TO_CHIEF,
            self::RECEIVED_BY_CDS_CHIEF, self::FORWARDED_CDS_TO_PENRO, self::RECEIVED_BY_PENRO_FINAL,
            self::PENRO_FINAL_RETURNED_FOR_CORRECTION, self::PENRO_FINAL_APPROVED_FOR_REGIONAL,
            self::FORWARDED_PENRO_TO_RECORDS, self::RECEIVED_BY_RECORDS_FINAL, self::RELEASED_TO_REGIONAL,
        ]);
        $timeline = collect($timeline)->sort(
            fn (array $left, array $right): int => $this->compareTimelineItems($left, $right, $timelineOrder),
        )->values()->all();
        $regional = $dates[self::RELEASED_TO_REGIONAL] ?? null;
        $currentLocation = $this->currentLocation($report, $dates, $nextKey);
        $currentStatus = $this->currentStatus($definitions, $dates, $nextKey, $report, isset($dates[self::RECEIVED_BY_PENRO_FINAL]));
        $currentStage = $nextKey ? collect($timeline)->firstWhere('key', $nextKey) : null;
        $lastAction = collect($timeline)
            ->filter(fn (array $item): bool => filled($item['occurred_at']))
            ->sort(fn (array $left, array $right): int => $this->compareTimelineItems($left, $right, $timelineOrder))
            ->last();
        $routingSummary = [
            'current_location' => $currentLocation,
            'current_status' => $currentStatus,
            'status_context' => $this->internalPenroStatusContext($report, $nextKey, $currentStage, $hasRegionalEndorsement),
            'responsible_office' => $currentStage['held_at'] ?? ($hasRegionalEndorsement ? 'Regional Office / Completed' : null),
            'pending_since' => $currentStage['previous_occurred_at'] ?? null,
            'working_days_pending' => $currentStage['pending_working_days'] ?? null,
            'next_expected_action' => $this->nextExpectedAction($nextKey, $hasRegionalEndorsement, isset($dates[self::RECEIVED_BY_PENRO_FINAL])),
            'last_action' => $lastAction ? [
                'label' => $lastAction['label'],
                'occurred_at' => $lastAction['occurred_at'],
                'recorded_by' => $lastAction['recorded_by'],
                'recorded_by_role' => $lastAction['actor_category_label'] ?? null,
                'recorded_by_office' => $lastAction['actor_office'] ?? null,
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
            'actions' => $currentStage['actions'] ?? [],
            'legacy_source_metadata' => $legacyRegionalDate ? ['regional_release_date' => $legacyRegionalDate->toDateString(), 'label' => 'Historical source regional release date; not a complete canonical eDATS routing chain.'] : null,
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

    /** @param \Illuminate\Support\Collection<int, PambRoutingEvent> $allEvents */
    private function presentCorrectionCycle(ConservationReportSubmission $report, \Illuminate\Support\Collection $allEvents, ?CarbonInterface $asOf, int $cycle): array
    {
        $nextKey = $this->nextStageKeyFor($report, $allEvents);
        $nextBase = $this->canonicalStageKey((string) $nextKey);
        $cycleEvents = $this->eventsForCycle($allEvents, $this->currentCycle($allEvents));
        $attachments = $this->routingAttachments->forPambEvents($allEvents->pluck('id'));
        $cycleDates = $this->milestoneDates($report, $cycleEvents);
        $finalReceiptRecorded = isset($cycleDates[self::RECEIVED_BY_PENRO_FINAL]);
        $definitions = collect($this->definitions($report))->keyBy('key');
        $timeline = [];

        foreach ($allEvents->sortBy(fn (PambRoutingEvent $event): array => [$this->eventTimestamp($event) ?? '', $event->id]) as $event) {
            $base = $this->canonicalStageKey((string) $event->stage_key);
            $definition = $definitions->get($base, []);
            $metadata = $this->stageDefinition($base);
            $actorContext = $this->actorContext($base, $event, $report);
            $timeline[] = [
                'key' => $event->stage_key,
                'stage_key' => $event->stage_key,
                'label' => $metadata['label'],
                'held_at' => $definition['held_at'] ?? null,
                'destination' => $definition['destination'] ?? null,
                'occurred_at' => $this->eventTimestamp($event),
                'business_date' => null,
                'previous_occurred_at' => null,
                'status' => 'completed',
                'elapsed_working_days' => null,
                'pending_working_days' => null,
                'delay_type' => $this->delayType($base),
                'recorded_by' => $event->recordedBy?->name,
                'actor_category' => $actorContext['category'] ?? null,
                'actor_category_label' => $actorContext['category_label'] ?? null,
                'actor_office' => $actorContext['office'] ?? null,
                'remarks' => $event->remarks,
                'routing_event_id' => $event->id,
                'attachment' => isset($attachments[$event->id]) ? $this->routingAttachments->descriptor($attachments[$event->id]) : null,
                'is_internal' => true,
                'can_record' => false,
                'action_label' => $definition['action_label'] ?? null,
            ];
        }

        foreach ([
            SubmissionTrackingService::CENRO_RELEASE => [$report->date_report_released_cenro, 'Released by CENRO', 'CENRO'],
            self::RECORDS_RECEIVED => [$report->date_received_penro, 'Received by PENRO Records', 'PENRO Records'],
            self::RELEASED_TO_REGIONAL => [$report->date_endorsed_regional, 'Released/Endorsed to Regional Office', 'Regional Office'],
        ] as $key => [$date, $label, $office]) {
            if (! $date || ($key === SubmissionTrackingService::CENRO_RELEASE && $this->routingPolicy->isDirectPenro($report))) continue;
            if ($allEvents->contains(fn (PambRoutingEvent $event): bool => $this->canonicalStageKey((string) $event->stage_key) === $key)) continue;
            $timeline[] = [
                'key' => $key,
                'stage_key' => $key,
                'label' => $label,
                'held_at' => $office,
                'destination' => $office,
                'occurred_at' => $this->date($date)->toDateString(),
                'business_date' => $this->date($date)->toDateString(),
                'previous_occurred_at' => null,
                'status' => 'completed',
                'elapsed_working_days' => null,
                'pending_working_days' => null,
                'delay_type' => null,
                'recorded_by' => null,
                'remarks' => null,
                'is_internal' => false,
                'can_record' => false,
                'action_label' => null,
            ];
        }

        $timeline = collect($timeline)->sortBy(fn (array $item): string => $item['occurred_at'] ?? '')->values()->all();
        $current = null;
        if ($nextKey !== null) {
            $currentIndex = collect($timeline)->search(fn (array $item): bool => ($item['stage_key'] ?? $item['key']) === $nextKey);
            if ($currentIndex !== false) {
                $timeline[$currentIndex]['status'] = 'current';
                $timeline[$currentIndex]['label'] = $nextBase === self::RECEIVED_BY_PENRO_FINAL
                    ? ($finalReceiptRecorded ? 'For Final Review' : 'Awaiting Receipt by Office of the PENRO')
                    : ($timeline[$currentIndex]['label'] ?? $nextBase);
                $current = $timeline[$currentIndex];
            } elseif ($nextBase !== self::RELEASED_TO_REGIONAL) {
                $definition = $definitions->get($nextBase, []);
                $metadata = $this->stageDefinition($nextBase);
                $current = [
                    'key' => $nextKey,
                    'stage_key' => $nextKey,
                    'label' => $nextBase === self::RECEIVED_BY_PENRO_FINAL ? ($finalReceiptRecorded ? 'For Final Review' : 'Awaiting Receipt by Office of the PENRO') : ($definition['awaiting_label'] ?? $metadata['label']),
                    'held_at' => $definition['held_at'] ?? null,
                    'destination' => $definition['destination'] ?? null,
                    'occurred_at' => null,
                    'previous_occurred_at' => null,
                    'status' => 'current',
                    'elapsed_working_days' => null,
                    'pending_working_days' => null,
                    'delay_type' => $this->delayType($nextBase),
                    'recorded_by' => null,
                    'remarks' => null,
                    'is_internal' => true,
                    'can_record' => ! ($nextBase === self::RECEIVED_BY_PENRO_FINAL && $finalReceiptRecorded),
                    'action_label' => $nextBase === self::RECEIVED_BY_PENRO_FINAL ? ($finalReceiptRecorded ? 'Review and select an action' : 'Record Receipt') : ($definition['action_label'] ?? null),
                ];
                $timeline[] = $current;
            } else {
                $current = ['key' => $nextKey, 'stage_key' => $nextKey, 'label' => 'Awaiting Regional Release', 'status' => 'current', 'is_internal' => false, 'can_record' => false, 'occurred_at' => null, 'recorded_by' => null, 'remarks' => null];
                $timeline[] = $current;
            }
        }

        $last = collect($timeline)->filter(fn (array $item): bool => filled($item['occurred_at']))->last();
        $regional = $report->date_endorsed_regional ? $this->date($report->date_endorsed_regional) : null;
        $currentLocation = $this->locationForStage($nextBase, $report);
        $currentStatus = $this->statusForStage($nextBase, $nextKey === null, $report, $finalReceiptRecorded);

        return [
            'applicable' => true,
            'workflow_key' => $report->workflow_key,
            'current_document_location' => $currentLocation,
            'current_processing_status' => $currentStatus,
            'routing_summary' => [
                'current_location' => $currentLocation,
                'current_status' => $currentStatus,
                'status_context' => null,
                'responsible_office' => $current['held_at'] ?? ($nextBase === self::RELEASED_TO_REGIONAL ? 'Regional Office / Completed' : null),
                'pending_since' => $current['previous_occurred_at'] ?? null,
                'working_days_pending' => null,
                'next_expected_action' => $this->nextExpectedAction($nextBase, $nextKey === null, $finalReceiptRecorded),
                'last_action' => $last ? ['label' => $last['label'], 'occurred_at' => $last['occurred_at'], 'recorded_by' => $last['recorded_by'], 'remarks' => $last['remarks']] : null,
                'last_updated' => $last['occurred_at'] ?? null,
            ],
            'timeline' => $timeline,
            'summary_metrics' => [
                'cenro_to_penro' => $this->summaryMetric($this->routingPolicy->isDirectPenro($report) ? null : ($report->date_report_released_cenro ? $this->date($report->date_report_released_cenro) : null), $report->date_received_penro ? $this->date($report->date_received_penro) : null, $report, $this->routingPolicy->isDirectPenro($report) ? 'N/A' : null),
                'penro_to_regional' => $this->summaryMetric($report->date_received_penro ? $this->date($report->date_received_penro) : null, $regional, $report),
                'cenro_to_regional' => $this->summaryMetric($this->routingPolicy->isDirectPenro($report) ? null : ($report->date_report_released_cenro ? $this->date($report->date_report_released_cenro) : null), $regional, $report, $this->routingPolicy->isDirectPenro($report) ? 'N/A' : null),
                'total_working_days_pending_at_penro' => $this->summaryMetric($report->date_received_penro ? $this->date($report->date_received_penro) : null, $regional ?? ($asOf ? CarbonImmutable::instance($asOf) : CarbonImmutable::now(BusinessCalendarService::TIMEZONE)), $report),
            ],
        ];
    }
    /** @return array{label:string,current_unit:string}|null */
    private function internalPenroStatusContext(ConservationReportSubmission $report, ?string $nextKey, ?array $currentStage, bool $complete): ?array
    {
        if ($complete || blank($report->date_received_penro) || filled($report->date_endorsed_regional) || blank($nextKey) || ! $this->isInternalStageKey($nextKey)) {
            return null;
        }

        $currentUnit = $currentStage['held_at'] ?? null;
        if (blank($currentUnit)) {
            return null;
        }

        return [
            'label' => 'PENRO internal routing in progress',
            'current_unit' => $currentUnit,
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
        if ($report->date_endorsed_regional && (isset($dates[self::RECEIVED_BY_RECORDS_FINAL]) || ($events instanceof \Illuminate\Support\Collection && $events->has(self::RELEASED_TO_REGIONAL)))) {
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
            ['key' => self::FORWARDED_PENRO_TO_TSD, 'label' => 'Forwarded to PENRO TSD Chief', 'awaiting_label' => 'Forwarded to PENRO TSD Chief', 'action_label' => 'Record Forwarding to PENRO TSD Chief', 'held_at' => 'Office of the PENRO', 'destination' => 'PENRO TSD Chief'],
            ['key' => self::RECEIVED_BY_TSD, 'label' => 'Received by PENRO TSD Chief', 'awaiting_label' => 'Awaiting Receipt by PENRO TSD Chief', 'action_label' => 'Record Receipt by PENRO TSD Chief', 'held_at' => 'PENRO TSD Chief', 'destination' => 'PENRO TSD Chief'],
            ['key' => self::FORWARDED_TSD_TO_CDS, 'label' => 'Forwarded to PENRO CDS Focal Person', 'awaiting_label' => 'Forwarded to PENRO CDS Focal Person', 'action_label' => 'Record Forwarding to PENRO CDS Focal Person', 'held_at' => 'PENRO TSD Chief', 'destination' => 'PENRO CDS Focal Person'],
            ['key' => self::RECEIVED_BY_CDS, 'label' => 'Received by PENRO CDS Focal Person', 'awaiting_label' => 'Awaiting Receipt by PENRO CDS Focal Person', 'action_label' => 'Record Receipt by PENRO CDS Focal Person', 'held_at' => 'PENRO CDS Focal Person', 'destination' => 'PENRO CDS Focal Person'],
            ['key' => self::FORWARDED_CDS_FOCAL_TO_CHIEF, 'label' => 'Forwarded to PENRO CDS Chief', 'awaiting_label' => 'Forwarded to PENRO CDS Chief', 'action_label' => 'Forward to PENRO CDS Chief', 'held_at' => 'PENRO CDS Focal Person', 'destination' => 'PENRO CDS Chief'],
            ['key' => self::RECEIVED_BY_CDS_CHIEF, 'label' => 'Received by PENRO CDS Chief', 'awaiting_label' => 'Awaiting Review by PENRO CDS Chief', 'action_label' => 'Receive / Review', 'held_at' => 'PENRO CDS Chief', 'destination' => 'PENRO CDS Chief'],
            ['key' => self::FORWARDED_CDS_TO_PENRO, 'label' => 'Recommended to Office of the PENRO', 'awaiting_label' => 'Forwarded to Office of the PENRO', 'action_label' => 'Recommend to Office of the PENRO', 'held_at' => 'PENRO CDS Chief', 'destination' => 'Office of the PENRO'],
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
            self::FORWARDED_CDS_FOCAL_TO_CHIEF => self::RECEIVED_BY_CDS,
            self::RECEIVED_BY_CDS_CHIEF => self::FORWARDED_CDS_FOCAL_TO_CHIEF,
            self::FORWARDED_CDS_TO_PENRO => self::RECEIVED_BY_CDS_CHIEF,
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
            self::FORWARDED_CDS_FOCAL_TO_CHIEF => $dates[self::RECEIVED_BY_CDS] ?? null,
            self::RECEIVED_BY_CDS_CHIEF => $dates[self::FORWARDED_CDS_FOCAL_TO_CHIEF] ?? null,
            self::FORWARDED_CDS_TO_PENRO => $dates[self::RECEIVED_BY_CDS_CHIEF] ?? null,
            self::RECEIVED_BY_PENRO_FINAL => $dates[self::FORWARDED_CDS_TO_PENRO] ?? null,
            self::FORWARDED_PENRO_TO_RECORDS => $dates[self::RECEIVED_BY_PENRO_FINAL] ?? null,
            self::RECEIVED_BY_RECORDS_FINAL => $dates[self::FORWARDED_PENRO_TO_RECORDS] ?? null,
            self::RELEASED_TO_REGIONAL => $dates[self::RECEIVED_BY_RECORDS_FINAL] ?? null,
            default => null,
        };
        return $previous;
    }

    private function currentLocation(ConservationReportSubmission $report, array $dates, ?string $nextKey = null): string
    {
        if (isset($dates[self::RELEASED_TO_REGIONAL])) return 'Regional Office';
        if ($nextKey === self::RECEIVED_BY_PENRO_FINAL) return 'Office of the PENRO';
        if (isset($dates[self::RECEIVED_BY_RECORDS_FINAL])) return 'PENRO Records — For Regional Release';
        if (isset($dates[self::FORWARDED_PENRO_TO_RECORDS])) return 'For Receipt by PENRO Records';
        if (isset($dates[self::PENRO_TO_RECORDS])) return 'PENRO Records — For Regional Release';
        if (isset($dates[self::RECEIVED_BY_PENRO_FINAL])) return 'Office of the PENRO';
        if (isset($dates[self::FORWARDED_CDS_TO_PENRO])) return 'For Receipt by Office of the PENRO';
        if (isset($dates[self::RECEIVED_BY_CDS_CHIEF])) return 'PENRO CDS Chief';
        if (isset($dates[self::FORWARDED_CDS_FOCAL_TO_CHIEF])) return 'In Transit to PENRO CDS Chief';
        if (isset($dates[self::RECEIVED_BY_CDS])) return 'PENRO CDS';
        if (isset($dates[self::FORWARDED_TSD_TO_CDS])) return 'For Receipt by PENRO CDS Focal Person';
        if (isset($dates[self::RECEIVED_BY_TSD])) return 'PENRO TSD Chief';
        if (isset($dates[self::FORWARDED_PENRO_TO_TSD])) return 'For Receipt by PENRO TSD Chief';
        if (isset($dates[self::RECEIVED_BY_PENRO])) return 'Office of the PENRO';
        if (isset($dates[self::FORWARDED_RECORDS_TO_PENRO])) return 'For Receipt by Office of the PENRO';
        if (isset($dates[self::RECORDS_RECEIVED])) return 'PENRO Records';
        if (isset($dates[SubmissionTrackingService::CENRO_RELEASE])) return 'Awaiting PENRO Receipt';
        return $this->routingPolicy->isDirectPenro($report) ? 'Awaiting PENRO Receipt' : 'CENRO';
    }

    private function currentStatus(array $definitions, array $dates, ?string $nextKey, ConservationReportSubmission $report): string
    {
        if (isset($dates[self::RELEASED_TO_REGIONAL])) return 'Released to Regional Office';
        if ($nextKey === SubmissionTrackingService::CENRO_RELEASE) return RoutingStatusPresenter::PENDING_CENRO;
        if (! isset($dates[self::RECORDS_RECEIVED])) return 'Awaiting PENRO Receipt';
        if ($nextKey === null) return 'For Regional Release';
        if ($nextKey === self::RECEIVED_BY_PENRO_FINAL) return isset($dates[self::RECEIVED_BY_PENRO_FINAL]) ? 'For Final Review' : 'Awaiting Receipt by Office of the PENRO';
        if ($nextKey === self::RECEIVED_BY_RECORDS_FINAL) return 'Awaiting PENRO Records Receipt';
        if ($nextKey === self::RELEASED_TO_REGIONAL) return 'Ready for Regional Release';
        $definition = collect($definitions)->firstWhere('key', $nextKey);
        if (! $definition) return 'Processing at PENRO';
        return $nextKey === self::RECORDS_RECEIVED || str_starts_with($nextKey, 'received_')
            ? ($definition['awaiting_label'] ?? $definition['label'])
            : 'At '.$definition['held_at'];
    }

    private function nextExpectedAction(?string $nextKey, bool $complete, bool $finalReceiptRecorded = false): string
    {
        if ($complete || $nextKey === null) return 'No further routing action';

        return match ($nextKey) {
            SubmissionTrackingService::CENRO_RELEASE => 'Record CENRO Release',
            self::RECORDS_RECEIVED => 'Record PENRO Receipt',
            self::FORWARDED_RECORDS_TO_PENRO => 'Forward to Office of the PENRO',
            self::RECEIVED_BY_PENRO => 'Record Receipt by Office of the PENRO',
            self::FORWARDED_PENRO_TO_TSD => 'Forward to PENRO TSD Chief',
            self::RECEIVED_BY_TSD => 'Record Receipt by PENRO TSD Chief',
            self::FORWARDED_TSD_TO_CDS => 'Forward to PENRO CDS Focal Person',
            self::RECEIVED_BY_CDS => 'Record Receipt by PENRO CDS Focal Person',
            self::FORWARDED_CDS_FOCAL_TO_CHIEF => 'Forward to PENRO CDS Chief',
            self::RECEIVED_BY_CDS_CHIEF => 'Review / Receive by PENRO CDS Chief',
            self::FORWARDED_CDS_TO_PENRO => 'Recommend to Office of the PENRO',
            self::RECEIVED_BY_PENRO_FINAL => $finalReceiptRecorded ? 'Review and select an action' : 'Record Receipt',
            self::FORWARDED_PENRO_TO_RECORDS => 'Forward to PENRO Records',
            self::RECEIVED_BY_RECORDS_FINAL => 'Record Receipt by PENRO Records',
            self::RELEASED_TO_REGIONAL => 'Release / Endorse to Regional Office',
            default => 'Continue routing',
        };
    }

    private function locationForStage(string $stage, ConservationReportSubmission $report): string
    {
        return match ($stage) {
            self::RECEIVED_BY_CDS => 'PENRO CDS Focal Person',
            self::FORWARDED_CDS_FOCAL_TO_CHIEF => 'In Transit to PENRO CDS Chief',
            self::RECEIVED_BY_CDS_CHIEF => 'PENRO CDS Chief',
            self::FORWARDED_CDS_TO_PENRO => 'In Transit to Office of the PENRO',
            self::RECEIVED_BY_PENRO_FINAL, self::PENRO_FINAL_RETURNED_FOR_CORRECTION, self::PENRO_FINAL_APPROVED_FOR_REGIONAL => 'Office of the PENRO',
            self::FORWARDED_PENRO_TO_RECORDS => 'In Transit to PENRO Records',
            self::RECEIVED_BY_RECORDS_FINAL => 'For Receipt by PENRO Records',
            self::RELEASED_TO_REGIONAL => 'Regional Office',
            default => $this->routingPolicy->isDirectPenro($report) ? 'Awaiting PENRO Receipt' : 'CENRO',
        };
    }

    private function statusForStage(string $stage, bool $complete, ConservationReportSubmission $report, bool $finalReceiptRecorded = false): string
    {
        if ($complete || $stage === self::RELEASED_TO_REGIONAL) return 'Released to Regional Office';
        return match ($stage) {
            self::RECEIVED_BY_CDS => 'Needs Correction / Processing by PENRO CDS Focal Person',
            self::FORWARDED_CDS_FOCAL_TO_CHIEF => 'Awaiting PENRO CDS Chief Receipt',
            self::RECEIVED_BY_CDS_CHIEF => 'Awaiting PENRO CDS Chief Recommendation',
            self::FORWARDED_CDS_TO_PENRO => 'Awaiting Office of the PENRO Receipt',
            self::RECEIVED_BY_PENRO_FINAL => $finalReceiptRecorded ? 'For Final Review' : 'Awaiting Receipt by Office of the PENRO',
            self::RECEIVED_BY_RECORDS_FINAL => 'Awaiting PENRO Records Receipt',
            self::RELEASED_TO_REGIONAL => 'Ready for Regional Release',
            self::FORWARDED_PENRO_TO_RECORDS => 'Awaiting PENRO Records Receipt',
            self::RECEIVED_BY_RECORDS_FINAL => 'Ready for Regional Release',
            default => $this->routingPolicy->isDirectPenro($report) ? 'Awaiting PENRO Receipt' : 'Processing at PENRO',
        };
    }

    private function delayType(string $key): ?string
    {
        return str_starts_with($key, 'received_by_') ? 'receipt' : (str_starts_with($key, 'forwarded_') ? 'processing' : null);
    }

    /** @param \Illuminate\Support\Collection<int, PambRoutingEvent> $events */
    private function eventsByStage(\Illuminate\Support\Collection $events): \Illuminate\Support\Collection
    {
        return $this->eventsForCycle($events, 1);
    }

    /** @param \Illuminate\Support\Collection<int, PambRoutingEvent> $events */
    private function eventsForCycle(\Illuminate\Support\Collection $events, int $cycle): \Illuminate\Support\Collection
    {
        return $events
            ->filter(fn (PambRoutingEvent $event): bool => $this->cycleForStageKey((string) $event->stage_key) === $cycle)
            ->sortBy('id')
            ->mapWithKeys(fn (PambRoutingEvent $event): array => [$this->canonicalStageKey((string) $event->stage_key) => $event]);
    }

    private function eventKey(string $baseStage, int $cycle): string
    {
        return $cycle === 1 ? $baseStage : $baseStage.'__cycle_'.$cycle;
    }

    /** @return array{0:string,1:?int} */
    private function parseStageKey(string $stageKey): array
    {
        if (preg_match('/^(.*)__cycle_(\d+)$/', $stageKey, $matches)) {
            return [$this->canonicalStageKey($matches[1]), (int) $matches[2]];
        }
        return [$this->canonicalStageKey($stageKey), null];
    }

    private function cycleForStageKey(string $stageKey): int
    {
        [, $cycle] = $this->parseStageKeyWithoutRecursion($stageKey);
        return $cycle ?? 1;
    }

    /** @return array{0:string,1:?int} */
    private function parseStageKeyWithoutRecursion(string $stageKey): array
    {
        if (preg_match('/^(.*)__cycle_(\d+)$/', $stageKey, $matches)) {
            return [self::LEGACY_STAGE_ALIASES[$matches[1]] ?? $matches[1], (int) $matches[2]];
        }
        return [self::LEGACY_STAGE_ALIASES[$stageKey] ?? $stageKey, null];
    }

    /** @param \Illuminate\Support\Collection<int, PambRoutingEvent> $events */
    private function finalVerdictForCycle(\Illuminate\Support\Collection $events): ?string
    {
        foreach ([self::PENRO_FINAL_RETURNED_FOR_CORRECTION, self::PENRO_FINAL_APPROVED_FOR_REGIONAL] as $verdict) {
            if ($events->has($verdict)) return $verdict;
        }
        return null;
    }

    /** @param \Illuminate\Support\Collection<int, PambRoutingEvent> $events */
    private function nextStageKeyFor(ConservationReportSubmission $report, \Illuminate\Support\Collection $events): ?string
    {
        if ($report->date_endorsed_regional) return null;

        $cycle = $this->currentCycle($events);
        $cycleEvents = $this->eventsForCycle($events, $cycle);
        $dates = $this->milestoneDates($report, $cycleEvents);

        if (! $this->routingPolicy->isDirectPenro($report) && ! isset($dates[SubmissionTrackingService::CENRO_RELEASE])) {
            return SubmissionTrackingService::CENRO_RELEASE;
        }
        if (! isset($dates[self::RECORDS_RECEIVED])) return self::RECORDS_RECEIVED;

        $sequence = $cycle === 1
            ? [
                self::FORWARDED_RECORDS_TO_PENRO, self::RECEIVED_BY_PENRO,
                self::FORWARDED_PENRO_TO_TSD, self::RECEIVED_BY_TSD,
                self::FORWARDED_TSD_TO_CDS, self::RECEIVED_BY_CDS,
                self::FORWARDED_CDS_FOCAL_TO_CHIEF, self::RECEIVED_BY_CDS_CHIEF,
                self::FORWARDED_CDS_TO_PENRO, self::RECEIVED_BY_PENRO_FINAL,
            ]
            : [
                self::RECEIVED_BY_CDS, self::FORWARDED_CDS_FOCAL_TO_CHIEF,
                self::RECEIVED_BY_CDS_CHIEF, self::FORWARDED_CDS_TO_PENRO,
                self::RECEIVED_BY_PENRO_FINAL,
            ];

        foreach ($sequence as $stage) {
            if (! isset($dates[$stage])) return $this->eventKey($stage, $cycle);
        }

        $verdict = $this->finalVerdictForCycle($cycleEvents);
        if ($verdict === null) return $this->eventKey(self::RECEIVED_BY_PENRO_FINAL, $cycle);

        if ($verdict === self::PENRO_FINAL_RETURNED_FOR_CORRECTION) {
            return $this->eventKey(self::RECEIVED_BY_CDS, $cycle + 1);
        }

        foreach ([self::FORWARDED_PENRO_TO_RECORDS, self::RECEIVED_BY_RECORDS_FINAL] as $stage) {
            if (! isset($dates[$stage])) return $this->eventKey($stage, $cycle);
        }

        return self::RELEASED_TO_REGIONAL;
    }

    /** @param array<string, CarbonImmutable> $milestones @param \Illuminate\Support\Collection<int, PambRoutingEvent> $allEvents */
    private function previousDateFor(ConservationReportSubmission $report, string $stage, int $cycle, array $milestones, \Illuminate\Support\Collection $allEvents): ?CarbonImmutable
    {
        if ($stage === self::RECEIVED_BY_CDS && $cycle > 1) {
            $prior = $this->eventsForCycle($allEvents, $cycle - 1)->get(self::PENRO_FINAL_RETURNED_FOR_CORRECTION);
            return $prior?->occurred_at ? $this->date($prior->occurred_at) : null;
        }
        if ($stage === self::PENRO_FINAL_RETURNED_FOR_CORRECTION || $stage === self::PENRO_FINAL_APPROVED_FOR_REGIONAL) {
            return $milestones[self::RECEIVED_BY_PENRO_FINAL] ?? null;
        }
        if ($stage === self::FORWARDED_PENRO_TO_RECORDS) {
            return $milestones[self::PENRO_FINAL_APPROVED_FOR_REGIONAL] ?? null;
        }
        $previousKey = $this->previousKey($stage);
        return $previousKey ? ($milestones[$previousKey] ?? null) : null;
    }

    public function canonicalStageKey(string $stageKey): string
    {
        [$base] = $this->parseStageKeyWithoutRecursion($stageKey);
        return $base;
    }

    public function isCorrectionStageKey(string $stageKey): bool
    {
        return $this->canonicalStageKey($stageKey) === self::PENRO_FINAL_RETURNED_FOR_CORRECTION;
    }

    public function isCorrectionActionKey(string $actionKey): bool
    {
        return in_array($actionKey, [
            'return_for_correction_cenro_records',
            'return_for_correction_penro_records',
        ], true);
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

    /** @return array<string, array{name:?string,category:?string,office:?string}> */
    private function canonicalActors(ConservationReportSubmission $report): array
    {
        return AuditLog::query()
            ->where('event_type', 'submission_tracking')
            ->where('entity_type', 'conservation')
            ->where('entity_id', (string) $report->getKey())
            ->with('user:id,name,section,office_designated')
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
            ->mapWithKeys(function ($logs, string $stage): array {
                $user = $logs->first()->user;
                return [$stage => [
                    'name' => $user?->name,
                    'category' => $user ? $this->organization->effectiveCategory($user) : null,
                    'office' => $user ? $this->organization->normalizeOffice($user->office_designated) : null,
                ]];
            })
            ->all();
    }

    private function presentationTimestamp(?string $key, ?CarbonImmutable $date, ?PambRoutingEvent $event = null): ?string
    {
        if ($event) return $this->eventTimestamp($event);
        return $date?->toDateString();
    }

    private function eventTimestamp(PambRoutingEvent $event): ?string
    {
        $occurred = $event->occurred_at?->setTimezone(BusinessCalendarService::TIMEZONE);
        $created = $event->created_at?->setTimezone(BusinessCalendarService::TIMEZONE);

        // Legacy canonical milestones were stored at midnight because their
        // domain fields are date-only. Their row creation timestamp is the
        // precise action time when it falls on that same business date.
        if ($occurred && $created
            && $occurred->format('H:i:s') === '00:00:00'
            && $created->isSameDay($occurred)
            && $created->greaterThan($occurred)) {
            return $created->toIso8601String();
        }

        return $occurred?->toIso8601String();
    }

    private function compareTimelineItems(array $left, array $right, array $timelineOrder): int
    {
        $leftStamp = (string) ($left['occurred_at'] ?? '');
        $rightStamp = (string) ($right['occurred_at'] ?? '');
        if ($leftStamp === '' || $rightStamp === '') return $leftStamp === $rightStamp ? 0 : ($leftStamp === '' ? 1 : -1);

        $leftDate = substr($leftStamp, 0, 10);
        $rightDate = substr($rightStamp, 0, 10);
        if ($leftDate !== $rightDate) return strcmp($leftDate, $rightDate);

        $leftDateOnly = strlen($leftStamp) <= 10;
        $rightDateOnly = strlen($rightStamp) <= 10;
        if (!$leftDateOnly && !$rightDateOnly) {
            $timeOrder = strcmp($leftStamp, $rightStamp);
            if ($timeOrder !== 0) return $timeOrder;
        }

        $leftOrder = $timelineOrder[$this->canonicalStageKey((string) ($left['stage_key'] ?? $left['key']))] ?? PHP_INT_MAX;
        $rightOrder = $timelineOrder[$this->canonicalStageKey((string) ($right['stage_key'] ?? $right['key']))] ?? PHP_INT_MAX;
        return $leftOrder <=> $rightOrder;
    }

    private function businessDate(string $key, ?CarbonImmutable $date): ?string
    {
        return in_array($this->canonicalStageKey($key), [
            SubmissionTrackingService::CENRO_RELEASE,
            self::RECORDS_RECEIVED,
            self::RELEASED_TO_REGIONAL,
        ], true) ? $date?->toDateString() : null;
    }

    /** @return array{category:?string,category_label:?string,office:?string} */
    private function actorContext(string $stageKey, ?PambRoutingEvent $event, ConservationReportSubmission $report): array
    {
        $stage = $this->canonicalStageKey($stageKey);
        $category = match ($stage) {
            SubmissionTrackingService::CENRO_RELEASE => OrganizationalAccessService::CENRO_RECORDS,
            self::RECORDS_RECEIVED, self::FORWARDED_RECORDS_TO_PENRO, self::RECEIVED_BY_RECORDS_FINAL, self::RELEASED_TO_REGIONAL => OrganizationalAccessService::PENRO_RECORDS,
            self::RECEIVED_BY_PENRO, self::FORWARDED_PENRO_TO_TSD, self::RECEIVED_BY_PENRO_FINAL, self::PENRO_FINAL_RETURNED_FOR_CORRECTION, self::PENRO_FINAL_APPROVED_FOR_REGIONAL, self::FORWARDED_PENRO_TO_RECORDS => OrganizationalAccessService::OFFICE_PENRO,
            self::RECEIVED_BY_TSD, self::FORWARDED_TSD_TO_CDS => OrganizationalAccessService::PENRO_TSD_CHIEF,
            self::RECEIVED_BY_CDS, self::FORWARDED_CDS_FOCAL_TO_CHIEF => OrganizationalAccessService::PENRO_FOCAL,
            self::RECEIVED_BY_CDS_CHIEF, self::FORWARDED_CDS_TO_PENRO => OrganizationalAccessService::PENRO_CHIEF,
            default => null,
        };
        $office = $category && in_array($category, [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL], true)
            ? $this->organization->normalizeOffice($report->target_office)
            : ($event?->recordedBy ? $this->organization->normalizeOffice($event->recordedBy->office_designated) : null);

        return [
            'category' => $category,
            'category_label' => $category ? $this->organization->categoryLabel($category) : null,
            'office' => $office,
        ];
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
            self::FORWARDED_PENRO_TO_TSD => 'Forwarded to PENRO TSD Chief',
            self::RECEIVED_BY_TSD => 'Received by PENRO TSD Chief',
            self::FORWARDED_TSD_TO_CDS => 'Forwarded to PENRO CDS Focal Person',
            self::RECEIVED_BY_CDS => 'Received by PENRO CDS Focal Person',
            self::FORWARDED_CDS_FOCAL_TO_CHIEF => 'Forwarded to PENRO CDS Chief',
            self::RECEIVED_BY_CDS_CHIEF => 'Received by PENRO CDS Chief',
            self::FORWARDED_CDS_TO_PENRO => 'Recommended to Office of the PENRO',
            self::RECEIVED_BY_PENRO_FINAL => 'Received by Office of the PENRO',
            self::PENRO_FINAL_RETURNED_FOR_CORRECTION => 'Office of the PENRO Returned for CDS Correction',
            self::PENRO_FINAL_APPROVED_FOR_REGIONAL => 'Office of the PENRO Approved for Regional Release',
            self::FORWARDED_PENRO_TO_RECORDS => 'Forwarded to PENRO Records',
            self::RECEIVED_BY_RECORDS_FINAL => 'Received by PENRO Records',
        ])->map(fn (string $label, string $stage): array => ['key' => $stage, 'label' => $label])->get($key);
    }
}
