<?php

namespace App\Services;

use App\Models\NonWorkingDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;

/**
 * The single source of truth for report-submission business dates.
 *
 * Existing workflows retain the Monday-Thursday default calendar profile.
 * PAMB calculations explicitly use the Monday-Thursday profile, with
 * configured non-working days layered on top of either profile.
 */
class BusinessCalendarService
{
    public const TIMEZONE = 'Asia/Manila';

    /** @var list<int> */
    public const PAMB_WORKING_WEEKDAYS = [1, 2, 3, 4];

    public const SCOPE_NATIONAL = 'NATIONAL';
    public const SCOPE_DAVAO_ORIENTAL = 'DAVAO_ORIENTAL';
    public const SCOPE_OFFICE = 'OFFICE';

    /** @var array<string, Collection<int, NonWorkingDay>> */
    private static array $activeDaysByYear = [];

    public function isWorkingDay(CarbonInterface|string $date, ?string $office = null, ?array $workingWeekdays = null): bool
    {
        $day = $this->date($date);

        $workingWeekdays ??= [1, 2, 3, 4];
        if (! in_array($day->dayOfWeekIso, $workingWeekdays, true)) {
            return false;
        }

        return ! $this->activeNonWorkingDays($day->year, $office)
            ->contains(fn (NonWorkingDay $holiday): bool => $holiday->date->isSameDay($day));
    }

    public function addWorkingDays(CarbonInterface|string $startDate, int $numberOfDays, ?string $office = null, ?array $workingWeekdays = null): CarbonImmutable
    {
        $cursor = $this->date($startDate);
        $remaining = abs($numberOfDays);
        $step = $numberOfDays < 0 ? -1 : 1;

        while ($remaining > 0) {
            $cursor = $cursor->addDays($step);
            if ($this->isWorkingDay($cursor, $office, $workingWeekdays)) {
                $remaining--;
            }
        }

        return $cursor;
    }

    /**
     * Counts eligible dates strictly after startDate and through endDate.
     * This matches the existing report tracker semantics: the accomplishment
     * date itself is not counted, while a received date is counted.
     */
    public function workingDaysBetween(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        string $countingSemantics = 'after_through',
        ?string $office = null,
        ?array $workingWeekdays = null,
    ): int {
        if ($countingSemantics !== 'after_through') {
            throw new \InvalidArgumentException("Unsupported business-day counting semantics: {$countingSemantics}");
        }

        $start = $this->date($startDate);
        $end = $this->date($endDate);
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $days = 0;
        for ($cursor = $start->addDay(); $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addDay()) {
            if ($this->isWorkingDay($cursor, $office, $workingWeekdays)) {
                $days++;
            }
        }

        return $days;
    }

    public function signedWorkingDayDifference(
        CarbonInterface|string $deadline,
        CarbonInterface|string $receivedDate,
        ?string $office = null,
    ): int {
        $due = $this->date($deadline);
        $received = $this->date($receivedDate);

        if ($received->equalTo($due)) {
            return 0;
        }

        return $received->greaterThan($due)
            ? -$this->workingDaysBetween($due, $received, 'after_through', $office)
            : $this->workingDaysBetween($received, $due, 'after_through', $office);
    }

    public static function forgetCache(): void
    {
        self::$activeDaysByYear = [];
    }

    private function date(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
    }

    /** @return Collection<int, NonWorkingDay> */
    private function activeNonWorkingDays(int $year, ?string $office): Collection
    {
        // Pure model/unit tests may use Carbon accessors without booting Laravel's database.
        if (! Container::getInstance()->bound('db')) {
            return collect();
        }

        if (! array_key_exists((string) $year, self::$activeDaysByYear)) {
            self::$activeDaysByYear[(string) $year] = NonWorkingDay::query()
                ->whereYear('date', $year)
                ->where('is_active', true)
                ->get(['date', 'type', 'scope', 'location']);
        }

        return self::$activeDaysByYear[(string) $year]
            ->filter(function (NonWorkingDay $holiday) use ($office): bool {
                if (in_array($holiday->scope, [self::SCOPE_NATIONAL, self::SCOPE_DAVAO_ORIENTAL], true)) {
                    return true;
                }

                return $holiday->scope === self::SCOPE_OFFICE
                    && $office !== null
                    && mb_strtolower(trim((string) $holiday->location)) === mb_strtolower(trim($office));
            })
            ->values();
    }
}
