<?php

namespace App\Services;

use App\Models\IpafAccountingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IpafBankBalanceSyncService
{
    public function __construct(private readonly IpafAccountingSheetReader $reader) {}

    public function sync(?int $requestedYear = null): array
    {
        $settings = config('ipaf.accounting_sheet');
        $source = $this->reader->read();
        $sourceYear = (int) $source['source_year'];
        $sourceAsOf = (string) $source['source_as_of'];

        if ($requestedYear !== null && $requestedYear !== $sourceYear) {
            $displayDate = CarbonImmutable::parse($sourceAsOf)->format('M j, Y');
            throw new InvalidArgumentException("Google Sheet Bank Balances are currently dated {$displayDate}. Select Reporting Year {$sourceYear} to synchronize.");
        }

        $mapping = $settings['mapping'] ?? [];
        $excluded = $settings['excluded'] ?? [];
        $result = [
            'created' => [],
            'updated' => [],
            'unchanged' => [],
            'excluded' => array_keys($excluded),
            'unmapped' => [],
            'invalid' => [],
            'failed' => [],
            'source_heading' => $source['source_heading'],
            'source_as_of' => $sourceAsOf,
            'source_year' => $sourceYear,
            'source_records' => $source['records'],
        ];

        DB::transaction(function () use ($source, $mapping, $excluded, $sourceYear, $sourceAsOf, &$result): void {
            foreach ($source['records'] as $sourceRecord) {
                $label = $sourceRecord['source_label'];

                if (array_key_exists($label, $excluded)) {
                    $result['excluded'][] = $label;
                    continue;
                }

                if (! array_key_exists($label, $mapping)) {
                    if (! in_array($label, $result['unmapped'], true)) {
                        $result['unmapped'][] = $label;
                    }
                    continue;
                }

                if ($sourceRecord['bank_balance'] === null || $sourceRecord['total_ipaf_collection'] === null) {
                    $result['invalid'][] = $label;
                    continue;
                }

                $record = IpafAccountingStatus::query()
                    ->where('protected_area_id', $mapping[$label])
                    ->where('reporting_year', $sourceYear)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    $record = new IpafAccountingStatus([
                        'protected_area_id' => $mapping[$label],
                        'reporting_year' => $sourceYear,
                        'status_note' => null,
                    ]);
                    $record->forceFill([
                        'total_ipaf_collection' => $sourceRecord['total_ipaf_collection'],
                        'bank_balance' => $sourceRecord['bank_balance'],
                        'accounting_data_source' => 'Google Sheets',
                        'total_ipaf_collection_source_reference' => $sourceRecord['total_ipaf_collection_source_reference'],
                        'bank_balance_source' => 'Google Sheets',
                        'bank_balance_source_reference' => $sourceRecord['source_reference'],
                        'bank_balance_source_as_of' => $sourceAsOf,
                        'bank_balance_synced_at' => now(),
                        'bank_balance_sync_status' => 'synced',
                    ])->save();
                    $result['created'][] = $label;
                    continue;
                }

                $balanceChanged = $record->bank_balance === null
                    || bccomp((string) $record->bank_balance, $sourceRecord['bank_balance'], 2) !== 0;
                $collectionChanged = $record->total_ipaf_collection === null
                    || bccomp((string) $record->total_ipaf_collection, $sourceRecord['total_ipaf_collection'], 2) !== 0;
                $metadataChanged = $record->accounting_data_source !== 'Google Sheets'
                    || $record->total_ipaf_collection_source_reference !== $sourceRecord['total_ipaf_collection_source_reference']
                    || $record->bank_balance_source_reference !== $sourceRecord['source_reference']
                    || $record->bank_balance_source_as_of?->toDateString() !== $sourceAsOf
                    || $record->bank_balance_sync_status !== 'synced';

                if ($balanceChanged || $collectionChanged || $metadataChanged) {
                    $record->forceFill([
                        'total_ipaf_collection' => $sourceRecord['total_ipaf_collection'],
                        'bank_balance' => $sourceRecord['bank_balance'],
                        'accounting_data_source' => 'Google Sheets',
                        'total_ipaf_collection_source_reference' => $sourceRecord['total_ipaf_collection_source_reference'],
                        'bank_balance_source' => 'Google Sheets',
                        'bank_balance_source_reference' => $sourceRecord['source_reference'],
                        'bank_balance_source_as_of' => $sourceAsOf,
                        'bank_balance_synced_at' => now(),
                        'bank_balance_sync_status' => 'synced',
                    ])->save();
                    $result['updated'][] = $label;
                    continue;
                }

                $result['unchanged'][] = $label;
            }
        });

        return $result;
    }
}
