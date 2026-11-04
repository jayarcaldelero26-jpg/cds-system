<?php

namespace App\Http\Controllers;

use App\Models\CalendarTask;
use App\Policies\CalendarTaskPolicy;
use App\Services\Calendar\CalendarTaskService;
use App\Services\Calendar\CalendarTaskAttributionService;
use App\Services\Calendar\CalendarTaskAudienceService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CalendarTaskController extends Controller
{
    public function store(Request $request, CalendarTaskService $tasks, CalendarTaskPolicy $policy, CalendarTaskAttributionService $attribution, CalendarTaskAudienceService $audiences, AuditLogService $audit): RedirectResponse
    {
        $this->ensureStorage($tasks);
        abort_unless($policy->create($request->user()), 403);
        $data = $this->validatedTaskData($request, $tasks);
        $shareWithOffice = (bool) ($data['share_with_office'] ?? false);
        $includeSupervising = (bool) ($data['include_supervising_office'] ?? false);
        if ($includeSupervising && ! $shareWithOffice) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'include_supervising_office' => 'Choose office sharing before including a supervising office.',
            ]);
        }
        unset($data['share_with_office'], $data['include_supervising_office']);
        DB::transaction(function () use ($data, $request, $attribution, $audiences, $audit, $shareWithOffice, $includeSupervising): void {
            $resolvedAudiences = $audiences->createAudiences($request->user(), $shareWithOffice, $includeSupervising);
            $task = CalendarTask::query()->create([
                ...$this->normalizeTimes($data),
                'created_by' => $request->user()->getKey(),
                'status' => CalendarTask::STATUS_SCHEDULED,
            ]);
            if ($shareWithOffice) $task->forceFill(['visibility' => 'shared'])->save();
            $audiences->persistAudiences($task, $resolvedAudiences);
            $audit->record('calendar_task', 'created', CalendarTask::class, $task->getKey(), 'Calendar', 'Calendar task created.', [
                'calendar_task_attribution' => $attribution->snapshot($request->user()),
                'calendar_task_sharing' => [
                    'choice' => $shareWithOffice ? 'shared_with_office' : 'private',
                    'include_supervising_office' => $includeSupervising,
                    'audiences' => array_map(fn (array $audience): array => $audiences->auditAudience($audience), $resolvedAudiences),
                ],
            ], (int) $request->user()->getKey(), $request);
        });

        return back()->with('success', 'Calendar task saved.');
    }

    public function update(Request $request, string $task, CalendarTaskService $tasks, CalendarTaskPolicy $policy): RedirectResponse
    {
        $this->ensureStorage($tasks);
        $record = $this->ownedTask($request, $task);
        abort_unless($policy->update($request->user(), $record), 403);
        $data = $this->validatedTaskData($request, $tasks, $record);
        $record->fill($this->normalizeTimes($data))->save();

        return back()->with('success', 'Calendar task updated.');
    }

    public function updateStatus(Request $request, string $task, CalendarTaskService $tasks, CalendarTaskPolicy $policy, CalendarTaskAttributionService $attribution, AuditLogService $audit): RedirectResponse
    {
        $this->ensureStorage($tasks);
        $data = $request->validate(['status' => ['required', Rule::in([CalendarTask::STATUS_DONE, CalendarTask::STATUS_CANCELLED])]]);
        DB::transaction(function () use ($request, $task, $data, $policy, $attribution, $audit): void {
            $record = CalendarTask::query()->where('created_by', $request->user()->getKey())->lockForUpdate()->findOrFail($task);
            $ability = $data['status'] === CalendarTask::STATUS_DONE ? 'complete' : 'cancel';
            abort_unless($policy->{$ability}($request->user(), $record), 403);
            $previous = $record->status;
            $record->forceFill(['status' => $data['status']])->save();
            $audit->record('calendar_task', 'status_changed', CalendarTask::class, $record->getKey(), 'Calendar', 'Calendar task status changed.', [
                'calendar_task_attribution' => $attribution->snapshot($request->user()),
                'status_from' => $previous,
                'status_to' => $data['status'],
            ], (int) $request->user()->getKey(), $request);
        });

        return back()->with('success', $data['status'] === CalendarTask::STATUS_DONE ? 'Calendar task marked done.' : 'Calendar task cancelled.');
    }

    private function ensureStorage(CalendarTaskService $tasks): void
    {
        abort_unless($tasks->isReady(), 503, 'Calendar task storage is not installed.');
    }

    private function ownedTask(Request $request, string $id): CalendarTask
    {
        return CalendarTask::query()
            ->where('created_by', $request->user()->getKey())
            ->findOrFail($id);
    }

    private function validatedTaskData(Request $request, CalendarTaskService $tasks, ?CalendarTask $existingTask = null): array
    {
        $rangeReady = $tasks->rangeReady();
        $identityRules = [
            'created_by' => ['prohibited'],
            'creator_id' => ['prohibited'],
            'creator_name' => ['prohibited'],
            'user_id' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'actor_name' => ['prohibited'],
            'office' => ['prohibited'],
            'office_id' => ['prohibited'],
            'office_name' => ['prohibited'],
            'office_designated' => ['prohibited'],
            'organizational_office_id' => ['prohibited'],
            'shared_office_id' => ['prohibited'],
            'protected_area_id' => ['prohibited'],
            'protectedAreaId' => ['prohibited'],
            'protected_area' => ['prohibited'],
            'pamo_office_id' => ['prohibited'],
            'pamo_office_name' => ['prohibited'],
            'unit' => ['prohibited'],
            'unit_assignment' => ['prohibited'],
            'audience' => ['prohibited'],
            'audiences' => ['prohibited'],
            'audience_ids' => ['prohibited'],
            'task_audiences' => ['prohibited'],
            'audience_type' => ['prohibited'],
            'visibility' => ['prohibited'],
            'sharing' => ['prohibited'],
            'share_scope' => ['prohibited'],
            'share_with_supervising_office' => ['prohibited'],
        ];
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_end_date' => [$rangeReady ? 'nullable' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:scheduled_date'],
            'is_all_day' => ['required', 'boolean'],
            'start_time' => ['exclude_if:is_all_day,1', 'nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['exclude_if:is_all_day,1', 'nullable', 'date_format:H:i', 'required_with:start_time'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'share_with_office' => [$existingTask ? 'prohibited' : 'sometimes', 'boolean'],
            'include_supervising_office' => [$existingTask ? 'prohibited' : 'sometimes', 'boolean'],
            ...$identityRules,
        ]);

        $effectiveEndDate = array_key_exists('scheduled_end_date', $data)
            ? $data['scheduled_end_date']
            : ($rangeReady ? $existingTask?->scheduled_end_date?->format('Y-m-d') : null);

        if ($rangeReady) {
            if ($effectiveEndDate && $effectiveEndDate < $data['scheduled_date']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'scheduled_end_date' => 'The retained end date must be the same as or later than the start date.',
                ]);
            }
        }

        if (! ($data['is_all_day'] ?? false) && ! empty($data['start_time']) && ! empty($data['end_time'])) {
            $endDateForTime = $effectiveEndDate ?: $data['scheduled_date'];
            if (($endDateForTime.$data['end_time']) <= ($data['scheduled_date'].$data['start_time'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['end_time' => 'The end time must be later than the start date and time.']);
            }
        }

        return $data;
    }

    private function normalizeTimes(array $data): array
    {
        if ($data['is_all_day']) {
            $data['start_time'] = null;
            $data['end_time'] = null;
        }

        return $data;
    }
}
