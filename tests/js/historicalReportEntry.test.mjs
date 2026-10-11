import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { resolveHistoricalRecordHref, stopHistoricalRecordEvent } from '../../resources/js/Components/historicalRecordBadgeBehavior.mjs';

const entry = readFileSync(
    new URL('../../resources/js/Pages/Admin/Settings/HistoricalReports.jsx', import.meta.url),
    'utf8',
);
const detail = readFileSync(
    new URL('../../resources/js/Pages/Admin/Settings/HistoricalReportDetail.jsx', import.meta.url),
    'utf8',
);
const badge = readFileSync(
    new URL('../../resources/js/Components/HistoricalRecordBadge.jsx', import.meta.url),
    'utf8',
);
const trackerWrapper = readFileSync(
    new URL('../../resources/js/Components/StandardAReportSubmissionTracker.jsx', import.meta.url),
    'utf8',
);
const conservationTracker = readFileSync(
    new URL('../../resources/js/Pages/Bms/ReportSubmissionTracker.jsx', import.meta.url),
    'utf8',
);

test('historical form selection uses the active workflow contract and sends an original evidence file', () => {
    assert.match(entry, /catalog\.workflows\?\.find\(item => String\(item\.id\) === String\(form\.data\.workflow_id\)\)/);
    assert.match(entry, /const fileFor = kind => kind === 'aws' \? 'report_file' : kind === 'management-plans' \? 'attachments' : 'mov'/);
    assert.match(entry, /fetch\('\/settings\/historical-reports\/periods'/);
    assert.match(entry, /fetch\('\/settings\/historical-reports\/preview'/);
    assert.match(entry, /form\.post\('\/settings\/historical-reports', \{ forceFormData: true/);
    assert.match(entry, /Original Date Received by PENRO/);
    assert.match(entry, /Original Date Endorsed to Regional Office/);
    assert.match(entry, /Upload the original supporting document\. Files use protected application storage\./);
});

test('historical module badges use the protected index route and stop source-row activation', () => {
    const routeCalls = [];
    const routeBuilder = (name, params) => {
        routeCalls.push({ name, params });
        return '/settings/historical-reports';
    };

    assert.equal(resolveHistoricalRecordHref(false, routeBuilder), null);
    assert.deepEqual(routeCalls, []);
    assert.equal(resolveHistoricalRecordHref(true, routeBuilder), '/settings/historical-reports');
    assert.deepEqual(routeCalls, [{ name: 'settings.historical-reports.index', params: undefined }]);

    const eventOrder = [];
    const event = { stopPropagation: () => eventOrder.push('stopped') };
    stopHistoricalRecordEvent(event, () => eventOrder.push('badge click'));
    stopHistoricalRecordEvent(event);
    assert.deepEqual(eventOrder, ['stopped', 'badge click', 'stopped']);

    assert.match(badge, /resolveHistoricalRecordHref\(auth\.canViewHistoricalReports, route\)/);
    assert.match(badge, /onClick=\{event => stopHistoricalRecordEvent\(event, onClick\)\}/);
    assert.match(badge, /onKeyDown=\{event => stopHistoricalRecordEvent\(event\)\}/);
    assert.match(trackerWrapper, /historicalSource=\{historicalSource \|\| \(moduleLabel === 'BAMS' \? 'bams' : 'imea'\)\}/);
    assert.match(conservationTracker, /historicalKind !== 'conservation'/);
    assert.match(badge, /aria-label="Open Historical Reports table"/);
    assert.match(badge, /passive \? null/);
    assert.match(entry, /<HistoricalRecordBadge source=\{record\.source\} id=\{record\.id\} passive \/>/);
    assert.match(detail, /record\.attachments\?\.length/);
    assert.match(detail, /DocumentPreviewDialog/);
    assert.match(detail, /record\.timeliness\?\.deadline/);
    assert.match(detail, /historicalSourceModuleHref\(record\)/);
    assert.match(detail, /href=\{sourceModuleHref\}/);
});
