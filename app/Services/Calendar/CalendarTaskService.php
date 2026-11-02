<?php

namespace App\Services\Calendar;

use App\Models\CalendarTask;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CalendarTaskService
{
    public function isReady(): bool
    {
        return Schema::hasTable('calendar_tasks');
    }

    public function rangeReady(): bool
    {
        return $this->isReady() && Schema::hasColumn('calendar_tasks', 'scheduled_end_date');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forRange(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! $this->isReady()) {
            return collect();
        }

        $rangeReady = $this->rangeReady();
        $query = CalendarTask::query()
            ->with('creator.protectedArea')
            ->where('created_by', $user->getKey())
            ->where(function ($query) use ($start, $end, $rangeReady): void {
                $query->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()]);
                if ($rangeReady) {
                    $query->orWhere(function ($overlap) use ($start, $end): void {
                        $overlap->where('scheduled_date', '<=', $end->toDateString())
                            ->whereRaw('COALESCE(scheduled_end_date, scheduled_date) >= ?', [$start->toDateString()]);
                    });
                }
            })
            ->orderBy('scheduled_date')
            ->orderByRaw('start_time IS NULL')
            ->orderBy('start_time')
            ->orderBy('id');
        $records = $query->get();
        $events = $this->auditEvents($records);
        $attribution = app(CalendarTaskAttributionService::class);
        $creator = $records->first()?->creator;
        $creatorFallback = $creator ? $attribution->snapshot($creator) + ['label' => 'Creator current office'] : null;

        return $records->map(fn (CalendarTask $task): array => $this->payload($task, $events[$task->getKey()] ?? [], $attribution, $creatorFallback, $rangeReady))->values();
    }

    public function payload(CalendarTask $task, array $events = [], ?CalendarTaskAttributionService $attribution = null, ?array $creatorFallback = null, ?bool $rangeReady = null): array
    {
        $creatorAttribution = $events['created']['metadata']['calendar_task_attribution'] ?? null;
        $creator = $task->creator;
        $attribution ??= app(CalendarTaskAttributionService::class);
        $rangeReady ??= $this->rangeReady();
        if (is_array($creatorAttribution)) {
            $creatorAttribution['label'] = 'Recorded creator office';
        } elseif ($creator) {
            $creatorAttribution = $creatorFallback ?? ($attribution->snapshot($creator) + ['label' => 'Creator current office']);
        }
        $statusEvent = $events['status'] ?? null;
        $statusActor = is_array($statusEvent['metadata']['calendar_task_attribution'] ?? null)
            ? $statusEvent['metadata']['calendar_task_attribution']
            : null;
        $statusDetails = $statusActor
            ? [
                ...$statusActor,
                'label' => 'Recorded status action',
                'status_from' => $statusEvent['metadata']['status_from'] ?? null,
                'status_to' => $statusEvent['metadata']['status_to'] ?? null,
                'timestamp' => $this->auditTimestamp($statusEvent['created_at'] ?? null),
            ]
            : ['label' => $task->status === CalendarTask::STATUS_SCHEDULED ? 'No status action yet' : 'Action details not recorded'];

        return [
            'id' => $task->getKey(),
            'title' => $task->title,
            'scheduled_date' => $task->scheduled_date?->format('Y-m-d'),
            'scheduled_end_date' => $rangeReady ? $task->scheduled_end_date?->format('Y-m-d') : null,
            'is_all_day' => (bool) $task->is_all_day,
            'start_time' => $task->start_time ? substr((string) $task->start_time, 0, 5) : null,
            'end_time' => $task->end_time ? substr((string) $task->end_time, 0, 5) : null,
            'notes' => $task->notes,
            'status' => $task->status,
            'creator_attribution' => is_array($creatorAttribution) ? $creatorAttribution : ['label' => 'Creator details not recorded'],
            'status_attribution' => $statusDetails,
        ];
    }

    /** @return array<int|string, array{created?: array, status?: array}> */
    private function auditEvents($records): array
    {
        if ($records->isEmpty() || ! Schema::hasTable('audit_logs')) return [];
        $ids = $records->modelKeys();
        $logs = AuditLog::query()
            ->whereIn('entity_type', [CalendarTask::class, 'calendar_task'])
            ->whereIn('entity_id', array_map('strval', $ids))
            ->whereIn('action', ['created', 'status_changed'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'action', 'metadata', 'created_at']);
        $events = [];
        foreach ($logs as $log) {
            $key = (string) $log->entity_id;
            $action = $log->action === 'created' ? 'created' : 'status';
            $events[$key][$action] = [
                'metadata' => is_array($log->metadata) ? $log->metadata : [],
                'created_at' => $log->created_at,
            ];
        }

        return $events;
    }

    private function auditTimestamp(mixed $value): ?string
    {
        if (! $value) return null;
        return CarbonImmutable::parse($value)->timezone('Asia/Manila')->toIso8601String();
    }
}
