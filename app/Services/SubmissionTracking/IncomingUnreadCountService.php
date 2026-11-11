<?php

namespace App\Services\SubmissionTracking;

use App\Models\User;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Count unread workflow arrivals that remain in the user's authorized Incoming queue. */
final class IncomingUnreadCountService
{
    public function __construct(private readonly SubmissionTrackingService $tracking) {}

    /**
     * Resolve a specific report against the current authorized Incoming queue.
     * If the notice identifies a handoff, the report must still be at that
     * exact routing event when the selection is opened.
     */
    public function isCurrentIncomingAction(User $user, string $source, int $sourceId, ?int $arrivalEventId = null): bool
    {
        return $this->incomingActionStatuses($user, [
            'selected' => ['source' => $source, 'source_id' => $sourceId, 'arrival_event_id' => $arrivalEventId],
        ])['selected'] ?? false;
    }

    /**
     * Resolve multiple notification destinations against one authorized queue
     * snapshot and their exact current handoff identity.
     *
     * @param array<string, array{source:string,source_id:int,arrival_event_id:?int}> $targets
     * @return array<string, bool>
     */
    public function incomingActionStatuses(User $user, array $targets): array
    {
        $statuses = array_fill_keys(array_keys($targets), false);
        if ($targets === []
            || ! $user->is_active
            || (int) auth()->id() !== (int) $user->getKey()
            || ! app(OrganizationalAccessService::class)->canViewSubmissionTracking($user)) {
            return $statuses;
        }

        $allowedSources = array_flip($this->tracking->archiveSourceKeys());
        $idsBySource = [];
        foreach ($targets as $target) {
            $source = $target['source'] ?? '';
            $sourceId = (int) ($target['source_id'] ?? 0);
            if (! is_string($source) || ! isset($allowedSources[$source]) || $sourceId < 1) continue;
            $idsBySource[$source][$sourceId] = $sourceId;
        }

        $records = collect();
        foreach ($idsBySource as $source => $ids) {
            foreach (collect(array_values($ids))->chunk(400) as $chunk) {
                $records = $records->merge($this->tracking->records([
                    '_selected_source' => $source,
                    '_selected_ids' => $chunk->all(),
                ], null, false));
            }
        }
        if ($records->isEmpty()) return $statuses;

        $recordByKey = $records->keyBy(fn (array $row): string => ($row['source'] ?? '').':'.($row['source_id'] ?? ''));
        $incomingKeys = $this->tracking->workspaceQueues([], $records)['incoming']
            ->map(fn (array $row): string => ($row['source'] ?? '').':'.($row['source_id'] ?? ''))
            ->flip();

        $documentIdsBySource = [];
        $pambIds = [];
        foreach ($targets as $target) {
            $source = (string) ($target['source'] ?? '');
            $sourceId = (int) ($target['source_id'] ?? 0);
            $key = $source.':'.$sourceId;
            if ($sourceId < 1 || ! $incomingKeys->has($key) || ($target['arrival_event_id'] ?? null) === null) continue;

            $record = $recordByKey->get($key);
            if ($source === 'conservation'
                && (bool) ($record['pamb_routing_applicable'] ?? false)
                && ! (bool) ($record['canonical_custody_applicable'] ?? false)) {
                $pambIds[$sourceId] = $sourceId;
            } else {
                $documentIdsBySource[$source][$sourceId] = $sourceId;
            }
        }

        $currentEventIds = [];
        if ($pambIds !== []) {
            foreach (collect(array_values($pambIds))->chunk(400) as $chunk) {
                $latest = PambRoutingEvent::query()
                    ->whereIn('conservation_report_submission_id', $chunk->all())
                    ->selectRaw('conservation_report_submission_id, MAX(id) as current_event_id')
                    ->groupBy('conservation_report_submission_id')
                    ->pluck('current_event_id', 'conservation_report_submission_id');
                foreach ($latest as $sourceId => $eventId) $currentEventIds['conservation:'.$sourceId] = (int) $eventId;
            }
        }
        foreach ($documentIdsBySource as $source => $ids) {
            foreach (collect(array_values($ids))->chunk(400) as $chunk) {
                $latest = DocumentRoutingEvent::query()
                    ->where('source_type', $source)
                    ->whereIn('source_id', $chunk->all())
                    ->selectRaw('source_id, MAX(id) as current_event_id')
                    ->groupBy('source_id')
                    ->pluck('current_event_id', 'source_id');
                foreach ($latest as $sourceId => $eventId) $currentEventIds[$source.':'.$sourceId] = (int) $eventId;
            }
        }

        foreach ($targets as $key => $target) {
            $source = (string) ($target['source'] ?? '');
            $sourceId = (int) ($target['source_id'] ?? 0);
            $reportKey = $source.':'.$sourceId;
            if ($sourceId < 1 || ! $incomingKeys->has($reportKey)) continue;

            $arrivalEventId = $target['arrival_event_id'] ?? null;
            $statuses[$key] = $arrivalEventId === null
                || (($currentEventIds[$reportKey] ?? null) !== null && $currentEventIds[$reportKey] === (int) $arrivalEventId);
        }

        return $statuses;
    }

    public function countFor(User $user): int
    {
        if (! $user->is_active
            || ! app(OrganizationalAccessService::class)->canViewSubmissionTracking($user)
            || ! Schema::hasTable('notifications')) {
            return 0;
        }

        $latestByReport = [];
        foreach ($user->notifications()
            ->where('data->type', 'workflow')
            ->latest('created_at')
            ->get(['id', 'data', 'read_at', 'created_at']) as $notification) {
            $arrival = $this->arrivalIdentity($notification->data ?? []);
            if ($arrival === null) continue;

            [$source, $sourceId, $eventId] = $arrival;
            $key = $source.':'.$sourceId;
            $createdAt = $notification->created_at?->getTimestamp() ?? 0;
            $current = $latestByReport[$key] ?? null;
            if ($current !== null
                && ($createdAt < $current['created_at']
                    || ($createdAt === $current['created_at'] && $eventId <= $current['event_id']))) {
                continue;
            }

            $latestByReport[$key] = [
                'source' => $source,
                'source_id' => $sourceId,
                'event_id' => $eventId,
                'created_at' => $createdAt,
                'unread' => $notification->read_at === null,
            ];
        }

        $unreadArrivals = collect($latestByReport)->filter(fn (array $arrival): bool => $arrival['unread']);
        if ($unreadArrivals->isEmpty()) return 0;

        $records = collect();
        foreach ($unreadArrivals->groupBy('source') as $source => $arrivals) {
            if (! in_array($source, $this->tracking->archiveSourceKeys(), true)) continue;

            foreach ($arrivals->pluck('source_id')->unique()->chunk(400) as $ids) {
                $records = $records->merge($this->tracking->records([
                    '_selected_source' => $source,
                    '_selected_ids' => $ids->all(),
                ], null, false));
            }
        }

        if ($records->isEmpty()) return 0;

        $incomingKeys = $this->tracking->workspaceQueues([], $records)['incoming']
            ->map(fn (array $record): string => ($record['source'] ?? '').':'.($record['source_id'] ?? ''))
            ->flip();

        return $unreadArrivals->keys()->filter(fn (string $key): bool => $incomingKeys->has($key))->count();
    }

    /** @param array<string, mixed> $data @return array{string,int,int}|null */
    private function arrivalIdentity(array $data): ?array
    {
        $source = $data['source_type'] ?? null;
        $sourceId = filter_var($data['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $eventId = EdatsInAppNotificationService::arrivalEventId($data);
        $parts = explode(':', (string) ($data['dedup_key'] ?? ''));
        // Notifications created before arrival metadata was introduced keep
        // their stable source and event identity in the workflow dedup key.
        if (count($parts) >= 5 && $parts[0] === 'workflow' && $parts[4] !== 'received') {
            $source ??= $parts[1];
            if ($sourceId === false) $sourceId = filter_var($parts[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $eventId ??= filter_var($parts[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }

        if (! is_string($source) || $source === '' || $sourceId === false || $eventId === false || $eventId === null) return null;

        return [$source, (int) $sourceId, (int) $eventId];
    }
}
