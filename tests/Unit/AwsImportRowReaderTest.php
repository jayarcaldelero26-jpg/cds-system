<?php

use App\Services\AwsImportRowReader;
use Illuminate\Http\UploadedFile;

function awsXlsxFixture(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'aws-xlsx-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $sheetEntries = [];
    $relationshipEntries = [];
    $sharedStrings = [];
    $sharedStringIndexes = [];

    foreach ($sheets as $index => $sheet) {
        $sheetNumber = $index + 1;
        $sheetEntries[] = '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1).'" sheetId="'.$sheetNumber.'" r:id="rId'.$sheetNumber.'"/>';
        $relationshipEntries[] = '<Relationship Id="rId'.$sheetNumber.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';

        $rowsXml = [];
        foreach ($sheet['rows'] as $rowNumber => $row) {
            $cells = [];
            foreach ($row as $column => $value) {
                $reference = $column.($rowNumber + 1);
                if (is_string($value)) {
                    $sharedStringIndexes[$value] ??= count($sharedStrings);
                    if (! isset($sharedStrings[$sharedStringIndexes[$value]])) {
                        $sharedStrings[] = $value;
                    }
                    $cells[] = '<c r="'.$reference.'" t="s"><v>'.$sharedStringIndexes[$value].'</v></c>';
                } elseif ($value !== null) {
                    $cells[] = '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                }
            }
            $rowsXml[] = '<row r="'.($rowNumber + 1).'">'.implode('', $cells).'</row>';
        }

        $zip->addFromString(
            'xl/worksheets/sheet'.$sheetNumber.'.xml',
            '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $rowsXml).'</sheetData></worksheet>'
        );
    }

    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>'
    );
    $zip->addFromString(
        'xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('', $sheetEntries).'</sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $relationshipEntries).'</Relationships>'
    );
    $zip->addFromString(
        'xl/sharedStrings.xml',
        '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($value) => '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>', $sharedStrings)).'</sst>'
    );
    $zip->close();

    return $path;
}

test('xlsx reader finds a bounded header after title rows and preserves the first data row', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive is required for XLSX tests.');
    }

    $path = awsXlsxFixture([
        [
            'name' => 'Metadata',
            'rows' => [
                ['A' => 'Device metadata'],
            ],
        ],
        [
            'name' => 'Config 1',
            'rows' => [
                ['A' => 'Device', 'B' => 'Port 1'],
                ['A' => 'Station', 'B' => 'Weather'],
                ['A' => 'Timestamps', 'B' => 'mm Precipitation', 'C' => 'Wind Speed', 'D' => 'Air Temperature', 'E' => 'Relative Humidity'],
                [],
                ['A' => 46022.75, 'B' => 0, 'C' => 2.5, 'D' => 25.2, 'E' => 92.4],
            ],
        ],
    ]);

    try {
        $file = new UploadedFile($path, 'weather.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $result = app(AwsImportRowReader::class)->read($file);
        expect($result['rows'])->toBeInstanceOf(Generator::class);

        $rows = iterator_to_array($result['rows'], false);
        expect($result['header'][0])->toBe('Timestamps')
            ->and($rows)->toHaveCount(1)
            ->and($rows[0][0])->toBe('2025-12-31 18:00:00')
            ->and($rows[0][1])->toBe(0.0);
    } finally {
        @unlink($path);
    }
});

test('CSV reader streams rows through a generator and preserves representative values', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'aws-csv-');
    file_put_contents($path, "Device metadata\nTimestamps,mm Precipitation,Wind Speed,Air Temperature\n2026-01-01 00:00:00,0,2.5,25.2\n2026-01-01 00:15:00,1.5,3,26\n");

    try {
        $result = app(AwsImportRowReader::class)->read(new UploadedFile($path, 'weather.csv', 'text/csv', null, true));
        expect($result['rows'])->toBeInstanceOf(Generator::class);
        $rows = iterator_to_array($result['rows'], false);
        expect($result['header'][0])->toBe('Timestamps')
            ->and($rows)->toHaveCount(2)
            ->and($rows[0][1])->toBe('0')
            ->and($rows[1][1])->toBe('1.5');
    } finally {
        @unlink($path);
    }
});

test('CSV reader rejects malformed files and closes its input handle after iteration', function (): void {
    $badPath = tempnam(sys_get_temp_dir(), 'aws-csv-');
    file_put_contents($badPath, "not an AWS header\njust text\n");
    expect(fn () => app(AwsImportRowReader::class)->read(new UploadedFile($badPath, 'bad.csv', 'text/csv', null, true)))
        ->toThrow(RuntimeException::class, 'No valid timestamp header');
    @unlink($badPath);

    $path = tempnam(sys_get_temp_dir(), 'aws-csv-');
    file_put_contents($path, "Timestamps,mm Precipitation,Wind Speed,Air Temperature\n2026-01-01 00:00:00,0,2.5,25.2\n");
    try {
        $result = app(AwsImportRowReader::class)->read(new UploadedFile($path, 'weather.csv', 'text/csv', null, true));
        $generator = $result['rows'];
        $generator->rewind();
        expect($generator->current())->toBeArray();
        $generator->next();
        expect($generator->valid())->toBeFalse();
        @unlink($path);
        expect(file_exists($path))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

test('XLSX reader rejects malformed workbook input safely', function (): void {
    if (! class_exists(ZipArchive::class)) $this->markTestSkipped('ZipArchive is required for XLSX tests.');
    $path = tempnam(sys_get_temp_dir(), 'aws-xlsx-');
    file_put_contents($path, 'not a zip workbook');

    try {
        expect(fn () => app(AwsImportRowReader::class)->read(new UploadedFile($path, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)))
            ->toThrow(RuntimeException::class, 'could not be opened');
    } finally {
        @unlink($path);
    }
});

test('CSV reader enforces the documented row byte bound', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'aws-csv-');
    $largeValue = str_repeat('1', 900000);
    file_put_contents($path, "Timestamps,mm Precipitation,Wind Speed,Air Temperature,Relative Humidity,Atmospheric Pressure\n2026-01-01 00:00:00,{$largeValue},{$largeValue},{$largeValue},{$largeValue},{$largeValue}\n");

    try {
        $result = app(AwsImportRowReader::class)->read(new UploadedFile($path, 'large-row.csv', 'text/csv', null, true));
        expect(fn () => iterator_to_array($result['rows'], false))
            ->toThrow(RuntimeException::class, '4 MB row limit');
    } finally {
        @unlink($path);
    }
});

test('CSV reader enforces the documented row and column counts without materializing rows', function (): void {
    $widePath = tempnam(sys_get_temp_dir(), 'aws-wide-csv-');
    $wideHeader = array_merge(['Timestamps', 'mm Precipitation', 'Wind Speed', 'Air Temperature'], array_fill(0, 253, 'extra'));
    file_put_contents($widePath, implode(',', $wideHeader)."\n");

    try {
        expect(fn () => app(AwsImportRowReader::class)->read(new UploadedFile($widePath, 'wide.csv', 'text/csv', null, true)))
            ->toThrow(RuntimeException::class, '256-column limit');
    } finally {
        @unlink($widePath);
    }

    $path = tempnam(sys_get_temp_dir(), 'aws-many-rows-');
    $line = "2026-01-01 00:00:00,1,2,25\n";
    file_put_contents($path, "Timestamps,mm Precipitation,Wind Speed,Air Temperature\n".str_repeat($line, 250001));

    try {
        $result = app(AwsImportRowReader::class)->read(new UploadedFile($path, 'many-rows.csv', 'text/csv', null, true));
        expect(fn () => consumeAwsRows($result['rows']))
            ->toThrow(RuntimeException::class, '250,000 data rows');
    } finally {
        @unlink($path);
    }
});

function consumeAwsRows(iterable $rows): void
{
    foreach ($rows as $_row) {
    }
}

test('xlsx reader rejects workbooks without a valid AWS sensor header', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive is required for XLSX tests.');
    }

    $path = awsXlsxFixture([
        [
            'name' => 'Metadata',
            'rows' => [
                ['A' => 'Timestamps', 'B' => 'Description'],
                ['A' => 46022.75, 'B' => 'Not AWS data'],
            ],
        ],
    ]);

    try {
        $file = new UploadedFile($path, 'invalid.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        expect(fn () => app(AwsImportRowReader::class)->read($file))
            ->toThrow(RuntimeException::class, 'No valid AWS data header was found');
    } finally {
        @unlink($path);
    }
});
