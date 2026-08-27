<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;

trait HasStandardAReportCalculations
{
    public function getDeadlineSubmissionAttribute(): ?string
    {
        return $this->date_accomplished?->copy()->addWeekdays(15)->format('Y-m-d');
    }

    public function getNumberDaysCompliedAttribute(): int|string|null
    {
        if (! $this->date_accomplished) {
            return null;
        }
        if (! $this->date_received_penro) {
            return 'Pending Submission by CENRO';
        }

        return self::workingDaysAfterThrough($this->date_accomplished, $this->date_received_penro);
    }

    public function getTimelinessAttribute(): string
    {
        $days = $this->number_days_complied;
        if ($days === null) {
            return 'No Data';
        }
        if (! is_int($days)) {
            return $days;
        }

        return match (true) {
            $days <= 11 => 'Outstanding',
            $days <= 13 => 'Very Satisfactory',
            $days <= 15 => 'Satisfactory',
            $days <= 29 => 'Unsatisfactory',
            $days <= 90 => 'Poor',
            default => 'No Rating',
        };
    }

    public function getSubmissionStatusAttribute(): string
    {
        if (! $this->date_accomplished && ! $this->date_received_penro) {
            return 'No Activity Conducted';
        }
        if ($this->date_received_penro) {
            return 'Report Submitted';
        }

        return now()->startOfDay()->greaterThan($this->date_accomplished->copy()->addWeekdays(15)->startOfDay())
            ? 'Report Not Yet Submitted'
            : 'Ongoing Preparation at CENRO Level';
    }

    public function getTotalDaysDelayedPenroAttribute(): int|string
    {
        if (! $this->date_received_penro || ! $this->date_endorsed_regional) {
            return 'Please Update Date Endorsed to Regional Office';
        }

        return (int) $this->date_received_penro->diffInDays($this->date_endorsed_regional);
    }

    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }
        $days = 0;
        $cursor = $start->copy()->addDay()->startOfDay();
        $lastDay = $end->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($lastDay)) {
            if ($cursor->isWeekday()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }
}
