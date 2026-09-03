<?php

namespace App\Services\SubmissionTracking;

use App\Models\AuditLog;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Services\BusinessCalendarService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Authorization\OrganizationalAccessService;
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
        private readonly EngpReportWorkflowRegistry $engpWorkflows,
    ) {}

    /** @param Collection<int,AuditLog>|null $auditLogs @param Collection<int,\App\Models\DocumentRoutingEvent>|null $routingEvents */
    public function present(Model $record, string $sourceKey, ?Collection $auditLogs = null, ?Collection $routingEvents = null): array
    {
        if ($sourceKey === 'engp' || $record instanceof EngpReportSubmission) {
            return $this->presentEngp($record, $auditLogs ?? collect());
        }

        return $this->presentCanonical($record, $sourceKey, $routingEvents ?? collect());
    }

    /** @param array<string,mixed> $pamb */
    public function presentPamb(Model $record, array $pamb): array
    {
        $summary = $pamb['routing_summary'] ?? [];
        $timeline = collect($pamb['timeline'] ?? [])->values()->all();
        $last = $summary['last_action'] ?? null;

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
            'responsible_office' => $summary['responsible_office'] ?? null,
            'responsible_user_category' => null,
            'in_transit_to' => $this->pambTransitDestination($timeline),
            'pending_since' => $summary['pending_since'] ?? null,
            'working_days_pending' => $summary['working_days_pending'] ?? null,
            'last_action' => $last,
            'last_updated' => $summary['last_updated'] ?? null,
            'recorded_by' => $last['recorded_by'] ?? null,
            'next_expected_action' => $summary['next_expected_action'] ?? null,
            'deadline' => $record->getAttribute('deadline_submission'),
            'compliance_status' => $record->getAttribute('timeliness'),
            'timeline' => array_map(fn (array $event): array => [
                ...$event,
                'from' => null,
                'to' => $event['destination'] ?? null,
                'event_type' => $this->pambEventType($event),
                'recorded_at' => $event['occurred_at'] ?? null,
                'actor_category' => null,
                'office' => $event['held_at'] ?? null,
            ], $timeline),
        ];
    }

    /** @param Collection<int,\App\Models\DocumentRoutingEvent> $routingEvents */
    private function presentCanonical(Model $record, string $sourceKey, Collection $routingEvents): array
    {
        $state = app(DocumentRoutingTransitionService::class)->presentation($record, $sourceKey, $routingEvents, auth()->user());
        $profile = $state['profile'];
        $actions = collect($state['actions']);
        $start = $state['stage'] === DocumentRoutingProfileRegistry::PAMO_ORIGIN
            ? DocumentRoutingProfileRegistry::PAMO_ORIGIN
            : ($profile['key'] === 'canonical_direct_penro' ? DocumentRoutingProfileRegistry::PENRO_ORIGIN : DocumentRoutingProfileRegistry::PREPARATION);
        $pathStart = $state['bootstrapped'] ? $currentStage : $start;
        $path = [$pathStart];
        $cursor = $pathStart;
        while ($action = $actions->firstWhere('from', $cursor)) {
            $path[] = $action['to'];
            $cursor = $action['to'];
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
                'working_days_pending' => $isCurrent ? $this->pendingDays($state, $record, $event) : null,
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
        $allowed = collect($state['allowed_actions'])->map(fn (array $action): array => [
            'key' => $action['key'], 'label' => $action['label'], 'action_label' => $action['action_label'], 'to' => $action['to'], 'to_office' => $action['to_office'],
        ])->values()->all();

        return [
            'profile_key' => $profile['key'], 'profile_label' => $profile['label'], 'route_granularity' => $profile['route_granularity'],
            'business_route_confirmation' => $profile['business_route_confirmation'], 'detailed_route_requires_confirmation' => $profile['detailed_route_requires_confirmation'],
            'originating_office' => $profile['originating_office'], 'final_destination' => $profile['final_destination'],
            'current_location' => $this->stageLocation($current, $record), 'current_status' => $this->stageStatus($current, $record),
            'responsible_office' => data_get($current, 'office') ?: $record->getAttribute('target_office'),
            'responsible_user_category' => app(OrganizationalAccessService::class)->categoryLabel(data_get($nextAction, 'categories.0')) ?: data_get($current, 'actor_category'),
            'in_transit_to' => $current && str_starts_with((string) data_get($current, 'key'), 'transit_') ? data_get($current, 'to') : null,
            'pending_since' => data_get($current, 'pending_since'), 'working_days_pending' => data_get($current, 'working_days_pending'),
            'last_action' => $last ? ['label' => $last['label'], 'occurred_at' => $last['occurred_at'], 'recorded_by' => $last['recorded_by'], 'remarks' => $last['remarks']] : null,
            'last_updated' => $last['recorded_at'] ?? $last['occurred_at'] ?? null, 'recorded_by' => $last['recorded_by'] ?? null,
            'next_expected_action' => $allowed[0]['action_label'] ?? ($current ? 'No further routing action' : null),
            'deadline' => $record->getAttribute('deadline_submission'), 'compliance_status' => $record->getAttribute('timeliness'),
            'actions' => $allowed, 'capabilities' => $state['capabilities'], 'timeline' => $timeline,
        ];
    }

    /** @param Collection<int,AuditLog> $auditLogs */
    private function presentEngp(Model $record, Collection $auditLogs): array
    {
        $components = $this->engpWorkflows->releaseComponents((string) $record->workflow_key, (int) $record->reporting_year, (string) $record->period_key);
        $events = $record->relationLoaded('releaseEvents') ? $record->releaseEvents : $record->releaseEvents()->get();
        $timeline = [];
        $releasePending = true;
        foreach ($components as $component) {
            $event = $events->firstWhere('period_component', $component['key']);
            $date = $event?->date_report_released_cenro;
            $audit = $date ? $this->auditFor($auditLogs, SubmissionTrackingService::CENRO_RELEASE, $this->date($date)) : null;
            $status = $date ? 'completed' : ($releasePending ? 'current' : 'pending');
            $releasePending = $releasePending && ! $date;
            $timeline[] = [
                'key' => 'cenro_release:'.$component['key'], 'label' => 'Released by CENRO — '.$component['label'],
                'event_type' => 'forwarded', 'from' => 'CENRO', 'to' => 'PENRO', 'office' => 'CENRO',
                'occurred_at' => $this->date($date)?->toDateString(), 'recorded_at' => $audit?->created_at?->toIso8601String(),
                'status' => $status, 'pending_since' => null, 'working_days_pending' => null,
                'recorded_by' => $audit?->user?->name, 'actor_category' => $audit?->user?->section, 'remarks' => null,
            ];
        }
        $received = $this->date($record->date_received_penro);
        $lastRelease = $events->pluck('date_report_released_cenro')->filter()->map(fn ($value) => $this->date($value))->sort()->last();
        $receiptAudit = $received ? $this->auditFor($auditLogs, SubmissionTrackingService::PENRO_RECEIPT, $received) : null;
        $timeline[] = [
            'key' => SubmissionTrackingService::PENRO_RECEIPT, 'label' => 'Received by PENRO', 'event_type' => 'received',
            'from' => 'CENRO', 'to' => 'PENRO', 'office' => 'PENRO', 'occurred_at' => $received?->toDateString(),
            'recorded_at' => $receiptAudit?->created_at?->toIso8601String(), 'status' => $received ? 'completed' : 'current',
            'pending_since' => ! $received ? $lastRelease?->toDateString() : null,
            'working_days_pending' => ! $received && $lastRelease ? $this->calendar->workingDaysBetween($lastRelease, CarbonImmutable::now(BusinessCalendarService::TIMEZONE), 'after_through', $record->office) : null,
            'recorded_by' => $receiptAudit?->user?->name, 'actor_category' => $receiptAudit?->user?->section, 'remarks' => null,
        ];
        $current = collect($timeline)->firstWhere('status', 'current');
        $last = collect($timeline)->filter(fn (array $item): bool => filled($item['occurred_at']))->last();
        return [
            'profile_key' => 'engp_release_components', 'profile_label' => 'ENGP release-component routing', 'route_granularity' => 'release_components',
            'business_route_confirmation' => false, 'detailed_route_requires_confirmation' => false, 'originating_office' => 'CENRO', 'final_destination' => 'PENRO',
            'current_location' => $received ? 'PENRO' : ($current ? 'In transit to PENRO' : 'CENRO'),
            'current_status' => $received ? 'Received by PENRO' : ($current ? 'Awaiting PENRO Receipt' : 'Pending CENRO Release'),
            'responsible_office' => $received ? 'PENRO' : 'CENRO', 'responsible_user_category' => $current['actor_category'] ?? null,
            'in_transit_to' => $received ? null : 'PENRO', 'pending_since' => $current['pending_since'] ?? null,
            'working_days_pending' => $current['working_days_pending'] ?? null,
            'last_action' => $last ? ['label' => $last['label'], 'occurred_at' => $last['occurred_at'], 'recorded_by' => $last['recorded_by'], 'remarks' => $last['remarks']] : null,
            'last_updated' => $last['recorded_at'] ?? $last['occurred_at'] ?? null, 'recorded_by' => $last['recorded_by'] ?? null,
            'next_expected_action' => $received ? 'No further routing action' : 'Record PENRO Receipt',
            'deadline' => $record->getAttribute('deadline_submission'), 'compliance_status' => $record->getAttribute('timeliness_rating'), 'timeline' => $timeline,
        ];
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
            DocumentRoutingProfileRegistry::TSD => 'TSD',
            DocumentRoutingProfileRegistry::CDS => 'CDS',
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

    private function pendingDays(array $state, Model $record, mixed $event): ?int
    {
        $since = $this->pendingSince($state, $record, $event);
        if (! $since) return null;
        $office = $event?->to_office ?? $record->getAttribute('target_office');
        return $this->calendar->workingDaysBetween($since, CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(), 'after_through', $office);
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
                'occurred_at' => $date->toIso8601String(), 'recorded_at' => null, 'status' => 'completed',
                'pending_since' => null, 'working_days_pending' => null, 'recorded_by' => null, 'actor_category' => null, 'remarks' => null,
            ];
        }
        return $items;
    }

    private function stageLocation(?array $current, Model $record): string
    {
        if (! $current) return $record->getAttribute('target_office') ?: 'CENRO';
        if ($current['key'] === DocumentRoutingProfileRegistry::PREPARATION) return $record->getAttribute('target_office') ?: 'CENRO';
        if (str_starts_with((string) $current['key'], 'transit_')) return 'In transit to '.($current['to'] ?? 'next office');
        if ($current['key'] === DocumentRoutingProfileRegistry::RELEASED_REGIONAL) return 'Regional Office';
        return $current['office'] ?? $current['to'] ?? 'Not available';
    }

    private function stageStatus(?array $current, Model $record): string
    {
        if (! $current) return $this->date($record->getAttribute('date_accomplished') ?: $record->getAttribute('date_conducted')) ? 'Awaiting Forward to CENRO CDS Chief' : 'No Activity Conducted';
        if ($current['key'] === DocumentRoutingProfileRegistry::PREPARATION) return 'Awaiting Forward to CENRO CDS Chief';
        if (str_starts_with((string) $current['key'], 'transit_')) return 'In Transit to '.($current['to'] ?? 'next office');
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
        return blank($value) ? null : CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->startOfDay();
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
}
