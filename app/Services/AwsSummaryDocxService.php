<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class AwsSummaryDocxService
{
    /** @param iterable<array<string, mixed>> $rows */
    public function download(iterable $rows, string $mode, string $periodLabel, string $filename): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'The DOCX export extension is unavailable.');

        $path = tempnam(storage_path('app'), 'aws-docx-');
        $zip = new ZipArchive();
        abort_unless($path !== false && $zip->open($path, ZipArchive::OVERWRITE) === true, 500, 'The DOCX document could not be created.');

        $rows = array_values(is_array($rows) ? $rows : iterator_to_array($rows));
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('docProps/core.xml', $this->coreProperties($periodLabel));
        $zip->addFromString('docProps/app.xml', $this->appProperties());
        $zip->addFromString('word/document.xml', $this->document($rows, $mode, $periodLabel));
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->addFromString('word/settings.xml', $this->settings());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRelationships());
        $zip->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function document(array $rows, string $mode, string $periodLabel): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        $xml .= $this->paragraph('AUTOMATED WEATHER STATION (AWS)', 'Title');
        $subtitle = $mode === 'one_month' ? 'Daily Monitoring Summary | '.$periodLabel : ($mode === 'custom_range' || $mode === 'month' ? 'Monthly Monitoring Summary | '.$periodLabel : ($mode === 'day' ? 'Daily Monitoring Summary' : 'Monitoring Summary'));
        $xml .= $this->paragraph($subtitle, 'Subtitle');
        if ($mode === 'day') $xml .= $this->paragraph('Reporting Date: '.$periodLabel, 'Meta');
        if ($mode === 'range') $xml .= $this->paragraph('Reporting Period: '.$periodLabel, 'Meta');

        if (in_array($mode, ['one_month', 'custom_range', 'month'], true)) {
            $groups = collect($rows)->groupBy('protected_area_id')->values();
            foreach ($groups as $index => $group) {
                $xml .= $this->paragraph((string) ($group->first()['protected_area_name'] ?? 'Protected Area'), 'Heading1');
                $xml .= $this->table($group->all(), false, $mode);
                if ($index < $groups->count() - 1) $xml .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            if ($groups->isEmpty()) $xml .= $this->paragraph('No Data', 'Meta');
        } else {
            $xml .= $this->table($rows, true, $mode);
            if (count($rows) === 0) $xml .= $this->paragraph('No Data', 'Meta');
        }

        $xml .= '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="500" w:right="500" w:bottom="500" w:left="500" w:header="250" w:footer="250" w:gutter="0"/></w:sectPr></w:body></w:document>';

        return $xml;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function table(array $rows, bool $includeProtectedArea, string $mode = ''): string
    {
        $degree = "\u{00B0}";
        $headers = $includeProtectedArea
            ? ['Protected Area', 'Reporting Period', 'Average Atmospheric Pressure (kPa)', 'Average Air Temperature ('.$degree.'C)', 'Average Vapor Pressure Deficit (kPa)', 'Average Relative Humidity (%)', 'Mean Wind Direction ('.$degree.')', 'Total Precipitation (mm)', 'Average Wind Speed (m/s)', 'Data Completeness (%)', 'Remarks']
            : [($mode === 'one_month' ? 'Reporting Date' : 'Month / Reporting Period'), 'Average Atmospheric Pressure (kPa)', 'Average Air Temperature ('.$degree.'C)', 'Average Vapor Pressure Deficit (kPa)', 'Average Relative Humidity (%)', 'Mean Wind Direction ('.$degree.')', 'Total Precipitation (mm)', 'Average Wind Speed (m/s)', 'Data Completeness (%)', 'Remarks'];
        $widths = $includeProtectedArea ? [1500, 1300, 1750, 1550, 1850, 1550, 1500, 1500, 1450, 950, 940] : [1600, 1800, 1550, 1800, 1650, 1550, 1600, 1550, 1200, 1540];

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="15840" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="82938A"/><w:left w:val="single" w:sz="4" w:color="82938A"/><w:bottom w:val="single" w:sz="4" w:color="82938A"/><w:right w:val="single" w:sz="4" w:color="82938A"/><w:insideH w:val="single" w:sz="4" w:color="82938A"/><w:insideV w:val="single" w:sz="4" w:color="82938A"/></w:tblBorders></w:tblPr>';
        $xml .= $this->tableRow($headers, $widths, true, true);
        foreach ($rows as $index => $row) {
            $values = $includeProtectedArea
                ? [$row['protected_area_name'], $row['period'], $row['average_atmospheric_pressure'], $row['average_air_temperature'], $row['average_vapor_pressure_deficit'], $row['average_relative_humidity'], $row['mean_wind_direction'], $row['total_precipitation'], $row['average_wind_speed'], $row['data_completeness'], $row['remarks']]
                : [$row['period'], $row['average_atmospheric_pressure'], $row['average_air_temperature'], $row['average_vapor_pressure_deficit'], $row['average_relative_humidity'], $row['mean_wind_direction'], $row['total_precipitation'], $row['average_wind_speed'], $row['data_completeness'], $row['remarks']];
            $xml .= $this->tableRow($values, $widths, false, $index % 2 === 1);
        }
        $xml .= '</w:tbl>';

        return $xml;
    }

    /** @param array<int, mixed> $values @param array<int, int> $widths */
    private function tableRow(array $values, array $widths, bool $header, bool $alternate): string
    {
        $xml = '<w:tr>'.($header ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        foreach (array_values($values) as $index => $value) {
            $fill = $header ? '166534' : ($alternate ? 'F0F6F1' : 'FFFFFF');
            $text = $value === null || $value === '' ? "\u{2014}" : (string) $value;
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.($widths[$index] ?? 1500).'" w:type="dxa"/><w:shd w:fill="'.$fill.'"/></w:tcPr><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:sz w:val="'.($header ? '12' : '14').'"/>'.($header ? '<w:color w:val="FFFFFF"/><w:b/>' : '').'</w:rPr><w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p></w:tc>';
        }
        return $xml.'</w:tr>';
    }

    private function paragraph(string $text, string $style): string
    {
        return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr><w:r><w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function documentRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="14"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/><w:spacing w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/><w:spacing w:after="180"/></w:pPr><w:rPr><w:sz w:val="20"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Meta"><w:name w:val="Meta"/><w:basedOn w:val="Normal"/><w:rPr><w:sz w:val="16"/></w:rPr></w:style></w:styles>';
    }

    private function settings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:zoom w:percent="90"/></w:settings>';
    }

    private function coreProperties(string $periodLabel): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>AWS Monitoring Summary - '.$this->xml($periodLabel).'</dc:title><dc:creator>eDATS CDS</dc:creator></cp:coreProperties>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>eDATS CDS</Application></Properties>';
    }
}
