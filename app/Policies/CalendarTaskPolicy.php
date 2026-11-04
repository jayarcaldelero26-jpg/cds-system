<?php

namespace App\Policies;

use App\Models\CalendarTask;
use App\Models\User;
use App\Services\Calendar\CalendarTaskAudienceService;

class CalendarTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAccessCalendar($user);
    }

    public function create(User $user): bool
    {
        return $this->canAccessCalendar($user);
    }

    public function view(User $user, CalendarTask $task): bool
    {
        return app(CalendarTaskAudienceService::class)->canView($user, $task);
    }

    public function update(User $user, CalendarTask $task): bool
    {
        return (int) $task->created_by === (int) $user->getKey()
            && $this->canAccessCalendar($user)
            && $task->status === CalendarTask::STATUS_SCHEDULED;
    }

    public function complete(User $user, CalendarTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function cancel(User $user, CalendarTask $task): bool
    {
        return $this->update($user, $task);
    }

    private function canAccessCalendar(User $user): bool
    {
        return $user->is_active && $user->is_approved && $user->can('calendar.view');
    }
}
