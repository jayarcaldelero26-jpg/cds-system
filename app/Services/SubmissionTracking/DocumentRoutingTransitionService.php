<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
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
    ) {}

    /** @param Collection<int,DocumentRoutingEvent>|null $events */
    public function state(EloquentModel $record, string $sourceKey, ?Collection $events = null, ?User $actor = null): array
    {
        $events ??= $this->events($record, $sourceKey);
        if (method_exists($record, 'protectedArea') && ! $record->relationLoaded('protectedArea')) {
            $record->load('protectedArea');
        }
        $direct = $sourceKey !== 'conservation' || ! app(\App\Services\Conservation\PambComplianceCalculator::class)->applies((string) $record->getAttribute('workflow_key'))
            ? app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($record)
            : false;
        $profile = $this->profiles->actionProfile($sourceKey, $direct);
        $last = $events->sortBy(fn (DocumentRoutingEvent $event): string => ($event->occurred_at?->toDateTimeString() ?? '').sprintf('%010d', $event->id))->last();
        $bootstrapped = false;
        $stage = $last?->to_stage;

        if (! $stage) {
            [$stage, $bootstrapped] = $this->legacyStage($record, $direct, $actor);
        }

        return [
            'stage' => $stage,
            'bootstrapped' => $bootstrapped,
            'events' => $events->sortBy(fn (DocumentRoutingEvent $event): string => ($event->occurred_at?->toDateTimeString() ?? '').sprintf('%010d', $event->id))->values(),
            'profile' => $profile['profile'],
            'actions' => $profile['actions'],
        ];
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
        return collect($this->state($record, $sourceKey, null, $actor)['actions'])->pluck('key')->values()->all();
    }

    public function assertCanView(EloquentModel $record, string $sourceKey, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        abort_unless($actor && $this->access->canView($actor, $record, $sourceKey, $this->ability($sourceKey)), 403);
    }

    public function transition(EloquentModel $record, string $sourceKey, string $actionKey, ?int $userId, ?string $remarks = null): DocumentRoutingEvent
    {
        $actor = $userId ? User::query()->findOrFail($userId) : auth()->user();
        abort_unless($actor, 403);

        return DB::transaction(function () use ($record, $sourceKey, $actionKey, $actor, $remarks): DocumentRoutingEvent {
            /** @var EloquentModel $locked */
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $state = $this->state($locked, $sourceKey, $this->events($locked, $sourceKey), $actor);
            $action = collect($state['actions'])->firstWhere('key', $actionKey);
            if (! $action || $action['from'] !== $state['stage']) {
                throw ValidationException::withMessages(['stage' => 'This document is no longer awaiting that routing action.']);
            }
            $ability = $this->ability($sourceKey);
            abort_unless($this->access->canPerform($actor, $locked, $sourceKey, $action, $ability), 403);

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
                'metadata' => ['state_source' => $state['bootstrapped'] ? 'imported_existing_milestones' : 'routing_events'],
            ]);

            $this->syncCompatibilityMilestone($locked, $action['key']);
            return $event->load('recordedBy:id,name,section');
        });
    }

    /** @return array<string,mixed> */
    public function presentation(EloquentModel $record, string $sourceKey, ?Collection $events = null, ?User $actor = null): array
    {
        $state = $this->state($record, $sourceKey, $events, $actor);
        $current = (string) $state['stage'];
        $actions = collect($state['actions']);
        $allowed = $actor ? $actions->filter(fn (array $action): bool => $action['from'] === $current && $this->access->canPerform($actor, $record, $sourceKey, $action, $this->ability($sourceKey)))->values() : collect();
        return [...$state, 'allowed_actions' => $allowed->all(), 'capabilities' => $actor ? $this->access->capabilities($actor, $record, $sourceKey, $allowed->all(), $this->ability($sourceKey)) : []];
    }

    /** @return array{0:string,1:bool} */
    private function legacyStage(EloquentModel $record, bool $direct, ?User $actor): array
    {
        if ($record->getAttribute('date_endorsed_regional')) return [DocumentRoutingProfileRegistry::RELEASED_REGIONAL, true];
        if ($record->getAttribute('date_received_penro')) return [DocumentRoutingProfileRegistry::PENRO_RECORDS, true];
        if ($record->getAttribute('date_report_released_cenro')) return [DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, true];
        if ($direct) return [DocumentRoutingProfileRegistry::PENRO_ORIGIN, false];
        if ($actor && app(OrganizationalAccessService::class)->effectiveCategory($actor) === OrganizationalAccessService::PAMO) return [DocumentRoutingProfileRegistry::PAMO_ORIGIN, false];
        return [DocumentRoutingProfileRegistry::PREPARATION, false];
    }

    private function syncCompatibilityMilestone(EloquentModel $record, string $actionKey): void
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
            'bms' => 'bms.update', 'bams', 'imea', 'imea-maintenance' => 'imea.update', 'aws' => 'aws.update',
            'management-plans' => 'management-plans.update', default => 'technical-reports.update',
        };
    }
}
