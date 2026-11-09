<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Official exports over the exact normalized ExecutiveReportService payload. */
final class ExecutiveReportExportService
{
    public function download(array $report, string $format)
    {
        $format = strtolower($format);
        return match ($format) {
            'pdf' => Pdf::loadView('reports.executive-pdf', [
                'report' => $report,
                'metrics' => $this->metrics($report),
                'comparisonText' => $this->comparisonText($report),
            ])->setPaper('a4', 'portrait')->download($this->filename($report, 'pdf')),
            'xlsx' => $this->xlsx($report),
            'docx' => $this->docx($report),
            default => abort(404),
        };
    }

    private function xlsx(array $report): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'The XLSX export extension is unavailable.');
        return \App\Services\TemporaryExportFile::zipDownload(
            'executive-report-',
            $this->filename($report, 'xlsx'),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'The XLSX workbook could not be created.',
            'The XLSX workbook could not be created.',
            function (ZipArchive $zip) use ($report): void {
                $sheets = [
                    'Compliance Summary' => $this->summaryRows($report),
                    'Requirement Details' => $this->detailRows($report['details']),
                ];
                $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
                $zip->addFromString('_rels/.rels', $this->rootRelationships());
                $zip->addFromString('docProps/core.xml', $this->coreProperties($report));
                $zip->addFromString('docProps/app.xml', $this->appProperties());
                $zip->addFromString('xl/workbook.xml', $this->workbook(array_keys($sheets)));
                $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
                $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
                foreach (array_values($sheets) as $index => $rows) $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($rows));
            },
        );
    }

    private function docx(array $report): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'The DOCX export extension is unavailable.');
        return \App\Services\TemporaryExportFile::zipDownload(
            'executive-report-',
            $this->filename($report, 'docx'),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'The DOCX document could not be created.',
            'The DOCX document could not be created.',
            function (ZipArchive $zip) use ($report): void {
                $zip->addFromString('[Content_Types].xml', $this->docxContentTypes());
                $zip->addFromString('_rels/.rels', $this->rootRelationships('word/document.xml', 'word/styles.xml'));
                $zip->addFromString('docProps/core.xml', $this->coreProperties($report));
                $zip->addFromString('docProps/app.xml', $this->appProperties());
                $zip->addFromString('word/document.xml', $this->document($report));
                $zip->addFromString('word/styles.xml', $this->docxStyles());
                $zip->addFromString('word/settings.xml', '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:zoom w:percent="90"/></w:settings>');
                $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
            },
        );
    }

    /** @return array<string,int|float|null> */
    private function metrics(array $report): array
    {
        $summary = $report['summary'];

        return [
            'Due by cutoff' => $summary['due'],
            'Confirmed PENRO receipts' => $summary['received'],
            'Receipt rate (%)' => $summary['receipt_rate'],
            'Received on time' => $summary['on_time'],
            'Received late' => $summary['late'],
            'Awaiting PENRO receipt' => $summary['awaiting_receipt'],
            'No submission record' => $summary['missing'],
            'Submission evidence missing' => $summary['unverified'],
            'Not due by cutoff' => $summary['not_due'],
            'Deadline unavailable' => $summary['no_deadline'],
        ];
    }

    private function comparisonText(array $report): string
    {
        $previous = $report['comparison'];
        if ($previous === null) return 'No comparable scheduled period is available for this scope.';

        $text = $previous['period_label'].' through '.$previous['as_of_date'].' — '.$previous['received'].' of '.$previous['due'].' due received';
        if ($previous['receipt_rate'] !== null) $text .= ' ('.$previous['receipt_rate'].'%)';
        if ($previous['change_pp'] === null) return $text.'. A rate comparison requires due requirements in both periods.';

        return $text.'. Change in receipt rate '.($previous['change_pp'] > 0 ? '+' : '').$previous['change_pp'].' percentage points.';
    }

    /** @return list<array{values:array<int,mixed>,header:bool}> */
    private function metricRows(array $report): array
    {
        $rows = [['values' => ['Metric', 'Value'], 'header' => true]];
        foreach ($this->metrics($report) as $label => $value) {
            $rows[] = ['values' => [$label, $value ?? 'Not available'], 'header' => false];
        }

        return $rows;
    }

    private function summaryRows(array $report): array
    {
        $filters = $report['filters'];

        return [
            ['values' => ['CDS-SMART '.$report['title']], 'header' => true],
            ['values' => ['Reporting period', $report['period_label']], 'header' => false],
            ['values' => ['Applied scope', $filters['scope_label']], 'header' => false],
            ['values' => ['Cutoff (Asia/Manila)', $filters['as_of_date']], 'header' => false],
            ['values' => ['Generated', $report['generated_at']], 'header' => false],
            ['values' => ['Detail status filter', $filters['status'] ?: 'All detail statuses'], 'header' => false],
            ...$this->metricRows($report),
            ['values' => ['Previous period', $this->comparisonText($report)], 'header' => false],
            ['values' => ['Basis', 'Registered requirements and dated PENRO receipts through the cutoff. The detail filter applies to the appendix only.'], 'header' => false],
        ];
    }

    private function detailRows(array $details): array
    {
        $rows = [['values' => ['Requirement', 'Program', 'Report', 'PA / Office', 'Office', 'Period', 'Deadline', 'Release by cutoff', 'PENRO receipt by cutoff', 'Outcome', 'Current routing status', 'Tracking no.', 'Follow-up office', 'Follow-up', 'Tracking URL'], 'header' => true]];
        foreach ($details as $row) {
            $rows[] = [
                'values' => [
                    $row['id'], strtoupper($row['domain']), $row['report'], $row['scope'], $row['office'], $row['period'],
                    $row['due_date'], $row['release_date'], $row['receipt_date'], $row['status'], $row['routing_status'],
                    $row['tracking_number'], $row['next_action'] ? $row['action_office'] : null, $row['next_action'], $row['tracking_url'],
                ],
                'header' => false,
            ];
        }

        return $rows;
    }

    private function actionRows(array $actions): array
    {
        $rows = [['values' => ['Report / period', 'PA / Office', 'Deadline', 'Follow-up'], 'header' => true]];
        foreach ($actions as $row) {
            $rows[] = ['values' => [
                $row['report'].' · '.$row['period'],
                $row['scope'],
                $row['due_date'] ?? 'Unavailable',
                implode(' · ', array_filter([$row['action_office'], $row['next_action']])),
            ], 'header' => false];
        }
        if ($actions === []) $rows[] = ['values' => ['No follow-up items for the applied filters.'], 'header' => false];

        return $rows;
    }

    /** @param list<array{values:array<int,mixed>,header:bool}> $rows */
    private function worksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach ($rows as $number => $row) {
            $xml .= '<row r="'.($number + 1).'">';
            foreach ($row['values'] as $column => $value) {
                if ($value === null || $value === '') continue;
                $ref = $this->columnName($column + 1).($number + 1);
                $style = $row['header'] ? '1' : '0';
                if (is_int($value) || is_float($value)) $xml .= '<c r="'.$ref.'" s="'.$style.'" t="n"><v>'.(is_finite((float) $value) ? $value : 0).'</v></c>';
                else $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t>'.$this->xml((string) $value).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml.'</sheetData><pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.2" footer="0.2"/></worksheet>';
    }

    private function document(array $report): string
    {
        $filters = $report['filters'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        $xml .= $this->paragraph('CDS-SMART', 'Title');
        $xml .= $this->paragraph(strtoupper($report['title']), 'Subtitle');
        $xml .= $this->paragraph($report['period_label'].' · '.$filters['scope_label'].' · Through '.$filters['as_of_date'].' (Manila)', 'Meta');
        $xml .= $this->paragraph('Compliance results', 'Heading1').$this->table($this->metricRows($report), [6700, 2800]);
        $xml .= $this->paragraph('Previous period', 'Heading1').$this->paragraph($this->comparisonText($report), 'Normal');
        $xml .= $this->paragraph('Priority follow-up', 'Heading1');
        if ($filters['status'] !== '') $xml .= $this->paragraph('Detail status '.$filters['status'], 'Meta');
        if ($report['action_count'] > count($report['actions'])) {
            $xml .= $this->paragraph('Showing '.count($report['actions']).' of '.$report['action_count'].' items.', 'Meta');
        }
        $xml .= $this->table($this->actionRows($report['actions']), [2950, 2050, 1550, 3500]);

        return $xml.'<w:sectPr><w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>';
    }

    /** @param list<array{values:array<int,mixed>,header:bool}> $rows @param list<int> $widths */
    private function table(array $rows, array $widths): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="10320" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="82938A"/><w:left w:val="single" w:sz="4" w:color="82938A"/><w:bottom w:val="single" w:sz="4" w:color="82938A"/><w:right w:val="single" w:sz="4" w:color="82938A"/><w:insideH w:val="single" w:sz="4" w:color="82938A"/><w:insideV w:val="single" w:sz="4" w:color="82938A"/></w:tblBorders></w:tblPr>';
        foreach ($rows as $row) {
            $xml .= '<w:tr>'.($row['header'] ? '<w:trPr><w:tblHeader/></w:trPr>' : '<w:trPr><w:cantSplit/></w:trPr>');
            foreach ($row['values'] as $index => $value) $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.($widths[$index] ?? 1200).'" w:type="dxa"/><w:shd w:fill="'.($row['header'] ? '166534' : 'FFFFFF').'"/></w:tcPr><w:p><w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/>'.($row['header'] ? '<w:b/><w:color w:val="FFFFFF"/>' : '').'</w:rPr><w:t xml:space="preserve">'.$this->xml($value === null || $value === '' ? '—' : (string) $value).'</w:t></w:r></w:p></w:tc>';
            $xml .= '</w:tr>';
        }
        return $xml.'</w:tbl>';
    }

    private function paragraph(string $text, string $style): string { return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/></w:rPr><w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p>'; }
    private function filename(array $report, string $extension): string { return 'edats-executive-report-'.($report['filters']['year'] ?? 'report').'-'.now()->format('Ymd_His').'.'.$extension; }
    private function columnName(int $number): string { $name = ''; while ($number > 0) { $remainder = ($number - 1) % 26; $name = chr(65 + $remainder).$name; $number = intdiv($number - 1, 26); } return $name; }
    private function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
    private function contentTypes(int $count): string { $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'; for ($i = 1; $i <= $count; $i++) $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; return $xml.'</Types>'; }
    private function docxContentTypes(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>'; }
    private function rootRelationships(string $main = 'xl/workbook.xml', string $styles = 'xl/styles.xml'): string { $word = str_starts_with($main, 'word/'); return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.$main.'"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
    private function workbook(array $names): string { $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'; foreach ($names as $i => $name) $xml .= '<sheet name="'.$this->xml($name).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>'; return $xml.'</sheets></workbook>'; }
    private function workbookRelationships(int $count): string { $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'; for ($i = 1; $i <= $count; $i++) $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>'; return $xml.'<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'; }
    private function xlsxStyles(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Times New Roman"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Times New Roman"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="166534"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>'; }
    private function coreProperties(array $report): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>CDS-SMART Period Compliance Summary</dc:title><dc:creator>CDS-SMART</dc:creator><dc:subject>'.$this->xml((string) ($report['filters']['scope_label'] ?? 'Authorized report monitoring')).'</dc:subject></cp:coreProperties>'; }
    private function appProperties(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>CDS-SMART</Application></Properties>'; }
    private function docxStyles(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Meta"><w:name w:val="Meta"/></w:style></w:styles>'; }
}
