<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class IpafAccountingSheetReader
{
    public function read(): array
    {
        $settings = config('ipaf.accounting_sheet');
        $spreadsheetId = trim((string) ($settings['spreadsheet_id'] ?? ''));
        $sheetName = trim((string) ($settings['sheet_name'] ?? ''));

        if ($spreadsheetId === '' || $sheetName !== 'ALL IPAF') {
            throw new RuntimeException('The IPAF Accounting Google Sheet configuration is invalid.');
        }

        $headingRow = $this->readRow($spreadsheetId, $sheetName, (string) $settings['heading_range']);
        $labels = $this->readRow($spreadsheetId, $sheetName, (string) $settings['label_range']);
        $balances = $this->readRow($spreadsheetId, $sheetName, (string) $settings['balance_range']);
        $collections = $this->readRow($spreadsheetId, $sheetName, (string) $settings['collection_range']);
        $totalLabel = $this->readRow($spreadsheetId, $sheetName, (string) $settings['total_label_range']);

        $heading = trim((string) ($headingRow[0] ?? ''));
        [$sourceAsOf, $sourceYear] = $this->parseHeading($heading, (string) $settings['heading_prefix']);

        if (strtoupper(rtrim(trim((string) ($totalLabel[0] ?? '')), ':')) !== 'TOTAL') {
            throw new RuntimeException('The Google Sheet TOTAL row could not be verified.');
        }

        $labels = array_map(fn ($label) => strtoupper(trim((string) $label)), $labels);
        if (count($labels) !== 5 || count($balances) !== 5 || count($collections) !== 5 || in_array('', $labels, true) || count(array_unique($labels)) !== 5) {
            throw new RuntimeException('The Google Sheet PA labels or Accounting values have an unexpected structure.');
        }

        $expectedLabels = array_keys(($settings['mapping'] ?? []) + ($settings['blocked'] ?? []));
        if (array_diff($expectedLabels, $labels) !== []) {
            throw new RuntimeException('One or more verified Google Sheet PA labels are missing.');
        }

        $balanceRow = $this->rangeRowNumber((string) $settings['balance_range']);
        $firstBalanceColumn = $this->rangeStartColumnNumber((string) $settings['balance_range']);
        $collectionRow = $this->rangeRowNumber((string) $settings['collection_range']);
        $firstCollectionColumn = $this->rangeStartColumnNumber((string) $settings['collection_range']);
        $records = [];
        foreach ($labels as $index => $label) {
            $column = $this->columnName($firstBalanceColumn + $index);
            $collectionColumn = $this->columnName($firstCollectionColumn + $index);
            [$balance, $balanceError] = $this->normalizeBalance((string) ($balances[$index] ?? ''), 'Bank Balance');
            [$collection, $collectionError] = $this->normalizeBalance((string) ($collections[$index] ?? ''), 'Total IPAF Collection');
            $validationErrors = array_values(array_filter([$balanceError, $collectionError]));
            $records[] = [
                'source_label' => $label,
                'source_reference' => sprintf('%s!%s%d', $sheetName, $column, $balanceRow),
                'bank_balance_source_reference' => sprintf('%s!%s%d', $sheetName, $column, $balanceRow),
                'total_ipaf_collection_source_reference' => sprintf('%s!%s%d', $sheetName, $collectionColumn, $collectionRow),
                'bank_balance' => $balance,
                'total_ipaf_collection' => $collection,
                'validation_error' => $validationErrors ? implode(' ', $validationErrors) : null,
            ];
        }

        return [
            'source_heading' => $heading,
            'source_as_of' => $sourceAsOf,
            'source_year' => $sourceYear,
            'records' => $records,
        ];
    }

    private function readRow(string $spreadsheetId, string $sheetName, string $range): array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(8)->retry(2, 300)->get(
                "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq",
                ['tqx' => 'out:csv', 'sheet' => $sheetName, 'range' => $range],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException("Google Sheets could not be reached while reading {$range}.", previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Google Sheets returned HTTP {$response->status()} while reading {$range}.");
        }

        $body = trim($response->body());
        if ($body === '' || str_contains(strtolower($body), '<html')) {
            throw new RuntimeException("Google Sheets returned an unreadable response for {$range}.");
        }

        return array_map(fn ($value) => trim((string) $value), str_getcsv($body, ',', '"', '\\'));
    }

    private function parseHeading(string $heading, string $expectedPrefix): array
    {
        $pattern = '/^'.preg_quote($expectedPrefix, '/').'\s+([A-Z]+\s+\d{1,2},\s+\d{4})$/i';
        if (preg_match($pattern, $heading, $matches) !== 1) {
            throw new RuntimeException('The Google Sheet Bank Balance heading or AS OF date is not recognizable.');
        }

        $sourceDate = DateTimeImmutable::createFromFormat('!F j, Y', ucwords(strtolower($matches[1])));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($sourceDate === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new RuntimeException('The Google Sheet Bank Balance AS OF date could not be parsed.');
        }

        return [$sourceDate->format('Y-m-d'), (int) $sourceDate->format('Y')];
    }

    private function normalizeBalance(string $value, string $field): array
    {
        $normalized = str_replace([',', '₱', "\u{00A0}", ' '], '', trim($value));
        if ($normalized === '' || preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1 || ! is_finite((float) $normalized) || (float) $normalized < 0) {
            return [null, "{$field} is blank, non-numeric, or negative."];
        }

        return [bcadd($normalized, '0', 2), null];
    }

    private function rangeRowNumber(string $range): int
    {
        if (preg_match('/^[A-Z]+(\d+):[A-Z]+\d+$/i', $range, $matches) !== 1) {
            throw new RuntimeException('The configured Bank Balance range is invalid.');
        }

        return (int) $matches[1];
    }

    private function rangeStartColumnNumber(string $range): int
    {
        if (preg_match('/^([A-Z]+)\d+:[A-Z]+\d+$/i', $range, $matches) !== 1) {
            throw new RuntimeException('The configured Bank Balance range is invalid.');
        }

        $number = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $number = ($number * 26) + (ord($letter) - 64);
        }

        return $number;
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }
}
