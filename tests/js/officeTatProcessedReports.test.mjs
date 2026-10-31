import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve, join } from 'node:path';
import { tmpdir } from 'node:os';
import { formatPeriodLabel, reportingPeriodLabel } from '../../resources/js/Pages/Admin/OfficeTat/reportPresentation.mjs';
import { restoreModalFocusTarget } from '../../resources/js/Components/ModalMotion/focusReturn.mjs';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

let server;
const load = async path => {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        cacheDir: join(tmpdir(), 'cds-office-tat-processed-test-' + process.pid),
        optimizeDeps: { noDiscovery: true, include: [] },
        server: { middlewareMode: true, hmr: false, ws: false },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule('/resources/js/' + path);
};

test.after(async () => { await server?.close(); });

test('processed-report table renders accessible participant rows without a details column', async () => {
    const { default: ComplianceFirstAssessments } = await load('Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx');
    const markup = renderToStaticMarkup(React.createElement(ComplianceFirstAssessments, {
        data: {
            unit: 'development', period_key: '2026', as_of_date: '2026-10-27',
            office_tat_assessment: {
                processed_count: 2, received_by_penro_count: 1, assessed_count: 1,
                on_time_count: 1, tc_percent: 100, se_percent: 50, otps: 90,
                rows: [{
                    participant_key: 'cenro:4', name: 'CENRO Baganga', processed_count: 2,
                    received_by_penro_count: 1, assessed_count: 1, due_count: 1,
                    submitted_count: 1, on_time_count: 1, tc_percent: 100, se_percent: 50,
                    otps: 90, rank: 1, rank_status: 'Final rank', obligation_details: [],
                }],
            },
        },
    }));
    const header = markup.match(/<thead[\s\S]*?<\/thead>/)?.[0] || '';

    assert.equal((header.match(/<th\b/g) || []).length, 8);
    assert.match(markup, /Reports processed/);
    assert.match(markup, /Received by PENRO/);
    assert.match(markup, /aria-label="Open report details for CENRO Baganga"/);
    assert.match(markup, /tabindex="0"/);
    assert.match(markup, /hover:bg-green-50\/60[^\"]*focus-visible:ring-2/);
    assert.doesNotMatch(markup, /View details|Reports due|Reports submitted|Why this result is unavailable|More report date details/);
});

test('participant details use the shared clickable table with named rows and focus return', () => {
    const component = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
    const table = readFileSync(new URL('../../resources/js/Components/Crud/CrudTable.jsx', import.meta.url), 'utf8');

    assert.match(component, /<CrudTable/);
    assert.match(component, /onRowClick=\{row => setDetailRow\(row\)\}/);
    assert.match(component, /rowAriaLabel=\{row => 'Open report details for ' \+ row\.name\}/);
    assert.match(component, /focusRowOnClick/);
    assert.match(table, /shouldActivateRow\(event, event\.currentTarget\)/);
    assert.match(table, /event\.type === 'keydown'\) event\.preventDefault\(\)/);
    assert.match(table, /event\.type === 'click' && focusRowOnClick\) event\.currentTarget\.focus/);
});

test('details use canonical workflow and period labels and show a report ID once', async () => {
    const { ReportDetail } = await load('Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx');
    const markup = renderToStaticMarkup(React.createElement(ReportDetail, {
        expanded: true,
        onToggle() {},
        item: {
            label: 'bdfap', status: 'Submitted early', source_reference: 'Report #22',
            start_date: '2026-10-01', deadline: '2026-10-20', received: '2026-10-10',
            allowed_days: 15, days_used: 7, individual_efficiency_percent: 53.33,
            source_identity: {
                source_key: 'conservation', source_id: 22, workflow_key: 'bdfap',
                workflow_label: 'BDFAPs in PAs', activity_name: 'BDFAPs in PAs',
                period_key: 'quarter_4', period_label: 'quarter_4', reporting_year: 2026,
                document_type: 'Inventory Report',
            },
        },
    }));

    assert.match(markup, /BDFAPs in PAs/);
    assert.match(markup, /2026 Quarter 4/);
    assert.equal((markup.match(/Report #22/g) || []).length, 1);
    assert.doesNotMatch(markup, /\bbdfap\b|quarter_4|conservation #22/);
});

test('report details show a concise note only when a source date issue exists', async () => {
    const { ReportDetail } = await load('Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx');
    const base = {
        source_identity: { workflow_label: 'BDFAPs in PAs', period_key: 'quarter_4', reporting_year: 2026 },
        status: 'Receipt not recorded', start_date: '2026-10-01', deadline: '2026-10-20',
    };
    const ordinary = renderToStaticMarkup(React.createElement(ReportDetail, {
        item: base, expanded: true, onToggle() {},
    }));
    const withIssue = renderToStaticMarkup(React.createElement(ReportDetail, {
        item: { ...base, issue: 'Accepted PENRO receipt date is invalid.' }, expanded: true, onToggle() {},
    }));

    assert.doesNotMatch(ordinary, /Report note|Result:/);
    assert.match(withIssue, /<strong>Report note:<\/strong> Accepted PENRO receipt date is invalid\./);
});

test('period formatting covers quarters, semesters, months, and annual reports', () => {
    assert.equal(formatPeriodLabel('quarter_4'), 'Quarter 4');
    assert.equal(formatPeriodLabel('quarter_3'), 'Quarter 3');
    assert.equal(formatPeriodLabel('S1'), 'Semester 1');
    assert.equal(formatPeriodLabel('2026-01'), 'January');
    assert.equal(formatPeriodLabel('annual'), 'Annual');
    assert.equal(reportingPeriodLabel({ reporting_year: 2026, period_key: 'quarter_4' }), '2026 Quarter 4');
    assert.equal(reportingPeriodLabel({ reporting_year: 2026, period_label: 'October 2026' }), 'October 2026');
});

test('modal restores focus to the originating participant row', () => {
    const source = readFileSync(new URL('../../resources/js/Components/ModalMotion/useModalMotion.js', import.meta.url), 'utf8');
    const row = { focused: false, focus() { this.focused = true; } };
    const queued = [];
    const documentObject = { contains: candidate => candidate === row };

    assert.equal(restoreModalFocusTarget(row, documentObject, callback => queued.push(callback)), true);
    assert.equal(row.focused, false);
    queued[0]();
    assert.equal(row.focused, true);
    assert.match(source, /document\.activeElement/);
    assert.match(source, /restoreModalFocusTarget\(previous, document\)/);
});
