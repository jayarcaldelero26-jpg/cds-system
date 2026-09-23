<?php

namespace App\Services\SubmissionTracking;

use App\Models\AuditLog;
use App\Models\ConservationReportSubmission;
use App\Services\BusinessCalendarService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Support\DatePresentationNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Presents one server-derived routing contract for all tracker sources.
 * Compliance values remain owned by the source model and are only copied into
 * this presentation; no deadline or timeliness calculation is performed here.
 */
final class DocumentRoutingPresenter
{
    public function __construct(
        private readonly DocumentRoutingProfileRegistry $profiles,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
        private readonly BusinessCalendarService $calendar,
        private readonly OrganizationalAccessService $organization,
        private readonly RoutingAttachmentService $routingAttachments,
    ) {}

    /** @param Collection<int,AuditLog>|null $auditLogs @param Collection<int,\App\Models\DocumentRoutingEvent>|null $routingEvents */
    public function present(Model $record, string $sourceKey, ?Collection $auditLogs = null, ?Collection $routingEvents = null): array
    {
        return $this->presentCanonical($record, $sourceKey, $routingEvents ?? collect());
    }

    /** @param array<string,mixed> $pamb */
    public function presentPamb(Model $record, array $pamb): array
    {
        $summary = $pamb['routing_summary'] ?? [];
        $timeline = collect($pamb['timeline'] ?? [])->values()->all();
        $current = collect($timeline)->firstWhere('status', 'current');
        $isForwarded = str_starts_with((string) ($current['stage_key'] ?? $current['key'] ?? ''), 'forwarded_');
        // A pending forwarded stage still belongs to its sender. The
        // destination becomes accountable only after the forwarding event is
        // recorded and the next receipt stage is current.
        $pendingForward = $isForwarded && blank($current['occurred_at'] ?? null);
        $responsibleActor = $pendingForward
            ? ($current['held_at'] ?? null)
            : ($isForwarded ? ($current['destination'] ?? null) : ($current['held_at'] ?? null));
        $responsibleCategory = $this->organization->normalizeCategory($responsibleActor);
        $preReleaseMovOwner = $this->preReleasePambMovOwner($record);
        if ($preReleaseMovOwner !== null) {
            $responsibleCategory = $preReleaseMovOwner;
            $responsibleActor = $this->organization->categoryLabel($preReleaseMovOwner);
        }
        $last = $summary['last_action'] ?? null;
        $overrides = \App\Models\SubmissionRoutingOverride::query()->where('source', 'conservation')->where('source_record_id', $record->getKey())->get()->keyBy('action_key');

        return [
            'profile_key' => 'pamb_detailed',
            'profile_label' => 'PAMB detailed routing',
            'route_granularity' => 'detailed',
            'business_route_confirmation' => false,
            'detailed_route_requires_confirmation' => false,
            'originating_office' => $this->routingPolicy->isDirectPenro($record) ? 'PENRO' : 'CENRO',
            'final_destination' => 'Regional Office',
            'current_location' => $summary['current_location'] ?? ($pamb['current_document_location'] ?? null),
            'current_status' => $summary['current_status'] ?? null,
            'responsible_office' => $this->officeForActor($record, $responsibleActor, $isForwarded ? ($current['destination'] ?? null) : ($summary['responsible_office'] ?? null)),
            'responsible_user_category' => $responsibleCategory,
            'current_stage' => $current['stage_key'] ?? $current['key'] ?? null,
            'in_transit_to' => $this->pambTransitDestination($timeline),
            'pending_since' => $summary['pending_since'] ?? null,
            'working_days_pending' => $summary['working_days_pending'] ?? null,
            'last_action' => $last,
            'last_updated' => $summary['last_updated'] ?? null,
            'recorded_by' => $last['recorded_by'] ?? null,
            'next_expected_action' => $this->preReleasePambMovAction($record) ?? ($summary['next_expected_action'] ?? null),
            'deadline' => $record->getAttribute('deadline_submission'),
            'compliance_status' => $record->getAttribute('timeliness'),
            'actions' => $pamb['actions'] ?? [],
            'timeline' => array_map(function (array $event) use ($overrides, $record): array {
                $override = $overrides->get($event['stage_key'] ?? $event['key']);
                return [
                ...$event,
                'from' => null,
                'to' => $event['destination'] ?? null,
                'event_type' => $this->pambEventType($event),
                'recorded_at' => $event['occurred_at'] ?? null,
                'actor_category' => $this->organization->normalizeCategory($event['held_at'] ?? null),
                'office' => $this->officeForActor($record, $event['held_at'] ?? null, null),
                'administrative_override' => (bool) $override,
                'override_for_category' => $override?->overridden_accountable_category,
                'override_for_office' => $override?->overridden_office,
            ];
            }, $timeline),
        ];
    }

    /**
     * Before the canonical CENRO-release milestone exists, Regular PAMB is
     * accountable to its CENRO MOV workflow. The detailed timeline still
     * starts at the release milestone for routing history, so its generic
     * "CENRO" holder cannot identify the operational Incoming owner.
     */
    private function preReleasePambMovOwner(Model $record): ?string
    {
        if (! $record instanceof ConservationReportSubmission
            || $this->routingPolicy->isDirectPenro($record)
            || $record->date_report_released_cenro !== null
            || ! app(PambMovProcessingService::class)->isApplicable($record)) {
            return null;
        }

        return match (app(PambMovProcessingService::class)->status($record)) {
            PambMovProcessingService::ACTIVITY_CONDUCTED,
            PambMovProcessingService::NEEDS_CORRECTION => OrganizationalAccessService::CENRO_FOCAL,
            PambMovProcessingService::SUBMITTED_FOR_REVIEW => OrganizationalAccessService::CENRO_CHIEF,
            PambMovProcessingService::READY_FOR_RELEASE => OrganizationalAccessService::CENRO_RECORDS,
            default => null,
        };
    }

    private function preReleasePambMovAction(Model $record): ?string
    {
        $owner = $this->preReleasePambMovOwner($record);

        return match ($owner) {
            OrganizationalAccessService::CENRO_FOCAL => 'Submit MOV/report for CENRO CDS Chief review',
            OrganizationalAccessService::CENRO_CHIEF => 'Review MOV/report',
            OrganizationalAccessService::CENRO_RECORDS => 'Release report to PENRO',
            default => null,
        };
    }

    /** @param Collection<int,\App\Models\DocumentRoutingEvent> $routingEvents */
    private function presentCanonical(Model $record, string $sourceKey, Collection $routingEvents): array
    {
        $state = app(DocumentRoutingTransitionService::class)->presentation($record, $sourceKey, $routingEvents, auth()->user());
        $profile = $state['profile'];
        $actions = collect($state['actions']);
        $currentStage = (string) $state['stage'];
        $start = $state['stage'] === DocumentRoutingProfileRegistry::PAMO_ORIGIN
            ? DocumentRoutingProfileRegistry::PAMO_ORIGIN
            : ($profile['key'] === 'canonical_direct_penro' ? DocumentRoutingProfileRegistry::PENRO_ORIGIN : DocumentRoutingProfileRegistry::PREPARATION);
        $pathStart = $state['bootstrapped'] ? $currentStage : $start;
        $path = [$pathStart];
        $cursor = $pathStart;
        $visited = [$cursor];
        while ($action = $actions->first(fn (array $candidate): bool => $candidate['from'] === $cursor && ! ($candidate['correction'] ?? false))) {
            if (in_array($action['to'], $visited, true)) break;
            $path[] = $action['to'];
            $cursor = $action['to'];
            $visited[] = $cursor;
            if (count($path) > 30) break;
        }
        $eventByStage = $state['events']->keyBy('to_stage');
        $currentStage = (string) $state['stage'];
        $timeline = [];
        foreach ($path as $stage) {
            $event = $eventByStage->get($stage);
            $action = $actions->firstWhere('to', $stage);
            $isCurrent = $stage === $currentStage;
            $timeline[] = [
                'key' => $stage,
                'label' => $this->stageLabel($stage, $action, $start),
                'event_type' => $event?->event_key ?? (data_get($action, 'event_key') ?? 'stage'),
                'action_label' => data_get($action, 'action_label'),
                'from' => $event?->from_office ?? data_get($action, 'from_office'),
                'to' => $event?->to_office ?? data_get($action, 'to_office'),
                'office' => $event?->to_office ?? data_get($action, 'to_office'),
                'occurred_at' => $event?->occurred_at?->toIso8601String(),
                'recorded_at' => $event?->created_at?->toIso8601String(),
                'status' => $isCurrent ? 'current' : ($event || ($stage === $start && $currentStage !== $start) ? 'completed' : 'pending'),
                'pending_since' => $isCurrent ? $this->pendingSince($state, $record, $event) : null,
                'working_days_pending' => $isCurrent ? $this->pendingDays($state, $record, $event, $sourceKey) : null,
                'recorded_by' => $event?->recordedBy?->name,
                'actor_category' => $event?->recordedBy?->section,
                'remarks' => $event?->remarks,
            ];
        }

        if ($state['bootstrapped']) {
            $timeline = array_merge($this->legacyTimeline($record), $timeline);
        }
        $current = collect($timeline)->firstWhere('status', 'current');
        $last = collect($timeline)->filter(fn (array $item): bool => filled($item['occurred_at']))->last();
        $nextAction = $state['allowed_actions'][0] ?? null;
        $informationalAction = $actions->first(fn (array $action): bool => $action['from'] === $currentStage && ! ($action['internal_only'] ?? false));
        $allowed = collect($state['allowed_actions'])->map(fn (array $action): array => [
            'key' => $action['key'], 'label' => $action['label'], 'action_label' => $action['action_label'], 'to' => $action['to'], 'to_office' => $action['to_office'], 'correction' => (bool) ($action['correction'] ?? false), 'correction_reference_allowed' => (bool) ($action['correction_reference_allowed'] ?? ($sourceKey !== 'engp' && ($action['correction'] ?? false))), 'remarks_required' => (bool) (($action['correction'] ?? false) && ! isset($action['receipt_correction_context'])), 'receipt_correction_context' => $action['receipt_correction_context'] ?? null,
            'attachment_allowed' => (bool) ($action['attachment_allowed'] ?? (
                ! ($action['correction'] ?? false)
                && $action['key'] !== 'receive_at_penro_records_final'
            )),
        ])->values()->all();

        $attachments = $this->routingAttachments->forDocumentEvents($state['events']->pluck('id'));
        $history = $state['events']->map(function (\App\Models\DocumentRoutingEvent $event) use ($actions, $attachments): array {
            $action = $actions->first(fn (array $candidate): bool => $candidate['from'] === $event->from_stage && $candidate['to'] === $event->to_stage && $candidate['event_key'] === $event->event_key);
            $correction = $event->event_key === 'returned_for_correction';
            return [
                'id' => $event->id, 'key' => $event->event_key.':'.$event->id,
                'label' => $correction ? 'Returned for Correction' : (data_get($action, 'label') ?? ucfirst(str_replace('_', ' ', $event->event_key))),
                'event_type' => $event->event_key, 'from' => $event->from_office, 'to' => $event->to_office,
                'occurred_at' => $event->occurred_at?->toIso8601String(), 'recorded_at' => $event->created_at?->toIso8601String(),
                'recorded_by' => $event->recordedBy?->name, 'actor_category' => $event->recordedBy ? $this->organization->effectiveCategory($event->recordedBy) : null, 'actor_office' => $event->recordedBy ? $this->organization->normalizeOffice($event->recordedBy->office_designated) : null,
                'remarks' => $event->remarks, 'correction_reason_key' => data_get($event->metadata, 'correction_reason_key'), 'correction_reason' => data_get($event->metadata, 'correction_reason'), 'correction_detail' => data_get($event->metadata, 'correction_detail'), 'attachment' => isset($attachments[$event->id]) ? $this->routingAttachments->descriptor($attachments[$event->id]) : null, 'correction' => $correction, 'administrative_override' => (bool) data_get($event->metadata, 'administrative_override', false), 'override_for_category' => data_get($event->metadata, 'override_for_category'), 'override_for_office' => data_get($event->metadata, 'override_for_office'),
            ];
        })->values()->all();

        return [
            'profile_key' => $profile['key'], 'profile_label' => $profile['label'], 'route_granularity' => $profile['route_granularity'],
            'business_route_confirmation' => $profile['business_route_confirmation'], 'detailed_route_requires_confirmation' => $profile['detailed_route_requires_confirmation'],
            'originating_office' => $profile['originating_office'], 'final_destination' => $profile['final_destination'],
            'current_location' => $this->stageLocation($current, $record, $state), 'current_status' => $this->stageStatus($current, $record, $state),
            'processing_percentage' => $this->processingPercentage($sourceKey, $currentStage),
            'responsible_office' => $this->organizationalOffice($record, data_get($current, 'key'), data_get($current, 'office') ?: $record->getAttribute('target_office')),
            'responsible_user_category' => $this->organization->categoryLabel(data_get($informationalAction, 'categories.0')) ?: data_get($current, 'actor_category'),
            'current_stage' => data_get($current, 'key'),
            'in_transit_to' => $current && str_starts_with((string) data_get($current, 'key'), 'transit_') ? data_get($current, 'to') : null,
            'pending_since' => data_get($current, 'pending_since'), 'working_days_pending' => data_get($current, 'working_days_pending'),
            'last_action' => $last ? ['label' => $last['label'], 'occurred_at' => $last['occurred_at'], 'recorded_by' => $last['recorded_by'], 'remarks' => $last['remarks']] : null,
            'last_updated' => $last['recorded_at'] ?? $last['occurred_at'] ?? null, 'recorded_by' => $last['recorded_by'] ?? null,
            'next_expected_action' => data_get($informationalAction, 'action_label') ?? ($current ? 'No further routing action' : null),
            'deadline' => $record->getAttribute('deadline_submission'), 'compliance_status' => $record->getAttribute('timeliness'),
            'correction' => (bool) ($state['correction'] ?? false),
            'correction_reason_key' => data_get($state, 'correction_event.metadata.correction_reason_key'),
            'correction_reason' => data_get($state, 'correction_event.metadata.correction_reason') ?? data_get($state, 'correction_event.remarks'),
            'correction_detail' => data_get($state, 'correction_event.metadata.correction_detail') ?? data_get($state, 'correction_event.remarks'),
            'actions' => $allowed, 'capabilities' => $state['capabilities'], 'timeline' => $timeline, 'routing_history' => $history,
        ];
    }

    private function processingPercentage(string $sourceKey, string $stage): int
    {
        $engp = [
            DocumentRoutingProfileRegistry::PREPARATION => 0,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF => 20,
            DocumentRoutingProfileRegistry::CENRO_CHIEF => 35,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_RECORDS => 50,
            DocumentRoutingProfileRegistry::CENRO_RECORDS => 65,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS => 80,
            DocumentRoutingProfileRegistry::PENRO_RECORDS => 100,
            DocumentRoutingProfileRegistry::RELEASED_REGIONAL => 100,
        ];
        if ($sourceKey === 'engp') return $engp[$stage] ?? 100;

        return [
            DocumentRoutingProfileRegistry::PREPARATION => 0,
            DocumentRoutingProfileRegistry::PENRO_ORIGIN => 0,
            DocumentRoutingProfileRegistry::PAMO_ORIGIN => 0,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF => 20,
            DocumentRoutingProfileRegistry::CENRO_CHIEF => 35,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_RECORDS => 50,
            DocumentRoutingProfileRegistry::CENRO_RECORDS => 65,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS => 80,
            DocumentRoutingProfileRegistry::PENRO_RECORDS => 90,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO => 90,
            DocumentRoutingProfileRegistry::OFFICE_PENRO => 92,
            DocumentRoutingProfileRegistry::TRANSIT_TSD => 94,
            DocumentRoutingProfileRegistry::TSD => 95,
            DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL => 97,
            DocumentRoutingProfileRegistry::CDS_FOCAL => 98,
            DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF => 99,
            DocumentRoutingProfileRegistry::CDS_CHIEF => 100,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN => 100,
            DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN => 100,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL => 100,
            DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL => 100,
            DocumentRoutingProfileRegistry::RELEASED_REGIONAL => 100,
        ][$stage] ?? 100;
    }

    /** @param array<string,mixed>|null $action */
    private function stageLabel(string $stage, ?array $action, string $start): string
    {
        if ($stage === DocumentRoutingProfileRegistry::PREPARATION) return 'CENRO CDS Focal Person';
        if ($stage === DocumentRoutingProfileRegistry::PENRO_ORIGIN) return 'PENRO origin';
        if ($stage === DocumentRoutingProfileRegistry::PAMO_ORIGIN) return 'PAMO origin';
        if ($stage === DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return 'Released / Endorsed to Regional Office';
        if (str_starts_with($stage, 'transit_')) return 'In transit to '.(data_get($action, 'to_office') ?? 'next office');
        return data_get($action, 'to_office') ?? match ($stage) {
            DocumentRoutingProfileRegistry::CENRO_CHIEF => 'CENRO CDS Chief',
            DocumentRoutingProfileRegistry::CENRO_RECORDS => 'CENRO Records Unit',
            DocumentRoutingProfileRegistry::PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL => 'PENRO Records Unit',
            DocumentRoutingProfileRegistry::OFFICE_PENRO, DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN => 'Office of the PENRO',
            DocumentRoutingProfileRegistry::TSD => 'PENRO TSD Chief',
            DocumentRoutingProfileRegistry::CDS_FOCAL => 'PENRO CDS Focal Person',
            DocumentRoutingProfileRegistry::CDS_CHIEF => 'PENRO CDS Chief',
            default => $start,
        };
    }

    private function pendingSince(array $state, Model $record, mixed $event): ?string
    {
        if ($event?->occurred_at) return $event->occurred_at->toDateString();
        if ($state['bootstrapped'] ?? false) {
            return match ($state['stage']) {
                DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS => $this->date($record->getAttribute('date_report_released_cenro'))?->toDateString(),
                DocumentRoutingProfileRegistry::PENRO_RECORDS => $this->date($record->getAttribute('date_received_penro'))?->toDateString(),
                default => null,
            };
        }
        return $this->date($record->getAttribute('date_accomplished') ?: $record->getAttribute('date_conducted'))?->toDateString();
    }

    private function pendingDays(array $state, Model $record, mixed $event, string $sourceKey): ?int
    {
        // A valid PENRO receipt is ENGP's terminal routing point. Keep its
        // timeline/history, but do not report further actor processing time.
        if ($sourceKey === 'engp' && filled($record->getAttribute('date_received_penro'))) {
            return null;
        }

        $since = $this->pendingSince($state, $record, $event);
        if (! $since) return null;
        $office = $event?->to_office ?? $record->getAttribute('target_office');
        $today = CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString();

        return $sourceKey === 'engp'
            ? $this->calendar->conservationWorkingDaysBetween($since, $today, 'after_through', $office)
            : $this->calendar->workingDaysBetween($since, $today, 'after_through', $office);
    }

    /** @return list<array<string,mixed>> */
    private function legacyTimeline(Model $record): array
    {
        $items = [];
        $milestones = [
            ['field' => 'date_report_released_cenro', 'key' => 'legacy:cenro_release', 'label' => 'Imported Existing Milestone: CENRO release', 'from' => 'CENRO Records Unit', 'to' => 'PENRO Records Unit', 'event_type' => 'imported_existing_milestone'],
            ['field' => 'date_received_penro', 'key' => 'legacy:penro_receipt', 'label' => 'Imported Existing Milestone: PENRO receipt', 'from' => 'PENRO Records Unit', 'to' => 'PENRO Records Unit', 'event_type' => 'imported_existing_milestone'],
            ['field' => 'date_endorsed_regional', 'key' => 'legacy:regional_endorsement', 'label' => 'Imported Existing Milestone: Regional endorsement', 'from' => 'PENRO Records Unit', 'to' => 'Regional Office', 'event_type' => 'imported_existing_milestone'],
        ];
        foreach ($milestones as $milestone) {
            $date = $this->date($record->getAttribute($milestone['field']));
            if (! $date) continue;
            $items[] = [
                'key' => $milestone['key'], 'label' => $milestone['label'], 'event_type' => $milestone['event_type'],
                'action_label' => null, 'from' => $milestone['from'], 'to' => $milestone['to'], 'office' => $milestone['to'],
                'occurred_at' => $date->toDateString(), 'recorded_at' => null, 'status' => 'completed',
                'pending_since' => null, 'working_days_pending' => null, 'recorded_by' => null, 'actor_category' => null, 'remarks' => null,
            ];
        }
        return $items;
    }

    private function stageLocation(?array $current, Model $record, array $state = []): string
    {
        if (($state['correction'] ?? false) && in_array($state['stage'] ?? null, [DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::CDS_FOCAL], true)) {
            return ($state['stage'] ?? null) === DocumentRoutingProfileRegistry::PREPARATION ? 'CENRO CDS' : 'PENRO CDS';
        }
        if (! $current) return $record->getAttribute('target_office') ?: 'CENRO';
        if ($current['key'] === DocumentRoutingProfileRegistry::PREPARATION) return $record->getAttribute('target_office') ?: 'CENRO';
        if (str_starts_with((string) $current['key'], 'transit_')) return 'In Transit';
        if ($current['key'] === DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return 'Regional Office';
        return $current['office'] ?? $current['to'] ?? 'Not available';
    }

    private function stageStatus(?array $current, Model $record, array $state = []): string
    {
        if ($state['correction'] ?? false) return 'Needs Correction';
        if (! $current) return $this->date($record->getAttribute('date_accomplished') ?: $record->getAttribute('date_conducted')) ? 'Awaiting Forward to CENRO CDS Chief' : 'No Activity Conducted';
        if ($current['key'] === DocumentRoutingProfileRegistry::PREPARATION) return 'Awaiting Forward to CENRO CDS Chief';
        if (str_starts_with((string) $current['key'], 'transit_')) return 'Awaiting Receipt by '.($current['to'] ?? 'next actor');
        if ($current['key'] === DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return 'Completed';
        return 'At '.($current['office'] ?? $current['to'] ?? 'current office');
    }

    /** @param Collection<int,AuditLog> $logs */
    private function auditFor(Collection $logs, string $stage, CarbonImmutable $date): ?AuditLog
    {
        return $logs->first(fn (AuditLog $log): bool => ($log->metadata['stage'] ?? null) === $stage && ($log->metadata['date'] ?? null) === $date->toDateString());
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return ($date = DatePresentationNormalizer::toDateString($value)) ? CarbonImmutable::createFromFormat('!Y-m-d', $date, BusinessCalendarService::TIMEZONE) : null;
    }

    private function pambEventType(array $event): string
    {
        return str_starts_with((string) ($event['key'] ?? ''), 'received_') ? 'received' : (str_starts_with((string) ($event['key'] ?? ''), 'forwarded_') ? 'forwarded' : 'released');
    }

    private function pambTransitDestination(array $timeline): ?string
    {
        $current = collect($timeline)->firstWhere('status', 'current');
        return $current && $this->pambEventType($current) === 'forwarded' ? ($current['destination'] ?? null) : null;
    }
    private function organizationalOffice(Model $record, ?string $stage, ?string $fallback = null): ?string
    {
        $cenroStages = [
            DocumentRoutingProfileRegistry::PREPARATION,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF,
            DocumentRoutingProfileRegistry::CENRO_CHIEF,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_RECORDS,
            DocumentRoutingProfileRegistry::CENRO_RECORDS,
        ];

        if (in_array($stage, $cenroStages, true)) {
            return $this->organization->supervisingOfficeNameForProtectedArea((int) $record->getAttribute('protected_area_id')) ?: $fallback;
        }
        if ($stage === DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return 'Regional Office';
        if ($stage === DocumentRoutingProfileRegistry::PAMO_ORIGIN) {
            return $this->organization->supervisingOfficeNameForProtectedArea((int) $record->getAttribute('protected_area_id')) ?: $fallback;
        }
        if ($stage !== null) {
            $targetOffice = (string) $record->getAttribute('target_office');
            return str_starts_with($targetOffice, 'PENRO') ? $targetOffice : 'PENRO Davao Oriental';
        }
        return $fallback;
    }

    private function officeForActor(Model $record, ?string $actor, ?string $fallback = null): ?string
    {
        if ($actor === null) return $fallback;
        if (str_contains($actor, 'CENRO') || $actor === 'PAMO' || $actor === 'CENRO') {
            return $this->organization->supervisingOfficeNameForProtectedArea((int) $record->getAttribute('protected_area_id')) ?: $fallback;
        }
        if (str_contains($actor, 'PENRO') || $actor === 'Office of the PENRO') return 'PENRO Davao Oriental';
        if ($actor === 'Regional Office') return 'Regional Office';
        return $fallback;
    }
}
