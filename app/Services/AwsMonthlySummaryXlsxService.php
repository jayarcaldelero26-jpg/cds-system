<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class AwsMonthlySummaryXlsxService
{
    /** @param iterable<array<string, mixed>> $rows */
    public function download(iterable $rows, string $periodLabel, string $filename): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'The XLSX export extension is unavailable.');

        $path = tempnam(storage_path('app'), 'aws-monthly-');
        $zip = new ZipArchive();
        abort_unless($path !== false && $zip->open($path, ZipArchive::OVERWRITE) === true, 500, 'The XLSX workbook could not be created.');

        $rows = array_values(is_array($rows) ? $rows : iterator_to_array($rows));
        $degree = "\u{00B0}";
        $headers = [
            'Protected Area', 'Reporting Period', 'Average Atmospheric Pressure (kPa)',
            'Average Air Temperature ('.$degree.'C)', 'Average Vapor Pressure Deficit (kPa)',
            'Average Relative Humidity (%)', 'Mean Wind Direction ('.$degree.')',
            'Total Precipitation (mm)', 'Average Wind Speed (m/s)', 'Remarks',
        ];
        $sheetRows = [
            $this->row(1, ['Automated Weather Station (AWS) Monitoring Summary'], [1]),
            $this->row(2, ['Reporting Period: '.$periodLabel], [1]),
            $this->row(3, []),
            $this->row(4, $headers, array_fill(0, count($headers), 1)),
        ];
        foreach ($rows as $index => $summary) $sheetRows[] = $this->summaryRow($index + 5, $summary);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/core.xml', $this->coreProperties($periodLabel));
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($sheetRows, max(4, count($rows) + 4)));
        $zip->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function row(int $number, array $values, array $styles = []): array { return [$number, $values, $styles]; }

    private function summaryRow(int $number, array $summary): array
    {
        return $this->row($number, [
            $summary['protected_area_name'], $summary['period'], $summary['average_atmospheric_pressure'],
            $summary['average_air_temperature'], $summary['average_vapor_pressure_deficit'],
            $summary['average_relative_humidity'], $summary['mean_wind_direction'], $summary['total_precipitation'],
            $summary['average_wind_speed'], $summary['remarks'],
        ], [0, 0, 2, 2, 2, 2, 2, 2, 0]);
    }

    private function worksheet(array $rows, int $lastRow): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ([22, 18, 22, 20, 25, 21, 19, 20, 28] as $column => $width) $xml .= '<col min="'.($column + 1).'" max="'.($column + 1).'" width="'.$width.'" customWidth="1"/>';
        $xml .= '</cols><sheetData>';
        foreach ($rows as [$number, $values, $styles]) {
            $xml .= '<row r="'.$number.'">';
            foreach (array_values($values) as $column => $value) {
                $cell = $this->cell($column + 1, $number, $value, $styles[$column] ?? 0);
                if ($cell !== '') $xml .= $cell;
            }
            $xml .= '</row>';
        }
        return $xml.'</sheetData><autoFilter ref="A4:I'.$lastRow.'"/><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/></worksheet>';
    }

    private function cell(int $column, int $row, mixed $value, int $style): string
    {
        if ($value === null || $value === '') return '';
        $reference = $this->columnName($column).$row;
        if (is_int($value) || is_float($value)) return '<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.(is_finite((float) $value) ? (string) $value : '0').'</v></c>';
        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t>'.$this->xml((string) $value).'</t></is></c>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) { $remainder = ($number - 1) % 26; $name = chr(65 + $remainder).$name; $number = intdiv($number - 1, 26); }
        return $name;
    }

    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="AWS Monitoring Summary" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="0.00"/><numFmt numFmtId="165" formatCode="0.0"/></numFmts><fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="166534"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs></styleSheet>';
    }

    private function coreProperties(string $periodLabel): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>AWS Monitoring Summary - '.$this->xml($periodLabel).'</dc:title><dc:creator>eDATS CDS</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:created></cp:coreProperties>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>eDATS CDS</Application></Properties>';
    }
}
