<?php

namespace App\Console\Commands;

use App\Services\IpafBankBalanceSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncIpafBankBalances extends Command
{
    protected $signature = 'ipaf:sync-bank-balances
                            {--year= : Validate that the source heading matches this reporting year}';
    protected $description = 'Synchronize IPAF Accounting Data from the configured official Google Sheet';

    public function handle(IpafBankBalanceSyncService $syncService): int
    {
        try {
            $yearOption = $this->option('year');
            $requestedYear = $yearOption === null || $yearOption === '' ? null : filter_var($yearOption, FILTER_VALIDATE_INT);
            if ($requestedYear === false) {
                $this->error('The --year option must be a valid reporting year.');
                return self::INVALID;
            }

            $result = $syncService->sync($requestedYear);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line("Source: {$result['source_heading']}");
        $this->line("Source year: {$result['source_year']}");
        $this->table(
            ['Source label', 'Collection Source', 'Total Collection', 'Bank Source', 'Bank Balance', 'Validation'],
            array_map(fn (array $record): array => [
                $record['source_label'],
                $record['total_ipaf_collection_source_reference'],
                $record['total_ipaf_collection'] ?? '—',
                $record['source_reference'],
                $record['bank_balance'] ?? '—',
                $record['validation_error'] ?? 'Valid',
            ], $result['source_records']),
        );
        foreach (['created', 'updated', 'unchanged', 'excluded', 'unmapped', 'invalid', 'failed'] as $category) {
            $labels = $result[$category];
            $this->line(sprintf(
                '%s (%d): %s',
                str_replace('_', ' ', ucfirst($category)),
                count($labels),
                implode(', ', $labels) ?: 'None',
            ));
        }
        $this->info('IPAF Accounting data synchronization completed.');

        return self::SUCCESS;
    }
}
