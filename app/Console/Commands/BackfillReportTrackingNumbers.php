<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportTrackingNumberService;
use Illuminate\Console\Command;

class BackfillReportTrackingNumbers extends Command
{
    protected $signature = 'reports:backfill-tracking-numbers {--dry-run : Report missing references without creating them}';
    protected $description = 'Assign stable tracking numbers to existing active report submissions.';

    public function handle(ReportTrackingNumberService $tracking): int
    {
        $counts = $tracking->backfill((bool) $this->option('dry-run'));
        $this->info(sprintf('%s %d records; %d %s; %d skipped.', $this->option('dry-run') ? 'Would scan' : 'Scanned', $counts['scanned'], $counts['created'], $this->option('dry-run') ? 'missing references' : 'references created', $counts['skipped']));
        return self::SUCCESS;
    }
}
