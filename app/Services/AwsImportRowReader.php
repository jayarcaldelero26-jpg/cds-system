<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class AwsImportRowReader
{
    /**
     * @return array{header: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            default => throw new RuntimeException('Only .xlsx and .csv files are supported.'),
        };
    }

    /** @return array{header: array<int, string>, rows: array<int, array<int, mixed>>} */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! is_resource($handle)) {
            throw new RuntimeException('The uploaded AWS file could not be read.');
        }

        $header = null;
        $rows = [];
        $currentRow = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $currentRow++;
            $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $row);

            if ($this->hasTimestampHeader($normalized)) {
                $header = $row;
                break;
            }

            if ($currentRow > 10) {
                break;
            }
        }

        if ($header !== null) {
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $this->validateRows($header, $rows);
    }

    /** @return array{header: array<int, string>, rows: array<int, array<int, mixed>>} */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX import is unavailable because the PHP ZipArchive extension is not enabled.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The XLSX workbook could not be opened.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $styles = $this->dateStyles($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relationships = $this->relationshipTargets($zip);
            $sheetNames = [];

            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                $sheetName = (string) $sheet['name'];
                $sheetNames[] = $sheetName;
                $relationshipId = (string) $sheet->attributes('r', true)->id;
                $target = $relationships[$relationshipId] ?? null;
                if ($target === null) {
                    continue;
                }

                $rows = $this->worksheetRows($zip, $target, $sharedStrings, $styles);
                foreach (array_slice($rows, 0, 50) as $headerIndex => $header) {
                    $normalizedHeader = array_map(fn ($value) => $this->normalizeHeader((string) $value), $header);

                    if ($this->isAwsHeader($normalizedHeader)) {
                        $timestampIndex = array_search(true, array_map(
                            fn ($value) => in_array($value, [
                                'timestamps',
                                'timestamp',
                                'time stamp',
                                'date time',
                                'datetime',
                                'date and time',
                                'record time',
                                'sample time',
                            ], true),
                            $normalizedHeader
                        ), true);
                        $dataRows = array_slice($rows, $headerIndex + 1);

                        if ($timestampIndex !== false) {
                            foreach ($dataRows as &$dataRow) {
                                if (isset($dataRow[$timestampIndex]) && is_numeric($dataRow[$timestampIndex])) {
                                    $dataRow[$timestampIndex] = $this->excelDate((float) $dataRow[$timestampIndex]);
                                }
                            }
                            unset($dataRow);
                        }

                        return $this->validateRows($header, $dataRows);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        $sheetList = $sheetNames === [] ? '' : ' Checked: '.implode(', ', $sheetNames).'.';
        throw new RuntimeException('No valid AWS data header was found. Expected a date/time column and AWS sensor columns.'.$sheetList);
    }

    /** @return array<string, string> */
    private function relationshipTargets(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $targets = [];

        foreach ($xml->Relationship ?? [] as $relationship) {
            $id = (string) $relationship['Id'];
            $target = ltrim((string) $relationship['Target'], '/');
            $targets[$id] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return $targets;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');
        if ($contents === false) {
            return [];
        }

        $xml = $this->parseXml($contents);
        $values = [];

        foreach ($xml->si ?? [] as $item) {
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }
            $values[] = $text;
        }

        return $values;
    }

    /** @return array<int, bool> */
    private function dateStyles(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/styles.xml');
        if ($contents === false) {
            return [];
        }

        $xml = $this->parseXml($contents);
        $customFormats = [];
        foreach ($xml->numFmts->numFmt ?? [] as $format) {
            $customFormats[(int) $format['numFmtId']] = strtolower((string) $format['formatCode']);
        }

        $dateStyles = [];
        $builtInDateFormats = range(14, 22);
        foreach ($xml->cellXfs->xf ?? [] as $index => $format) {
            $formatId = (int) $format['numFmtId'];
            $formatCode = $customFormats[$formatId] ?? '';
            $dateStyles[(int) $index] = in_array($formatId, $builtInDateFormats, true)
                || (bool) preg_match('/(^|[^a-z])(yy|mm|dd|hh|ss)([^a-z]|$)/', $formatCode);
        }

        return $dateStyles;
    }

    /** @return array<int, array<int, mixed>> */
    private function worksheetRows(ZipArchive $zip, string $target, array $sharedStrings, array $dateStyles): array
    {
        $xml = $this->xml($zip, $target);
        $rows = [];

        foreach ($xml->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/([A-Z]+)/', $reference, $match);
                $column = $this->columnNumber($match[1] ?? '') ;
                $values[$column] = $this->cellValue($cell, $sharedStrings, $dateStyles);
            }

            if ($values !== []) {
                ksort($values);
                $lastColumn = max(array_keys($values));
                $rows[] = array_replace(array_fill(0, $lastColumn + 1, null), $values);
            }
        }

        return $rows;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings, array $dateStyles): mixed
    {
        $type = (string) ($cell['t'] ?? '');
        $value = (string) ($cell->v ?? '');

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        if ($type === 'inlineStr') {
            return implode('', array_map('strval', $cell->is->t ?? []));
        }

        if ($value === '') {
            return null;
        }

        if ($type === 'b') {
            return $value === '1';
        }

        if (is_numeric($value) && ! empty($dateStyles[(int) ($cell['s'] ?? -1)])) {
            return $this->excelDate((float) $value);
        }

        return is_numeric($value) ? (float) $value : $value;
    }

    private function excelDate(float $serial): string
    {
        $base = new \DateTimeImmutable('1899-12-30 00:00:00');
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        return $base->modify("+{$days} days")->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return max(0, $number - 1);
    }

    private function hasTimestampHeader(array $header): bool
    {
        return count(array_intersect([
            'timestamps',
            'timestamp',
            'time stamp',
            'date time',
            'datetime',
            'date and time',
            'record time',
            'sample time',
        ], $header)) > 0;
    }

    private function isAwsHeader(array $header): bool
    {
        if (! $this->hasTimestampHeader($header)) {
            return false;
        }

        $sensorGroups = [
            ['precipitation', 'precip'],
            ['wind direction'],
            ['wind speed'],
            ['air temperature'],
            ['relative humidity'],
            ['atmospheric pressure'],
        ];

        $recognizedSensors = 0;
        foreach ($sensorGroups as $phrases) {
            foreach ($header as $cell) {
                foreach ($phrases as $phrase) {
                    if (str_contains($cell, $phrase)) {
                        $recognizedSensors++;
                        break 2;
                    }
                }
            }
        }

        return $recognizedSensors >= 3;
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim(str_replace("\xEF\xBB\xBF", '', $value)));
        $value = str_replace(['&', '/', '\\', '-', '_'], ' ', $value);
        $value = preg_replace('/\band\b/', 'and', $value) ?? $value;

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /** @return array{header: array<int, string>, rows: array<int, array<int, mixed>>} */
    private function validateRows(?array $header, array $rows): array
    {
        if ($header === null) {
            throw new RuntimeException('No valid timestamp header found inside the AWS file.');
        }

        $header = array_map(fn ($value) => (string) $value, $header);
        $rows = array_values(array_filter($rows, fn ($row) => count(array_filter($row, fn ($value) => $value !== null && trim((string) $value) !== '')) > 0));

        if ($rows === []) {
            throw new RuntimeException('No data rows were found inside the AWS file.');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $contents = $zip->getFromName($path);
        if ($contents === false) {
            throw new RuntimeException("The XLSX workbook is missing {$path}.");
        }

        return $this->parseXml($contents);
    }

    private function parseXml(string $contents): SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();

        if ($xml === false) {
            throw new RuntimeException('The XLSX workbook contains malformed XML.');
        }

        return $xml;
    }
}
