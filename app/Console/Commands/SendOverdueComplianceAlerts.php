<?php

namespace App\Console\Commands;

use App\Models\ComplianceNotificationRun;
use App\Services\Compliance\ComplianceAlertDeliveryService;
use App\Services\Notifications\EdatsInAppNotificationService;
use Illuminate\Console\Command;

class SendOverdueComplianceAlerts extends Command
{
    protected $signature = 'compliance:send-overdue-alerts';
    protected $description = 'Run the configured automated Compliance Alert delivery flow.';

    public function handle(ComplianceAlertDeliveryService $delivery, EdatsInAppNotificationService $inAppNotifications): int
    {
        $inAppNotifications->syncDeadlineNotifications();
        $buckets = $delivery->currentAlertBuckets();
        $currentAlerts = collect($buckets)->flatten(1)->values();
        if ($currentAlerts->isEmpty()) {
            $this->info('No due-soon, due-today, or overdue reports found. No email will be sent.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Current alert reports: %d (due soon: %d, due today: %d, overdue: %d)',
            $currentAlerts->count(),
            $buckets[ComplianceNotificationRun::ALERT_DUE_SOON]->count(),
            $buckets[ComplianceNotificationRun::ALERT_DUE_TODAY]->count(),
            $buckets[ComplianceNotificationRun::ALERT_OVERDUE]->count(),
        ));
        $this->line('Mode: automatic production delivery policy.');
        foreach ($delivery->recipientReadiness($currentAlerts) as $group) {
            $destination = $group['recipient']['email'] ?? 'none';
            $this->line(sprintf(
                '%s | %s | %s report(s) | %s | %s',
                $group['protected_area_name'], $group['target_office'], $group['report_count'], strtoupper($group['status']), $destination,
            ));
        }

        $runs = $delivery->sendAutomatic();

        if ($runs->isEmpty()) {
            $this->info('No overdue reports require a new automatic notification today (already sent or no eligible delivery).');
            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $destination = implode(', ', $run->recipients ?? []) ?: 'unmapped';
            $reason = $run->error_message ? " {$run->error_message}" : '';
            $this->line("Run #{$run->id}: {$run->run_type}; {$run->status}; {$run->report_count} report(s); {$destination}.{$reason}");
        }

        return $runs->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
    }
}
