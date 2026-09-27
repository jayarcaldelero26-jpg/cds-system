<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

final class AwsImportRowReader
{
    private const MAX_ROWS = 250000;
    private const MAX_COLUMNS = 256;
    private const MAX_CELL_BYTES = 1048576;
    private const MAX_ROW_BYTES = 4194304;
    private const MAX_XLSX_EXPANDED_BYTES = 262144000;
    private const MAX_SHARED_STRINGS_BYTES = 16777216;

    /** @return array{header: array<int, string>, rows: iterable<array<int, mixed>>} */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            default => throw new RuntimeException('Only .xlsx and .csv files are supported.'),
        };
    }

    /** @return array{header: array<int, string>, rows: iterable<array<int, mixed>>} */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! is_resource($handle)) throw new RuntimeException('The uploaded AWS file could not be read.');

        $header = null;
        $headerRow = 0;
        try {
            for ($rowNumber = 1; $rowNumber <= 10 && ($row = fgetcsv($handle)) !== false; $rowNumber++) {
                $this->assertDimensions($row, $rowNumber);
                $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $row);
                if ($this->hasTimestampHeader($normalized)) {
                    $header = array_map(fn ($value) => (string) $value, $row);
                    $headerRow = $rowNumber;
                    break;
                }
            }

            if ($header === null) throw new RuntimeException('No valid timestamp header was found inside the AWS file.');
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }

        $rows = (static function () use ($handle, $headerRow): \Generator {
            $count = 0;
            $rowNumber = $headerRow;
            try {
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    self::assertDimensions($row, $rowNumber);
                    if (! self::hasData($row)) continue;
                    if (++$count > self::MAX_ROWS) throw new RuntimeException('The AWS file exceeds the maximum of 250,000 data rows.');
                    yield $row;
                }
            } finally {
                if (is_resource($handle)) fclose($handle);
            }

            if ($count === 0) throw new RuntimeException('No data rows were found inside the AWS file.');
        })();

        return ['header' => $header, 'rows' => $rows];
    }

    /** @return array{header: array<int, string>, rows: iterable<array<int, mixed>>} */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(XMLReader::class)) {
            throw new RuntimeException('XLSX import requires the PHP ZipArchive and XMLReader extensions.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('The XLSX workbook could not be opened.');

        try {
            $this->assertExpandedSize($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $styles = $this->dateStyles($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml', 2097152);
            $relationships = $this->relationshipTargets($zip);
            $worksheets = [];
            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                $relationshipId = (string) $sheet->attributes('r', true)->id;
                $target = $relationships[$relationshipId] ?? null;
                if ($target !== null) $worksheets[] = $target;
                if (count($worksheets) > 50) throw new RuntimeException('The XLSX workbook exceeds the 50 worksheet limit.');
            }
        } finally {
            $zip->close();
        }

        foreach ($worksheets as $target) {
            $rowIndex = 0;
            foreach ($this->worksheetRows($path, $target, 50, $sharedStrings, $styles) as $row) {
                $normalizedHeader = array_map(fn ($value) => $this->normalizeHeader((string) $value), $row);
                if ($this->isAwsHeader($normalizedHeader)) {
                    $timestampIndex = $this->timestampIndex($normalizedHeader);
                    $header = array_map(fn ($value) => (string) $value, $row);
                    return [
                        'header' => $header,
                        'rows' => $this->xlsxDataRows($path, $target, $rowIndex, $timestampIndex, $sharedStrings, $styles),
                    ];
                }
                $rowIndex++;
            }
        }

        throw new RuntimeException('No valid AWS data header was found. Expected a date/time column and AWS sensor columns.');
    }

    /** @return iterable<array<int, mixed>> */
    private function xlsxDataRows(string $path, string $target, int $headerRow, int $timestampIndex, array $sharedStrings, array $dateStyles): iterable
    {
        $count = 0;
        $rowIndex = 0;
        foreach ($this->worksheetRows($path, $target, null, $sharedStrings, $dateStyles) as $row) {
            if ($rowIndex++ <= $headerRow || ! self::hasData($row)) continue;
            if (++$count > self::MAX_ROWS) throw new RuntimeException('The AWS workbook exceeds the maximum of 250,000 data rows.');
            if (isset($row[$timestampIndex]) && is_numeric($row[$timestampIndex])) {
                $row[$timestampIndex] = $this->excelDate((float) $row[$timestampIndex]);
            }
            yield $row;
        }

        if ($count === 0) throw new RuntimeException('No data rows were found inside the AWS file.');
    }

    /** @return iterable<array<int, mixed>> */
    private function worksheetRows(string $path, string $target, ?int $maximumRows, array $sharedStrings, array $dateStyles): iterable
    {
        $uri = 'zip://'.str_replace('\\', '/', $path).'#'.$target;
        $reader = new XMLReader();
        if (! $reader->open($uri, null, LIBXML_NONET)) throw new RuntimeException('An XLSX worksheet could not be streamed.');

        $rows = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                if (++$rows > ($maximumRows ?? self::MAX_ROWS + 50)) {
                    if ($maximumRows !== null) return;
                    throw new RuntimeException('The XLSX worksheet exceeds the maximum of 250,000 data rows.');
                }

                $rowDepth = $reader->depth;
                $values = [];
                while ($reader->read()) {
                    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $reader->depth === $rowDepth) break;
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c' || $reader->depth !== $rowDepth + 1) continue;

                    $column = $this->columnNumber((string) $reader->getAttribute('r'));
                    if ($column >= self::MAX_COLUMNS) throw new RuntimeException('The AWS workbook exceeds the 256-column limit.');
                    $type = (string) $reader->getAttribute('t');
                    $style = (int) ($reader->getAttribute('s') ?? -1);
                    $cellDepth = $reader->depth;
                    $raw = '';
                    $inline = '';
                    if (! $reader->isEmptyElement) {
                        while ($reader->read()) {
                            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $cellDepth) break;
                            if ($reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true)) {
                                $targetName = $reader->localName;
                                while ($reader->read()) {
                                    if (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                                        $text = $reader->value;
                                        if (strlen($raw) + strlen($text) > self::MAX_CELL_BYTES) throw new RuntimeException('An AWS workbook cell exceeds the 1 MB cell limit.');
                                        if ($targetName === 't') $inline .= $text;
                                        else $raw .= $text;
                                    }
                                    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === $targetName) break;
                                }
                            }
                        }
                    }

                    $values[$column] = $this->xlsxCellValue($type, $raw, $inline, $style, $sharedStrings, $dateStyles);
                }

                self::assertDimensions($values, $rows);

                if ($values !== []) {
                    ksort($values);
                    yield array_replace(array_fill(0, max(array_keys($values)) + 1, null), $values);
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function xlsxCellValue(string $type, string $raw, string $inline, int $style, array $sharedStrings, array $dateStyles): mixed
    {
        if ($type === 's') return $sharedStrings[(int) $raw] ?? '';
        if ($type === 'inlineStr') return $inline;
        if ($raw === '') return null;
        if ($type === 'b') return $raw === '1';
        if (is_numeric($raw) && ! empty($dateStyles[$style])) return $this->excelDate((float) $raw);

        return is_numeric($raw) ? (float) $raw : $raw;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $this->entryContents($zip, 'xl/sharedStrings.xml', self::MAX_SHARED_STRINGS_BYTES, required: false);
        if ($contents === null) return [];

        $reader = new XMLReader();
        if (! $reader->XML($contents, null, LIBXML_NONET)) throw new RuntimeException('The XLSX shared-string data is malformed.');
        $values = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
                $xml = simplexml_load_string($reader->readOuterXml(), SimpleXMLElement::class, LIBXML_NONET);
                if ($xml === false) throw new RuntimeException('The XLSX shared-string data is malformed.');
                $text = '';
                foreach ($xml->xpath('.//*[local-name()="t"]') ?: [] as $part) $text .= (string) $part;
                if (strlen($text) > self::MAX_CELL_BYTES) throw new RuntimeException('An AWS workbook cell exceeds the 1 MB cell limit.');
                $values[] = $text;
            }
        } finally {
            $reader->close();
        }

        return $values;
    }

    /** @return array<int, bool> */
    private function dateStyles(ZipArchive $zip): array
    {
        $contents = $this->entryContents($zip, 'xl/styles.xml', 5242880, required: false);
        if ($contents === null) return [];
        $xml = $this->parseXml($contents);
        $customFormats = [];
        foreach ($xml->numFmts->numFmt ?? [] as $format) $customFormats[(int) $format['numFmtId']] = strtolower((string) $format['formatCode']);

        $dateStyles = [];
        foreach ($xml->cellXfs->xf ?? [] as $index => $format) {
            $formatId = (int) $format['numFmtId'];
            $dateStyles[(int) $index] = in_array($formatId, range(14, 22), true)
                || (bool) preg_match('/(^|[^a-z])(yy|mm|dd|hh|ss)([^a-z]|$)/', $customFormats[$formatId] ?? '');
        }

        return $dateStyles;
    }

    private function relationshipTargets(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/_rels/workbook.xml.rels', 5242880);
        $targets = [];
        foreach ($xml->Relationship ?? [] as $relationship) {
            $id = (string) $relationship['Id'];
            $target = ltrim((string) $relationship['Target'], '/');
            $target = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            if (str_contains($target, '..') || ! str_starts_with($target, 'xl/worksheets/')) continue;
            $targets[$id] = $target;
        }

        return $targets;
    }

    private function xml(ZipArchive $zip, string $path, int $limit): SimpleXMLElement
    {
        $contents = $this->entryContents($zip, $path, $limit);
        return $this->parseXml($contents);
    }

    private function entryContents(ZipArchive $zip, string $path, int $limit, bool $required = true): ?string
    {
        $index = $zip->locateName($path);
        if ($index === false) {
            if ($required) throw new RuntimeException("The XLSX workbook is missing {$path}.");
            return null;
        }
        $stat = $zip->statIndex($index);
        if (($stat['size'] ?? $limit + 1) > $limit) throw new RuntimeException('The XLSX workbook contains an oversized metadata section.');
        $contents = $zip->getFromIndex($index);
        if ($contents === false) throw new RuntimeException('The XLSX workbook could not be read.');

        return $contents;
    }

    private function assertExpandedSize(ZipArchive $zip): void
    {
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_XLSX_EXPANDED_BYTES) throw new RuntimeException('The XLSX workbook exceeds the 250 MB expanded-size limit.');
        }
    }

    private function parseXml(string $contents): SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        if ($xml === false) throw new RuntimeException('The XLSX workbook contains malformed XML.');

        return $xml;
    }

    private function timestampIndex(array $header): int
    {
        foreach ($header as $index => $value) {
            if (in_array($value, ['timestamps', 'timestamp', 'time stamp', 'date time', 'datetime', 'date and time', 'record time', 'sample time'], true)) return $index;
        }

        return 0;
    }

    private function hasTimestampHeader(array $header): bool
    {
        return count(array_intersect(['timestamps', 'timestamp', 'time stamp', 'date time', 'datetime', 'date and time', 'record time', 'sample time'], $header)) > 0;
    }

    private function isAwsHeader(array $header): bool
    {
        if (! $this->hasTimestampHeader($header)) return false;
        $sensorGroups = [['precipitation', 'precip'], ['wind direction'], ['wind speed'], ['air temperature'], ['relative humidity'], ['atmospheric pressure']];
        $recognizedSensors = 0;
        foreach ($sensorGroups as $phrases) {
            foreach ($header as $cell) {
                foreach ($phrases as $phrase) {
                    if (str_contains($cell, $phrase)) { $recognizedSensors++; break 2; }
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

    private function columnNumber(string $reference): int
    {
        preg_match('/([A-Z]+)/i', $reference, $match);
        $number = 0;
        foreach (str_split(strtoupper($match[1] ?? '')) as $letter) $number = ($number * 26) + ord($letter) - 64;
        if ($number <= 0) throw new RuntimeException('The XLSX workbook contains an invalid cell reference.');
        return $number - 1;
    }

    private function excelDate(float $serial): string
    {
        $base = new \DateTimeImmutable('1899-12-30 00:00:00');
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        return $base->modify("+{$days} days")->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    private static function assertDimensions(array $row, int $rowNumber): void
    {
        if (count($row) > self::MAX_COLUMNS) throw new RuntimeException('The AWS CSV exceeds the 256-column limit.');
        $rowBytes = 0;
        foreach ($row as $cell) {
            if (! is_string($cell)) continue;
            $cellBytes = strlen($cell);
            if ($cellBytes > self::MAX_CELL_BYTES) throw new RuntimeException('An AWS file cell exceeds the 1 MB cell limit.');
            $rowBytes += $cellBytes;
        }
        if ($rowBytes > self::MAX_ROW_BYTES) throw new RuntimeException('An AWS file row exceeds the 4 MB row limit.');
    }

    private static function hasData(array $row): bool
    {
        return count(array_filter($row, fn ($value) => $value !== null && trim((string) $value) !== '')) > 0;
    }
}
