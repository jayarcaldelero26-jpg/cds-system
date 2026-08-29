<?php

namespace App\Console\Commands;

use App\Services\Compliance\ComplianceAlertDeliveryService;
use App\Services\Compliance\OverdueReportService;
use App\Services\Notifications\EdatsInAppNotificationService;
use Illuminate\Console\Command;

class SendOverdueComplianceAlerts extends Command
{
    protected $signature = 'compliance:send-overdue-alerts {--dry-run : Scan and log without sending mail}';
    protected $description = 'Send the daily overdue PA-related report memorandum in compliance-alert safe mode.';

    public function handle(ComplianceAlertDeliveryService $delivery, OverdueReportService $reports, EdatsInAppNotificationService $inAppNotifications): int
    {
        if (! $this->option('dry-run')) {
            $inAppNotifications->syncDeadlineNotifications();
        }
        $overdue = $reports->overdueReports();
        if ($overdue->isEmpty()) {
            $this->info('No overdue reports found. No email will be sent.');

            return self::SUCCESS;
        }

        $this->info("Overdue reports: {$overdue->count()}");
        $this->line($this->option('dry-run') ? 'Mode: dry run (Mail is never called).' : 'Mode: automatic delivery policy.');
        foreach ($delivery->recipientReadiness($overdue) as $group) {
            $destination = $group['recipient']['email'] ?? 'none';
            $this->line(sprintf(
                '%s | %s | %s report(s) | %s | %s',
                $group['protected_area_name'], $group['target_office'], $group['report_count'], strtoupper($group['status']), $destination,
            ));
        }

        $runs = $delivery->sendAutomatic((bool) $this->option('dry-run'));

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
