<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\User;
use App\Support\LocalNavigationTrace;
use App\Services\Archive\ArchiveCheckpointPolicy;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Notifications\EdatsInAppNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Profile-driven, append-only custody transitions. */
final class DocumentRoutingTransitionService
{
    public function __construct(
        private readonly DocumentRoutingProfileRegistry $profiles,
        private readonly DocumentRoutingAccessService $access,
        private readonly ArchiveCheckpointPolicy $checkpointPolicy,
        private readonly RoutingPositionSnapshotService $positionSnapshots,
        private readonly EffectiveRoutingGraphResolver $graphResolver,
    ) {}

    /** @param Collection<int,DocumentRoutingEvent>|null $events */
    public function state(EloquentModel $record, string $sourceKey, ?Collection $events = null, ?User $actor = null, ?array $resolvedPosition = null): array
    {
        LocalNavigationTrace::incrementCurrent('canonical_state_builds');
        $events ??= $this->events($record, $sourceKey);
        $hasHistory = $events->isNotEmpty();
        $projectionIdentity = $this->projectionIdentity($record, $sourceKey, $events);
        if ($record instanceof \App\Models\ConservationReportSubmission && $sourceKey === 'conservation') {
            $events = app(ConservationMeetingRoutingCompatibilityAdapter::class)->events($record, $events);
        }
        if (method_exists($record, 'protectedArea') && ! $record->relationLoaded('protectedArea')) {
            $record->load('protectedArea');
        }
        $position = $resolvedPosition ?? $this->positionSnapshots->resolve($record, $sourceKey, $hasHistory);
        $direct = in_array($position['profile'] ?? null, ['regular', 'direct'], true)
            ? $position['profile'] === 'direct'
            : ($sourceKey !== 'engp' && app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($record));
        $graph = $this->graphResolver->resolve($sourceKey, $direct, $position);
        $profile = ['profile' => $graph['profile'], 'actions' => $graph['actions']];
            // Compatibility events already carry deterministic source-local order.
        $last = $events->last();
        $activeCycle = $record instanceof \App\Models\ConservationReportSubmission
            && $sourceKey === 'conservation'
            && app(ConservationMeetingRoutingCompatibilityAdapter::class)->applies($record)
                ? app(ConservationMeetingRoutingCompatibilityAdapter::class)->activeCycle($record, $events)
                : null;
        $bootstrapped = false;
        $stage = $last?->to_stage;

        if (! $stage) {
            [$stage, $bootstrapped] = $this->legacyStage($record, $direct, $actor, $sourceKey);
        }

        $lastCorrection = $last?->event_key === 'returned_for_correction';
        $correctionReceived = $last?->event_key === 'correction_received';
        $lastCorrectionEvent = $events->reverse()->first(fn (DocumentRoutingEvent $event): bool => $event->event_key === 'returned_for_correction');
        // Internal transition identities remain in the state graph for the
        // historical timeline and atomic service operation. Presentation
        // removes them from executable actions below.
        $actions = $profile['actions'];
        if ($lastCorrection) {
            // A correction return is a handoff to the previous accountable
            // sender.  The recipient must acknowledge that assignment before
            // the normal forward action becomes available again.
            $actions = [[
                'key' => 'receive_correction',
                'from' => $last->to_stage,
                'to' => $last->to_stage,
                'event_key' => 'correction_received',
                'from_office' => $last->to_office,
                'to_office' => $last->to_office,
                'categories' => [$this->categoryForStage((string) $last->to_stage)],
                'label' => 'Correction received',
                'action_label' => 'Receive Correction',
                'correction' => false,
                'correction_cycle' => true,
                'attachment_allowed' => false,
                'correction_reference_allowed' => false,
            ]];
        } elseif ($correctionReceived) {
            $actions = array_map(function (array $action): array {
                if ($action['key'] === 'forward_to_penro_records') {
                    return [...$action, 'action_label' => 'Resubmit Corrected Copy', 'label' => 'Corrected copy resubmitted to PENRO Records', 'correction_cycle' => true, 'document_operation' => 'correction_resubmission'];
                }
                return $action;
            }, $actions);
        }
        if ($direct
            && $stage === DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS
            && ! $this->supportedIncomingSender($graph['actions'], $events, (string) $stage)) {
            $actions = array_values(array_filter($actions, fn (array $action): bool => ($action['key'] ?? null) !== 'return_for_correction_penro_records'));
        }

        return [
            'stage' => $stage,
            'active_cycle' => $activeCycle,
            'bootstrapped' => $bootstrapped,
            // The adapter orders legacy PAMB events within their own table and
            // merges them with shared events by timestamp plus an explicit tie
            // policy. Re-sorting projected negative IDs here would reverse
            // same-time PAMB rows and make unrelated table IDs look comparable.
            'events' => $events->values(),
            '_projection_identity' => $projectionIdentity,
            'profile' => $profile['profile'],
            'route_profile' => $direct ? 'direct' : 'regular',
            'actions' => $actions,
            'route_actions' => $graph['actions'],
            'route_position' => $graph['route_position'],
            // correction_cycle is historical metadata carried forward with the
            // routing chain. Only a latest unresolved return is current state;
            // correction receipt and later routing resolve it.
            'correction' => $lastCorrection,
            'correction_event' => ($lastCorrection || $correctionReceived) ? $lastCorrectionEvent : null,
        ];
    }

    /** @param Collection<int,array{record:EloquentModel,key:string}> $loaded
     *  @param array<string,Collection<int,DocumentRoutingEvent>> $eventsByRecord */
    public function primeRoutingPositions(Collection $loaded, array $eventsByRecord): void
    {
        $this->positionSnapshots->prime($loaded->map(function (array $item) use ($eventsByRecord): array {
            $record = $item['record'];
            $source = (string) $item['key'];
            return [
                'record' => $record,
                'source' => $source,
                'has_history' => ($eventsByRecord[$source.':'.$record->getKey()] ?? collect())->isNotEmpty(),
            ];
        }));
    }

    public function clearPrimedRoutingPositions(): void
    {
        $this->positionSnapshots->clearPrimed();
    }

    private function categoryForStage(string $stage): string
    {
        if ($stage === DocumentRoutingProfileRegistry::CENRO_RECORDS) return OrganizationalAccessService::CENRO_RECORDS;
        if ($stage === DocumentRoutingProfileRegistry::PENRO_RECORDS) return OrganizationalAccessService::PENRO_RECORDS;
        if ($stage === DocumentRoutingProfileRegistry::PREPARATION) return OrganizationalAccessService::CENRO_FOCAL;
        if ($stage === DocumentRoutingProfileRegistry::PAMO_ORIGIN) return OrganizationalAccessService::PAMO;
        if ($stage === DocumentRoutingProfileRegistry::PENRO_ORIGIN) return OrganizationalAccessService::PENRO_FOCAL;
        if ($stage === DocumentRoutingProfileRegistry::CDS_FOCAL) return OrganizationalAccessService::PENRO_FOCAL;
        $action = collect($this->profiles->actionProfile('conservation')['actions'])->firstWhere('to', $stage);
        return (string) ($action['categories'][0] ?? OrganizationalAccessService::CENRO_RECORDS);
    }

    /** Return the latest sender only when that handoff belongs to this route graph. */
    private function supportedIncomingSender(array $routeActions, Collection $events, string $stage): ?DocumentRoutingEvent
    {
        $sender = $events->reverse()->first(fn (DocumentRoutingEvent $event): bool => $event->to_stage === $stage);
        if (! $sender || blank($sender->from_stage)) return null;

        return collect($routeActions)->contains(fn (array $action): bool =>
            ($action['from'] ?? null) === $sender->from_stage
            && ($action['to'] ?? null) === $sender->to_stage)
                ? $sender
                : null;
    }

    /** @return Collection<int,DocumentRoutingEvent> */
    public function events(EloquentModel $record, string $sourceKey): Collection
    {
        return $this->loadEvents($record, $sourceKey, false);
    }

    /** Read authoritative history after the source row lock for a transition. */
    private function eventsForTransition(EloquentModel $record, string $sourceKey): Collection
    {
        return $this->loadEvents($record, $sourceKey, true);
    }

    /** @return Collection<int,DocumentRoutingEvent> */
    private function loadEvents(EloquentModel $record, string $sourceKey, bool $lockForUpdate): Collection
    {
        $query = DocumentRoutingEvent::query()
            ->where('source_type', $sourceKey)
            ->where('source_id', $record->getKey())
            ->with('recordedBy:id,name,section,office_designated')
            ->orderBy('occurred_at')
            ->orderBy('id');
        if ($lockForUpdate) $query->lockForUpdate();
        $events = $query->get();

        return $record instanceof \App\Models\ConservationReportSubmission && $sourceKey === 'conservation'
            ? app(ConservationMeetingRoutingCompatibilityAdapter::class)->events($record, $events)
            : $events;
    }

    /** @return list<string> */
    public function actionKeys(EloquentModel $record, string $sourceKey, ?User $actor = null): array
    {
        return collect($this->state($record, $sourceKey, null, $actor)['actions'])
            ->reject(fn (array $action): bool => (bool) ($action['internal_only'] ?? false))
            ->pluck('key')->values()->all();
    }

    /**
     * Resolve correction classification from the active routing profile rather
     * than relying on action-key naming conventions.
     */
    public function isCorrectionAction(EloquentModel $record, string $sourceKey, string $actionKey, ?Collection $events = null): bool
    {
        $action = collect($this->state($record, $sourceKey, $events)['actions'])->firstWhere('key', $actionKey);

        return is_array($action) && (bool) ($action['correction'] ?? false);
    }

    /** Return the profile-derived document operation for an action available at the current stage. */
    public function documentOperation(EloquentModel $record, string $sourceKey, string $actionKey): ?string
    {
        $action = collect($this->state($record, $sourceKey)['actions'])->firstWhere('key', $actionKey);
        $operation = is_array($action) ? ($action['document_operation'] ?? null) : null;

        return in_array($operation, ['forward', 'correction_resubmission'], true) ? $operation : null;
    }

    public function assertCanView(EloquentModel $record, string $sourceKey, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        abort_unless($actor && $this->access->canView($actor, $record, $sourceKey, $this->ability($sourceKey)), 403);
    }

    /** Current official documents are available to users authorized for an action at the live routing stage. */
    public function canAccessCurrentDocument(EloquentModel $record, string $sourceKey, ?User $actor): bool
    {
        if (! $actor || ! $actor->is_active) return false;
        if (app(OrganizationalAccessService::class)->isGlobal($actor)) return true;

        $state = $this->state($record, $sourceKey, null, $actor);
        foreach ($state['actions'] as $action) {
            if (($action['internal_only'] ?? false) || ($action['from'] ?? null) !== $state['stage']) continue;
            if ($this->access->canPerform($actor, $record, $sourceKey, $action)) return true;
        }

        return false;
    }

    public function transition(EloquentModel $record, string $sourceKey, string $actionKey, ?int $userId, ?string $remarks = null, ?string $correctionReasonKey = null, ?string $correctionDetail = null): DocumentRoutingEvent
    {
        $actor = $userId ? User::query()->findOrFail($userId) : auth()->user();
        abort_unless($actor, 403);

        $event = DB::transaction(function () use ($record, $sourceKey, $actionKey, $actor, $remarks, $correctionReasonKey, $correctionDetail): DocumentRoutingEvent {
            /** @var EloquentModel $locked */
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $lockedEvents = $this->eventsForTransition($locked, $sourceKey);
            $position = $this->positionSnapshots->resolve($locked, $sourceKey, $lockedEvents->isNotEmpty(), $lockedEvents->isEmpty(), true);
            $state = $this->state($locked, $sourceKey, $lockedEvents, $actor, $position);
            // Authorize known effective actions before checking whether they
            // are still current. Custom position-dependent dispatches must use
            // the captured graph and current actor scope just like base actions.
            $knownAction = collect($state['route_actions'])->firstWhere('key', $actionKey);
            if ($knownAction) {
                abort_unless($this->access->canPerform($actor, $locked, $sourceKey, $knownAction, $this->ability($sourceKey)), 403);
            }
            if ($actionKey === 'return_for_correction_penro_records'
                && ($knownAction['receipt_correction_context'] ?? null) === 'penro_records'
                && $state['route_profile'] === 'direct'
                && $state['stage'] === DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS
                && ! $this->supportedIncomingSender($state['route_actions'], $state['events'], (string) $state['stage'])) {
                throw ValidationException::withMessages(['stage' => 'A correction return cannot be recorded because no verified sender is recorded in the captured direct route.']);
            }
            $action = collect($state['actions'])->firstWhere('key', $actionKey);
            if (! $action || $action['from'] !== $state['stage']) {
                throw ValidationException::withMessages(['stage' => 'This document is no longer awaiting that routing action.']);
            }
            $ability = $this->ability($sourceKey);
            abort_unless($this->access->canPerform($actor, $locked, $sourceKey, $action, $ability), 403);
            if (($action['correction'] ?? false) && blank(trim((string) $remarks))) {
                if (! isset($action['receipt_correction_context'])) throw ValidationException::withMessages(['remarks' => 'Correction remarks are required.']);
            }

            if (isset($action['receipt_correction_context'])) {
                $validReasons = $action['receipt_correction_context'] === 'cenro_records'
                    ? ['missing_signature', 'missing_attachment', 'incomplete_document', 'other']
                    : ['missing_endorsement', 'missing_attachment', 'missing_received_copy', 'incomplete_document', 'other'];
                if (! in_array($correctionReasonKey, $validReasons, true)) throw ValidationException::withMessages(['correction_reason_key' => 'Select a correction reason.']);
                if ($correctionReasonKey === 'other' && blank(trim((string) $correctionDetail))) throw ValidationException::withMessages(['correction_detail' => 'Explain the reason when Other is selected.']);
                $senderEvent = $this->supportedIncomingSender($state['route_actions'], $state['events'], (string) $state['stage']);
                if ($senderEvent) {
                    $action['to'] = $senderEvent->from_stage;
                    $action['to_office'] = $senderEvent->from_office;
                } elseif ($action['to'] === DocumentRoutingProfileRegistry::CENRO_RECORDS && $locked->getAttribute('target_office')) {
                    $action['to_office'] = $locked->getAttribute('target_office');
                }
                $remarks = trim((string) $correctionDetail) ?: null;
            }

            if ($locked instanceof \App\Models\ConservationReportSubmission
                && $sourceKey === 'conservation'
                && PambRoutingTimelineService::appliesWorkflow((string) $locked->workflow_key)
                && ! $this->pambMovAllowsAction($locked, (string) $action['key'], (string) $state['route_profile'])) {
                throw ValidationException::withMessages(['stage' => 'The PAMB MOV/review gate does not allow this custody transition yet.']);
            }

            $this->checkpointPolicy->assertTransitionAllowed($action['key'], $action['from'], $action['to']);
            $snapshot = $this->positionSnapshots->capture($locked, $sourceKey, $state['route_position'], (string) $state['route_profile'], $actor);

            $event = DocumentRoutingEvent::query()->create([
                'source_type' => $sourceKey,
                'source_id' => $locked->getKey(),
                'workflow_key' => $locked->getAttribute('workflow_key'),
                'event_key' => $action['event_key'],
                'from_stage' => $action['from'],
                'to_stage' => $action['to'],
                'from_office' => $action['from_office'],
                'to_office' => $action['to_office'],
                'occurred_at' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE),
                'recorded_by' => $actor->getKey(),
                'remarks' => $remarks,
                'metadata' => [
                    'state_source' => $state['bootstrapped'] ? 'imported_existing_milestones' : 'routing_events',
                    'action_key' => $action['key'],
                    'route_setting_version' => (int) ($state['route_position']['version'] ?? 1),
                    'route_setting_version_id' => $state['route_position']['setting_version_id'] ?? null,
                    'route_snapshot_id' => $snapshot?->getKey() ?? $state['route_position']['snapshot_id'] ?? null,
                    'route_graph_version' => $state['route_position']['graph_version'],
                    'route_profile' => $state['route_profile'],
                    ...($this->checkpointPolicy->isCheckpoint($action['key'], $action['from'], $action['to']) ? ['routing_checkpoint' => 'penro_records_initial_dispatch'] : []),
                    'correction' => (bool) ($action['correction'] ?? false),
                    'correction_cycle' => (bool) (($action['correction'] ?? false) || ($action['correction_cycle'] ?? false) || $state['correction']),
                    ...($state['active_cycle'] !== null ? ['pamb_cycle' => (int) $state['active_cycle'] + (($action['correction'] ?? false) ? 1 : 0)] : []),
                    ...($correctionReasonKey !== null ? [
                        'correction_reason_key' => $correctionReasonKey,
                        'correction_reason' => $this->correctionReasonLabel((string) $correctionReasonKey),
                        'correction_detail' => $correctionDetail,
                    ] : []),
                ],
            ]);

            $this->syncCompatibilityMilestone($locked, $sourceKey, $action['key']);
            return $event->load('recordedBy:id,name,section');
        });
        if (! $this->checkpointPolicy->isCheckpoint($actionKey, (string) $event->from_stage, (string) $event->to_stage)) {
            try {
                app(EdatsInAppNotificationService::class)->notifyGenericTransition($record, $sourceKey, $event, [
                    'key' => data_get($event->metadata, 'action_key'), 'event_key' => $event->event_key, 'to_office' => $event->to_office,
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $event;
    }

    private function correctionReasonLabel(string $key): string
    {
        return ['missing_signature' => 'Missing Signature', 'missing_endorsement' => 'Missing Endorsement', 'missing_attachment' => 'Missing Attachment', 'missing_received_copy' => 'Missing Received Copy', 'incomplete_document' => 'Incomplete / Incorrect Document', 'other' => 'Other'][$key] ?? $key;
    }

    /** @return array<string,mixed> */
    /** @param array<string,mixed> $override */
    public function transitionAsOverride(EloquentModel $record, string $sourceKey, string $actionKey, User $actor, array $override, ?string $remarks = null): DocumentRoutingEvent
    {
        $event = DB::transaction(function () use ($record, $sourceKey, $actionKey, $actor, $override, $remarks): DocumentRoutingEvent {
            /** @var EloquentModel $locked */
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $lockedEvents = $this->eventsForTransition($locked, $sourceKey);
            $position = $this->positionSnapshots->resolve($locked, $sourceKey, $lockedEvents->isNotEmpty(), $lockedEvents->isEmpty(), true);
            $state = $this->state($locked, $sourceKey, $lockedEvents, null, $position);
            $action = collect($state['actions'])->firstWhere('key', $actionKey);
            if (! $action || $action['from'] !== $state['stage']) {
                throw ValidationException::withMessages(['stage' => 'This document is no longer awaiting that routing action.']);
            }
            if (($action['correction'] ?? false) && blank(trim((string) $remarks))) {
                throw ValidationException::withMessages(['remarks' => 'Correction remarks are required.']);
            }
            if ($locked instanceof \App\Models\ConservationReportSubmission
                && $sourceKey === 'conservation'
                && PambRoutingTimelineService::appliesWorkflow((string) $locked->workflow_key)
                && ! $this->pambMovAllowsAction($locked, (string) $action['key'], (string) $state['route_profile'])) {
                throw ValidationException::withMessages(['stage' => 'The PAMB MOV/review gate does not allow this custody transition yet.']);
            }
            $this->checkpointPolicy->assertTransitionAllowed($action['key'], $action['from'], $action['to']);
            $snapshot = $this->positionSnapshots->capture($locked, $sourceKey, $state['route_position'], (string) $state['route_profile'], $actor);
            $event = DocumentRoutingEvent::query()->create([
                'source_type' => $sourceKey,
                'source_id' => $locked->getKey(),
                'workflow_key' => $locked->getAttribute('workflow_key'),
                'event_key' => $action['event_key'],
                'from_stage' => $action['from'],
                'to_stage' => $action['to'],
                'from_office' => $action['from_office'],
                'to_office' => $action['to_office'],
                'occurred_at' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE),
                'recorded_by' => $actor->getKey(),
                'remarks' => $remarks,
                'metadata' => ['state_source' => 'routing_events', 'action_key' => $action['key'], 'route_setting_version' => (int) ($state['route_position']['version'] ?? 1), 'route_setting_version_id' => $state['route_position']['setting_version_id'] ?? null, 'route_snapshot_id' => $snapshot?->getKey() ?? $state['route_position']['snapshot_id'] ?? null, 'route_graph_version' => $state['route_position']['graph_version'], 'route_profile' => $state['route_profile'], ...($this->checkpointPolicy->isCheckpoint($action['key'], $action['from'], $action['to']) ? ['routing_checkpoint' => 'penro_records_initial_dispatch'] : []), 'correction' => (bool) ($action['correction'] ?? false), 'administrative_override' => true, ...($state['active_cycle'] !== null ? ['pamb_cycle' => (int) $state['active_cycle'] + (($action['correction'] ?? false) ? 1 : 0)] : []), ...$override],
            ]);
            $this->syncCompatibilityMilestone($locked, $sourceKey, $action['key']);
            return $event->load('recordedBy:id,name,section');
        });
        if (! $this->checkpointPolicy->isCheckpoint($actionKey, (string) $event->from_stage, (string) $event->to_stage)) {
            try {
                app(EdatsInAppNotificationService::class)->notifyGenericTransition($record, $sourceKey, $event, [
                    'key' => data_get($event->metadata, 'action_key'), 'event_key' => $event->event_key, 'to_office' => $event->to_office,
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $event;
    }
    public function presentation(EloquentModel $record, string $sourceKey, ?Collection $events = null, ?User $actor = null, ?array $resolvedState = null): array
    {
        $canReuseState = $this->stateMatches($record, $sourceKey, $events, $resolvedState);
        if ($canReuseState) LocalNavigationTrace::incrementCurrent('canonical_state_reuses');
        $state = $canReuseState ? $resolvedState : $this->state($record, $sourceKey, $events, $actor);
        $current = (string) $state['stage'];
        $actions = collect($state['actions']);
        $allowed = $actor ? $actions->filter(function (array $action) use ($current, $actor, $record, $sourceKey, $state): bool {
            if (($action['internal_only'] ?? false) || $action['from'] !== $current
                || ! $this->access->canPerform($actor, $record, $sourceKey, $action, $this->ability($sourceKey))) return false;
            if ($record instanceof \App\Models\ConservationReportSubmission
                && $sourceKey === 'conservation'
                && PambRoutingTimelineService::appliesWorkflow((string) $record->workflow_key)
                && ! $this->pambMovAllowsAction($record, (string) $action['key'], (string) $state['route_profile'])) {
                return false;
            }
            return true;
        })->values() : collect();
        $allowed = $allowed->map(fn (array $action): array => [...$action, 'can_replace_document' => in_array($action['document_operation'] ?? null, ['forward', 'correction_resubmission'], true)])->values();
        return [...$state, 'allowed_actions' => $allowed->all(), 'capabilities' => $actor ? $this->access->capabilities($actor, $record, $sourceKey, $allowed->all(), $this->ability($sourceKey)) : []];
    }

    private function pambMovAllowsAction(\App\Models\ConservationReportSubmission $record, string $actionKey, ?string $routeProfile = null): bool
    {
        $routeProfile ??= app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($record) ? 'direct' : 'regular';
        if ($routeProfile === 'direct' || $record->date_report_released_cenro !== null) return true;
        $status = app(PambMovProcessingService::class)->status($record);
        return match ($actionKey) {
            // A legacy MOV may already carry the Chief's approval before
            // the first shared custody handoff. Preserve that approval while
            // requiring the same focal -> Chief handoff and receipt graph.
            'forward_to_cenro_chief' => in_array($status, [PambMovProcessingService::SUBMITTED_FOR_REVIEW, PambMovProcessingService::READY_FOR_RELEASE], true),
            'forward_to_cenro_records', 'forward_to_penro_records' => $status === PambMovProcessingService::READY_FOR_RELEASE,
            'return_to_cenro_focal' => $status === PambMovProcessingService::NEEDS_CORRECTION,
            default => true,
        };
    }

    /** The reusable state contains routing facts only, never actor decisions. */
    private function stateMatches(EloquentModel $record, string $sourceKey, ?Collection $events, ?array $state): bool
    {
        if ($events === null || ! is_array($state['_projection_identity'] ?? null)) return false;

        return $state['_projection_identity'] === $this->projectionIdentity($record, $sourceKey, $events);
    }

    /** @return array{source:string,record_class:string,record_id:string,events:string} */
    private function projectionIdentity(EloquentModel $record, string $sourceKey, Collection $events): array
    {
        $eventFacts = $events->map(static fn (mixed $event): array => [
            'class' => is_object($event) ? $event::class : gettype($event),
            'id' => is_object($event) && method_exists($event, 'getKey') ? (string) $event->getKey() : null,
            'source_type' => data_get($event, 'source_type'),
            'source_id' => data_get($event, 'source_id'),
            'event_key' => data_get($event, 'event_key'),
            'from_stage' => data_get($event, 'from_stage'),
            'to_stage' => data_get($event, 'to_stage'),
            'stage_key' => data_get($event, 'stage_key'),
            'workflow_key' => data_get($event, 'workflow_key'),
            'occurred_at' => data_get($event, 'occurred_at')?->format('Y-m-d H:i:s.u'),
            'cycle' => data_get($event, 'cycle') ?? data_get($event, 'metadata.cycle') ?? data_get($event, 'metadata.correction_cycle'),
            'legacy_pamb_event_id' => data_get($event, 'metadata.legacy_pamb_event_id'),
        ])->values()->all();
        $pambEvents = $record instanceof \App\Models\ConservationReportSubmission && $record->relationLoaded('routingEvents')
            ? $record->routingEvents->map(static fn (mixed $event): array => [
                'id' => method_exists($event, 'getKey') ? (string) $event->getKey() : null,
                'stage_key' => data_get($event, 'stage_key'),
                'occurred_at' => data_get($event, 'occurred_at')?->format('Y-m-d H:i:s.u'),
            ])->values()->all()
            : null;

        return [
            'source' => $sourceKey,
            'record_class' => $record::class,
            'record_id' => (string) $record->getKey(),
            'events' => hash('sha256', serialize($eventFacts)),
            'pamb_cycle_events' => $pambEvents === null ? null : hash('sha256', serialize($pambEvents)),
            'workflow_key' => (string) $record->getAttribute('workflow_key'),
            'protected_area_id' => (string) $record->getAttribute('protected_area_id'),
        ];
    }

    /** @return array{0:string,1:bool} */
    private function legacyStage(EloquentModel $record, bool $direct, ?User $actor, string $sourceKey): array
    {
        $meetingWorkflow = $record instanceof \App\Models\ConservationReportSubmission
            && $sourceKey === 'conservation'
            && app(ConservationMeetingRoutingCompatibilityAdapter::class)->applies($record);
        if ($sourceKey === 'engp') {
            if ($record->getAttribute('date_endorsed_regional')) return [DocumentRoutingProfileRegistry::RELEASED_REGIONAL, true];
            if ($record->getAttribute('date_received_penro')) return [DocumentRoutingProfileRegistry::PENRO_RECORDS, true];
            if ($record->getAttribute('date_report_released_cenro')) return [DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, true];
            return [DocumentRoutingProfileRegistry::PREPARATION, false];
        }
        // PAMB date columns can contain old administrative corrections without
        // a corresponding custody event. A meeting is complete only when its
        // adapted/shared event history reaches the terminal stage.
        if ($record->getAttribute('date_endorsed_regional') && ! $meetingWorkflow) return [DocumentRoutingProfileRegistry::RELEASED_REGIONAL, true];
        if ($record->getAttribute('date_received_penro')) return [DocumentRoutingProfileRegistry::PENRO_RECORDS, true];
        if ($record->getAttribute('date_report_released_cenro')) return [DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, true];
        if ($direct) {
            return [DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, false];
        }
        return [DocumentRoutingProfileRegistry::PREPARATION, false];
    }

    private function syncCompatibilityMilestone(EloquentModel $record, string $sourceKey, string $actionKey): void
    {
        $changes = match ($actionKey) {
            'forward_to_penro_records' => ['date_report_released_cenro' => now(BusinessCalendarService::TIMEZONE)->toDateString()],
            'receive_at_penro_records' => ['date_received_penro' => now(BusinessCalendarService::TIMEZONE)->toDateString()],
            'release_to_regional' => ['date_endorsed_regional' => now(BusinessCalendarService::TIMEZONE)->toDateString()],
            default => [],
        };
        if ($changes) $record->update($changes);
    }

    private function ability(string $sourceKey): ?string
    {
        return match ($sourceKey) {
            'bms' => 'bms.update', 'bams' => 'bams.update', 'imea', 'imea-maintenance' => 'imea.update', 'aws' => 'aws.update',
            'management-plans' => 'management-plans.update', default => 'technical-reports.update',
        };
    }
}
