<?php

namespace App\Services\Compliance;

use Carbon\CarbonImmutable;

final class ComplianceAlertSchedule
{
    public static function nextWeekdayRun(CarbonImmutable $now, string $sendTime): CarbonImmutable
    {
        $timezone = ComplianceAlertSettingsService::TIMEZONE;
        $localNow = $now->setTimezone($timezone);
        $nextRun = $localNow->setTimeFromTimeString($sendTime);

        if ($nextRun->lessThanOrEqualTo($localNow)) {
            $nextRun = $nextRun->addDay()->setTimeFromTimeString($sendTime);
        }

        while ($nextRun->isWeekend()) {
            $nextRun = $nextRun->addDay()->setTimeFromTimeString($sendTime);
        }

        return $nextRun;
    }
}
