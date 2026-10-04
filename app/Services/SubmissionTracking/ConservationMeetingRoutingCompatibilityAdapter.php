<?php

namespace App\Services\SubmissionTracking;

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use Illuminate\Support\Collection;

/**
 * Projects persisted meeting-workflow custody events into the shared routing
 * state machine. The projection is in-memory only: legacy PAMB rows are never
 * rewritten and no synthetic custody row is persisted.
 */
final class ConservationMeetingRoutingCompatibilityAdapter
{
    /** @var array<string,string> */
    private const ACTIONS = [
        SubmissionTrackingService::CENRO_RELEASE => 'forward_to_penro_records',
        SubmissionTrackingService::PENRO_RECEIPT => 'receive_at_penro_records',
        SubmissionTrackingService::REGIONAL_ENDORSEMENT => 'release_to_regional',
        PambRoutingTimelineService::RECORDS_RECEIVED => 'receive_at_penro_records',
        PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO => 'forward_to_office_penro',
        PambRoutingTimelineService::RECEIVED_BY_PENRO => 'receive_at_office_penro',
        PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD => 'assign_to_tsd_chief',
        PambRoutingTimelineService::RECEIVED_BY_TSD => 'receive_at_tsd_chief',
        PambRoutingTimelineService::FORWARDED_TSD_TO_CDS => 'forward_to_cds_focal',
        PambRoutingTimelineService::RECEIVED_BY_CDS => 'receive_at_cds_focal',
        PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF => 'forward_to_cds_chief',
        PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF => 'receive_at_cds_chief',
        PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO => 'recommend_to_office_penro',
        PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL => 'receive_at_office_penro_final',
        PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION => 'return_from_office_for_correction',
        PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL => 'approve_for_regional_release',
        PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL => 'receive_at_penro_records_final',
        PambRoutingTimelineService::RELEASED_TO_REGIONAL => 'release_to_regional',
    ];

    public function applies(ConservationReportSubmission $record): bool
    {
        return PambRoutingTimelineService::appliesWorkflow((string) $record->workflow_key);
    }

    public function actionForLegacyStage(ConservationReportSubmission $record, string $stageKey): ?string
    {
        if (! $this->applies($record)) return null;
        $base = app(PambRoutingTimelineService::class)->canonicalStageKey($stageKey);
        $cycle = app(PambRoutingTimelineService::class)->stageCycle($stageKey);
        if ($base === PambRoutingTimelineService::RECEIVED_BY_CDS && $cycle > 1) return 'receive_correction';
        $action = self::ACTIONS[$base] ?? null;
        if (! $action) return null;

        return $action;
    }

    /** Resolve the active cycle without comparing row IDs from different tables. */
    public function activeCycle(ConservationReportSubmission $record, Collection $events): int
    {
        $legacy = $record->relationLoaded('routingEvents')
            ? $record->routingEvents
            : $record->routingEvents()->get();
        $legacyCycle = $legacy->isEmpty() ? 1 : app(PambRoutingTimelineService::class)->currentCycle($legacy);
        $sharedCycle = (int) $events->max(fn (DocumentRoutingEvent $event): int => (int) data_get($event->metadata, 'pamb_cycle', 1));
        return max($legacyCycle, $sharedCycle, 1);
    }

    /**
     * @param Collection<int,DocumentRoutingEvent>|null $sharedEvents
     * @return Collection<int,DocumentRoutingEvent>
     */
    public function events(ConservationReportSubmission $record, ?Collection $sharedEvents = null): Collection
    {
        if (! $this->applies($record)) return $sharedEvents ?? collect();

        $sharedEvents ??= DocumentRoutingEvent::query()
            ->where('source_type', 'conservation')->where('source_id', $record->getKey())
            ->with('recordedBy:id,name,section,office_designated')
            ->orderBy('occurred_at')->orderBy('id')->get();

        $pambEvents = $record->relationLoaded('routingEvents')
            ? $record->routingEvents
            : $record->routingEvents()->with('recordedBy:id,name,section,office_designated')->get();
        $pambEvents = $pambEvents->sort(function (PambRoutingEvent $left, PambRoutingEvent $right): int {
            $dateOrder = strcmp((string) $left->occurred_at?->toDateTimeString(), (string) $right->occurred_at?->toDateTimeString());
            return $dateOrder !== 0 ? $dateOrder : ((int) $left->id <=> (int) $right->id);
        })->values();

        $actions = collect(app(DocumentRoutingProfileRegistry::class)->actionProfile('conservation')['actions'])->keyBy('key');
        $adapted = collect();
        foreach ($pambEvents as $legacy) {
            $baseKey = app(PambRoutingTimelineService::class)->canonicalStageKey((string) $legacy->stage_key);
            $cycle = app(PambRoutingTimelineService::class)->stageCycle((string) $legacy->stage_key);
            $actionKey = self::ACTIONS[$baseKey] ?? null;

            // A PAMB correction cycle begins with the focal person receiving
            // the returned copy. In the shared graph that occurrence is the
            // correction acknowledgement state, not a fabricated forward.
            if ($baseKey === PambRoutingTimelineService::RECEIVED_BY_CDS && $cycle > 1) {
                $actionKey = 'receive_correction';
            }

            // Older PAMB approval persisted a second same-time
            // FORWARDED_PENRO_TO_RECORDS marker. The shared approval action
            // already owns that handoff, so the duplicate marker is omitted.
            if ($baseKey === PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS
                && $pambEvents->contains(fn (PambRoutingEvent $candidate): bool =>
                    app(PambRoutingTimelineService::class)->canonicalStageKey((string) $candidate->stage_key) === PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL
                    && $candidate->occurred_at?->equalTo($legacy->occurred_at)
                    && $candidate->recorded_by === $legacy->recorded_by)) {
                continue;
            }

            $action = $actionKey ? $actions->get($actionKey) : null;
            if (! $action && $baseKey === PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS) {
                // Some older rows contain the real final handoff but no
                // separately persisted approval event. Preserve that handoff
                // as evidence without inventing the missing approval.
                $actionKey = 'legacy_forward_penro_records';
                $action = [
                    'key' => $actionKey, 'from' => DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN,
                    'to' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL,
                    'event_key' => 'forwarded', 'from_office' => 'Office of the PENRO',
                    'to_office' => 'PENRO Records Unit',
                ];
            }
            if (! $action && $actionKey === 'receive_correction') {
                $action = [
                    'key' => 'receive_correction', 'from' => DocumentRoutingProfileRegistry::CDS_FOCAL,
                    'to' => DocumentRoutingProfileRegistry::CDS_FOCAL, 'event_key' => 'correction_received',
                    'from_office' => 'PENRO CDS Focal Person', 'to_office' => 'PENRO CDS Focal Person',
                ];
            }
            if (! $action) continue;

            // Once a shared event records this same legacy milestone, it is
            // authoritative. Avoid duplicate history entries without
            // comparing IDs across the two event tables.
            $alreadyShared = $sharedEvents->contains(fn (DocumentRoutingEvent $event): bool =>
                data_get($event->metadata, 'action_key') === $actionKey
                && $event->occurred_at?->equalTo($legacy->occurred_at)
                && (int) $event->recorded_by === (int) $legacy->recorded_by);
            if ($alreadyShared) continue;

            $event = new DocumentRoutingEvent([
                'source_type' => 'conservation',
                'source_id' => $record->getKey(),
                'workflow_key' => $record->workflow_key,
                'event_key' => $action['event_key'],
                'from_stage' => $action['from'],
                'to_stage' => $action['to'],
                'from_office' => $action['from_office'],
                'to_office' => $action['to_office'],
                'occurred_at' => $legacy->occurred_at,
                'recorded_by' => $legacy->recorded_by,
                'remarks' => $legacy->remarks,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
                'metadata' => [
                    'action_key' => $actionKey,
                    'state_source' => 'legacy_pamb_event_projection',
                    'legacy_pamb_event_id' => $legacy->id,
                    'legacy_pamb_stage_key' => $legacy->stage_key,
                    'legacy_cycle' => $cycle,
                    'correction' => $actionKey === 'return_from_office_for_correction',
                    'correction_cycle' => $cycle > 1 || $actionKey === 'return_from_office_for_correction',
                ],
            ]);
            $event->setRelation('recordedBy', $legacy->recordedBy);
            $event->exists = true;
            $event->id = -1 * (int) $legacy->id;
            $adapted->push($event);
        }

        // Chronology is based on occurrence timestamps. On equal timestamps
        // shared events win; IDs are only used within their own source.
        return $sharedEvents->concat($adapted)->sort(function (DocumentRoutingEvent $left, DocumentRoutingEvent $right): int {
            $dateOrder = strcmp((string) $left->occurred_at?->toDateTimeString(), (string) $right->occurred_at?->toDateTimeString());
            if ($dateOrder !== 0) return $dateOrder;
            $leftLegacy = data_get($left->metadata, 'legacy_pamb_event_id') !== null;
            $rightLegacy = data_get($right->metadata, 'legacy_pamb_event_id') !== null;
            if ($leftLegacy !== $rightLegacy) return $leftLegacy ? -1 : 1;
            $leftId = (int) (data_get($left->metadata, 'legacy_pamb_event_id') ?? $left->id);
            $rightId = (int) (data_get($right->metadata, 'legacy_pamb_event_id') ?? $right->id);
            return $leftId <=> $rightId;
        })->values();
    }

    public function isComplete(ConservationReportSubmission $record, ?Collection $events = null): bool
    {
        return $this->applies($record)
            && $record->date_endorsed_regional !== null
            && app(DocumentRoutingTransitionService::class)->state($record, 'conservation', $events)['stage'] === DocumentRoutingProfileRegistry::RELEASED_REGIONAL;
    }
}
