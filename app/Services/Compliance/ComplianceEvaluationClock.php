<?php

namespace App\Services\Compliance;

use Carbon\CarbonImmutable;

/** Central clock boundary for Compliance eligibility evaluation. */
final class ComplianceEvaluationClock
{
    public const TIMEZONE = 'Asia/Manila';

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)
            ->setTimezone(self::TIMEZONE);
    }

    public function date(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }
}
