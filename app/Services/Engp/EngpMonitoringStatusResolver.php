<?php

namespace App\Services\Engp;

use App\Services\Compliance\ComplianceEvaluationClock;
use Carbon\CarbonImmutable;

/**
 * Presentation-only ENGP monitoring status.
 *
 * Deadline calculation and alert eligibility remain owned by the registry and
 * compliance services. This resolver only gives scheduled and actual rows the
 * same readable workflow status vocabulary.
 */
final class EngpMonitoringStatusResolver
{
    public const SUBMITTED = 'Report Submitted';
    public const WITHIN_PREPARATION = 'Within Allowable Preparation Period';
    public const ONGOING_PREPARATION = 'Ongoing Preparation at CENRO Level';
    public const NOT_YET_SUBMITTED = 'Report Not Yet Submitted';

    public function __construct(private readonly ComplianceEvaluationClock $clock) {}

    /** @return array{key:string,label:string,submitted:bool,within_preparation:bool,ongoing_preparation:bool,not_yet_submitted:bool} */
    public function resolve(?string $deadline, ?string $received, ?CarbonImmutable $today = null): array
    {
        if (filled($received)) {
            return $this->result('submitted', self::SUBMITTED);
        }

        $today ??= $this->clock->date();
        $deadlineDate = filled($deadline)
            ? CarbonImmutable::parse($deadline, ComplianceEvaluationClock::TIMEZONE)->startOfDay()
            : null;

        if ($deadlineDate?->lessThan($today)) {
            return $this->result('not_yet_submitted', self::NOT_YET_SUBMITTED);
        }

        $reminderThrough = $today->addDays(max(0, (int) config('notifications.due_soon_days', 3)));
        if ($deadlineDate !== null && $deadlineDate->lessThanOrEqualTo($reminderThrough)) {
            return $this->result('ongoing_preparation', self::ONGOING_PREPARATION);
        }

        return $this->result('within_preparation', self::WITHIN_PREPARATION);
    }

    /** @return array{key:string,label:string,submitted:bool,within_preparation:bool,ongoing_preparation:bool,not_yet_submitted:bool} */
    private function result(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'submitted' => $key === 'submitted',
            'within_preparation' => $key === 'within_preparation',
            'ongoing_preparation' => $key === 'ongoing_preparation',
            'not_yet_submitted' => $key === 'not_yet_submitted',
        ];
    }
}
