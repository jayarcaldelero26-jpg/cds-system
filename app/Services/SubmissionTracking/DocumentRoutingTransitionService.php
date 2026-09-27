<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\User;
use App\Services\Archive\ArchiveCheckpointPolicy;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Notifications\EdatsInAppNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Profile-driven, append-only custody transitions for non-PAMB documents. */
final class DocumentRoutingTransitionService
{
    public function __construct(
        private readonly DocumentRoutingProfileRegistry $profiles,
        private readonly DocumentRoutingAccessService $access,
        private readonly ArchiveCheckpointPolicy $checkpointPolicy,
    ) {}

    /** @param Collection<int,DocumentRoutingEvent>|null $events */
    public function state(EloquentModel $record, string $sourceKey, ?Collection $events = null, ?User $actor = null): array
    {
        $events ??= $this->events($record, $sourceKey);
        if (method_exists($record, 'protectedArea') && ! $record->relationLoaded('protectedArea')) {
            $record->load('protectedArea');
        }
        $direct = $sourceKey !== 'engp'
            && ($sourceKey !== 'conservation' || ! app(\App\Services\Conservation\PambComplianceCalculator::class)->applies((string) $record->getAttribute('workflow_key')))
            && app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($record);
        $profile = $this->profiles->actionProfile($sourceKey, $direct);
        $last = $events->sortBy(fn (DocumentRoutingEvent $event): string => ($event->occurred_at?->toDateTimeString() ?? '').sprintf('%010d', $event->id))->last();
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

        return [
            'stage' => $stage,
            'bootstrapped' => $bootstrapped,
            'events' => $events->sortBy(fn (DocumentRoutingEvent $event): string => ($event->occurred_at?->toDateTimeString() ?? '').sprintf('%010d', $event->id))->values(),
            'profile' => $profile['profile'],
            'actions' => $actions,
            // correction_cycle is historical metadata carried forward with the
            // routing chain. Only a latest unresolved return is current state;
            // correction receipt and later routing resolve it.
            'correction' => $lastCorrection,
            'correction_event' => ($lastCorrection || $correctionReceived) ? $lastCorrectionEvent : null,
        ];
    }

    private function categoryForStage(string $stage): string
    {
        if ($stage === DocumentRoutingProfileRegistry::CENRO_RECORDS) return OrganizationalAccessService::CENRO_RECORDS;
        if ($stage === DocumentRoutingProfileRegistry::PENRO_RECORDS) return OrganizationalAccessService::PENRO_RECORDS;
        if ($stage === DocumentRoutingProfileRegistry::PREPARATION) return OrganizationalAccessService::CENRO_FOCAL;
        if ($stage === DocumentRoutingProfileRegistry::CDS_FOCAL) return OrganizationalAccessService::PENRO_FOCAL;
        $action = collect($this->profiles->actionProfile('conservation')['actions'])->firstWhere('to', $stage);
        return (string) ($action['categories'][0] ?? OrganizationalAccessService::CENRO_RECORDS);
    }

    /** @return Collection<int,DocumentRoutingEvent> */
    public function events(EloquentModel $record, string $sourceKey): Collection
    {
        return DocumentRoutingEvent::query()
            ->where('source_type', $sourceKey)
            ->where('source_id', $record->getKey())
            ->with('recordedBy:id,name,section')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
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
    public function isCorrectionAction(EloquentModel $record, string $sourceKey, string $actionKey): bool
    {
        $action = collect($this->state($record, $sourceKey)['actions'])->firstWhere('key', $actionKey);

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
            $state = $this->state($locked, $sourceKey, $this->events($locked, $sourceKey), $actor);
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
                $senderEvent = $state['events']->filter(fn (DocumentRoutingEvent $event): bool => $event->to_stage === $state['stage'])->last();
                if ($senderEvent) {
                    $action['to'] = $senderEvent->from_stage;
                    $action['to_office'] = $senderEvent->from_office;
                } elseif ($action['to'] === DocumentRoutingProfileRegistry::CENRO_RECORDS && $locked->getAttribute('target_office')) {
                    $action['to_office'] = $locked->getAttribute('target_office');
                }
                $remarks = trim((string) $correctionDetail) ?: null;
            }

            $this->checkpointPolicy->assertTransitionAllowed($action['key'], $action['from'], $action['to']);

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
                    'correction' => (bool) ($action['correction'] ?? false),
                    'correction_cycle' => (bool) (($action['correction'] ?? false) || ($action['correction_cycle'] ?? false) || $state['correction']),
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
        $action = collect($this->profiles->actionProfile($sourceKey, false)['actions'])->firstWhere('key', $actionKey) ?: [];
        try {
            app(EdatsInAppNotificationService::class)->notifyGenericTransition($record, $sourceKey, $event, $action);
        } catch (\Throwable $exception) {
            report($exception);
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
            $state = $this->state($locked, $sourceKey, $this->events($locked, $sourceKey), null);
            $action = collect($state['actions'])->firstWhere('key', $actionKey);
            if (! $action || $action['from'] !== $state['stage']) {
                throw ValidationException::withMessages(['stage' => 'This document is no longer awaiting that routing action.']);
            }
            if (($action['correction'] ?? false) && blank(trim((string) $remarks))) {
                throw ValidationException::withMessages(['remarks' => 'Correction remarks are required.']);
            }
            $this->checkpointPolicy->assertTransitionAllowed($action['key'], $action['from'], $action['to']);
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
                'metadata' => ['state_source' => 'routing_events', 'action_key' => $action['key'], 'correction' => (bool) ($action['correction'] ?? false), 'administrative_override' => true, ...$override],
            ]);
            $this->syncCompatibilityMilestone($locked, $sourceKey, $action['key']);
            return $event->load('recordedBy:id,name,section');
        });
        $action = collect($this->profiles->actionProfile($sourceKey, false)['actions'])->firstWhere('key', $actionKey) ?: [];
        try {
            app(EdatsInAppNotificationService::class)->notifyGenericTransition($record, $sourceKey, $event, $action);
        } catch (\Throwable $exception) {
            report($exception);
        }
        return $event;
    }
    public function presentation(EloquentModel $record, string $sourceKey, ?Collection $events = null, ?User $actor = null): array
    {
        $state = $this->state($record, $sourceKey, $events, $actor);
        $current = (string) $state['stage'];
        $actions = collect($state['actions']);
        $allowed = $actor ? $actions->filter(fn (array $action): bool => ! ($action['internal_only'] ?? false) && $action['from'] === $current && $this->access->canPerform($actor, $record, $sourceKey, $action, $this->ability($sourceKey)))->values() : collect();
        $allowed = $allowed->map(fn (array $action): array => [...$action, 'can_replace_document' => in_array($action['document_operation'] ?? null, ['forward', 'correction_resubmission'], true)])->values();
        return [...$state, 'allowed_actions' => $allowed->all(), 'capabilities' => $actor ? $this->access->capabilities($actor, $record, $sourceKey, $allowed->all(), $this->ability($sourceKey)) : []];
    }

    /** @return array{0:string,1:bool} */
    private function legacyStage(EloquentModel $record, bool $direct, ?User $actor, string $sourceKey): array
    {
        if ($sourceKey === 'engp') {
            if ($record->getAttribute('date_endorsed_regional')) return [DocumentRoutingProfileRegistry::RELEASED_REGIONAL, true];
            if ($record->getAttribute('date_received_penro')) return [DocumentRoutingProfileRegistry::PENRO_RECORDS, true];
            if ($record->getAttribute('date_report_released_cenro')) return [DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, true];
            return [DocumentRoutingProfileRegistry::PREPARATION, false];
        }
        if ($record->getAttribute('date_endorsed_regional')) return [DocumentRoutingProfileRegistry::RELEASED_REGIONAL, true];
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
