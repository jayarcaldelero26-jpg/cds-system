<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$complianceAlertTime = config('compliance_alerts.send_time', '08:00');
try {
    if (Schema::hasTable('compliance_alert_settings')) {
        $complianceAlertTime = app(\App\Services\Compliance\ComplianceAlertSettingsService::class)->effective()['send_time'];
    }
} catch (\Throwable) {
    // During a first deployment/migration, retain the safe environment fallback.
}

Schedule::command('compliance:send-overdue-alerts')
    ->weekdays()
    ->dailyAt($complianceAlertTime)
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
