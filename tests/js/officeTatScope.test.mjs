import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const officeTat = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const historicalReports = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReports.jsx', import.meta.url), 'utf8');

test('Office TAT presents PAMO and CENRO in the existing gradient tab component', () => {
    const categories = officeTat.match(/const categories = \[([\s\S]*?)\n\];/)?.[1] || '';
    assert.deepEqual([...categories.matchAll(/\['(PAMO|CENRO)'/g)].map(match => match[1]), ['PAMO', 'CENRO']);
    assert.match(officeTat, /<ComplianceTabList tabs=\{categories\}[^>]*label="Average TAT office category"/);
    assert.match(officeTat, /Turnaround Time and Historical Date Gap Ranking/);
    assert.match(officeTat, /weighted performance score is a different measure/i);
    assert.match(officeTat, /icon=\{<Icon icon="lucide:settings-2" width="18" height="18" aria-hidden="true" \/>\}/);
});

test('historical date ranges keep optional To semantics through the shared date picker', () => {
    assert.match(historicalReports, /dateField\('historical-conducted-from', 'Date Conducted — From'[\s\S]*?true\)/);
    assert.match(historicalReports, /dateField\('historical-conducted-to', 'Date Conducted — To \(optional\)'/);
    assert.match(historicalReports, /actualDate=\{actualDate\}/);
    assert.match(historicalReports, /historical-revenue-deadline[\s\S]*?true, false,/);
    assert.doesNotMatch(historicalReports, /<input[^>]*type="date"/);
});

test('historical list and encoder render shared controls, modal, table, and protected upload', () => {
    for (const control of ['FilterToolbar', 'FloatingInput', 'FloatingSelect', 'ScopedOptionSelect', 'CrudFormModal', 'CrudTable', 'AttachmentDropzone', 'HistoricalRecordBadge']) {
        assert.match(historicalReports, new RegExp(control));
    }
    assert.match(historicalReports, /<option value="">Choose document type<\/option>/);
    assert.match(historicalReports, /onChange=\{event => set\('document_type', event\.target\.value\)\}/);
    assert.match(historicalReports, /errors=\{form\.errors\}/);
    assert.match(historicalReports, /processing=\{form\.processing\}/);
    assert.match(historicalReports, /value => set\('original_date_received_penro', value\)/);

    assert.match(historicalReports, /title="Encode historical report"[\s\S]*?icon=\{<Icon icon="lucide:archive" width="18" height="18" aria-hidden="true" \/>\}/);
});

test('historical date-gap rankings separate report attribution from the source-date basis', () => {
    assert.match(officeTat, /PA records use the Protected Area’s assigned PAMO/);
    assert.match(officeTat, /not measured PAMO custody time/);
    assert.match(officeTat, /ENGP records are grouped by their assigned CENRO/);
    assert.match(officeTat, /label: evidence === 'historical' \? 'Reports attributed to' : 'Office'/);
    assert.match(officeTat, /Historical date gap/);
    assert.match(officeTat, /Original CENRO Release → Original PENRO Receipt/);
    assert.match(officeTat, /Eligible gap samples/);
});
