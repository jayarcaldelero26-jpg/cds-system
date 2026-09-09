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

        expect($result['header'][0])->toBe('Timestamps')
            ->and($result['rows'])->toHaveCount(1)
            ->and($result['rows'][0][0])->toBe('2025-12-31 18:00:00')
            ->and($result['rows'][0][1])->toBe(0.0);
    } finally {
        @unlink($path);
    }
});

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
