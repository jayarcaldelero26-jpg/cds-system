import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    createHistoricalDetailRequestGuard,
    createHistoricalDetailTrace,
    fetchHistoricalDetail,
    historicalRecordIdentity,
} from '../../resources/js/Pages/Admin/Settings/historicalDetailRequest.mjs';

const page = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReports.jsx', import.meta.url), 'utf8');
const loadingView = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReportDetailSkeleton.jsx', import.meta.url), 'utf8');
const sharedSkeleton = readFileSync(new URL('../../resources/js/Components/Skeleton.jsx', import.meta.url), 'utf8');
const modal = readFileSync(new URL('../../resources/js/Components/Crud/CrudFormModal.jsx', import.meta.url), 'utf8');
const motion = readFileSync(new URL('../../resources/js/Components/ModalMotion/useModalMotion.js', import.meta.url), 'utf8');
const header = readFileSync(new URL('../../resources/js/Components/Crud/CrudModalHeader.jsx', import.meta.url), 'utf8');
const table = readFileSync(new URL('../../resources/js/Components/Crud/CrudTable.jsx', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function jsonResponse(record, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: name => name.toLowerCase() === 'content-type' ? 'application/json; charset=utf-8' : null },
        text: async () => JSON.stringify({ record }),
    };
}

const validRecord = {
    source: 'conservation', source_id: 38, label: 'Regular PAMB Meetings', detail: {},
    timeline: [], activity_timeline: [], historical_date_gaps: {}, timeliness: { status: 'N/A' }, attachments: [],
};

test('row activation reveals the loading dialog synchronously and requests by source/id identity', () => {
    const openHandler = page.slice(page.indexOf('const openDetails'), page.indexOf('const closeDetails'));
    assert.match(openHandler, /setSelectedRecord\(record\)/);
    assert.match(openHandler, /setDetailState\('loading'\)/);
    assert.match(openHandler, /setDetailsOpen\(true\)/);
    assert.match(page, /fetchHistoricalDetail\(requestUrl, \{ signal: controller\.signal, expectedIdentity: selectedIdentity, trace \}\)/);
    assert.match(page, /requestUrl = trace\?\.withDiagnostic\(selectedRecord\.url\)/);
    assert.match(page, /useLayoutEffect\(\(\) => \{[\s\S]*modal_shell_commit/);
    assert.doesNotMatch(openHandler, /await\s+fetch|await\s+fetchHistoricalDetail/);
    assert.match(page, /open=\{detailsOpen\}/);
    assert.match(page, /detailState === 'loading' && <HistoricalReportDetailSkeleton \/>/);
    assert.match(loadingView, /role="status"[\s\S]*Loading report details/);
    assert.match(loadingView, /xl:grid-cols-\[minmax\(0,1\.3fr\)_minmax\(280px,\.7fr\)\]/);
    assert.match(sharedSkeleton, /motion-safe:animate-pulse/);
    assert.match(page, /setDetailState\('error'\)/);
    assert.match(page, /setDetailRecord\(record\);[\s\S]*setDetailState\('ready'\)/);
    assert.match(page, /detailState === 'error'[\s\S]*Retry details[\s\S]*Close/);
    assert.match(page, /\[selectedIdentity, selectedRecord\?\.url, detailRetry\]/);
    assert.equal(historicalRecordIdentity({ source: 'conservation', id: 38 }), 'conservation:38');
    assert.equal(historicalRecordIdentity({ source: 'engp', id: 38 }), 'engp:38');
    assert.equal(historicalRecordIdentity({ source: '', id: 38 }), null);
});

test('authorized detail GET resolves after a deferred response using same-origin credentials', async () => {
    const response = deferred();
    const calls = [];
    const trace = createHistoricalDetailTrace({
        source: 'conservation', id: 38,
        location: { hostname: 'cds-system.test', origin: 'https://cds-system.test', search: '?__cds_perf=1' },
        log: () => {},
    });
    const requestUrl = trace.withDiagnostic('/settings/historical-reports/conservation/38');
    const request = fetchHistoricalDetail(requestUrl, {
        fetchImpl: (...args) => { calls.push(args); return response.promise; },
        expectedIdentity: 'conservation:38',
        trace,
    });

    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], requestUrl);
    assert.equal(calls[0][1].method, 'GET');
    assert.equal(calls[0][1].credentials, 'same-origin');
    assert.equal(calls[0][1].headers.Accept, 'application/json');
    response.resolve(jsonResponse(validRecord));
    assert.deepEqual(await request, validRecord);
    for (const mark of ['request_dispatch', 'response_headers', 'body_complete', 'json_parse_complete', 'contract_check_complete', 'payload_validated']) {
        assert.equal(trace.hasMark(mark), true, `missing trace mark: ${mark}`);
    }
});

test('denied, missing, and incomplete protected detail responses stay distinguishable', async () => {
    await assert.rejects(fetchHistoricalDetail('/denied', { fetchImpl: async () => jsonResponse(null, 403) }), error => error.status === 403 && /not authorized/.test(error.message));
    await assert.rejects(fetchHistoricalDetail('/missing', { fetchImpl: async () => jsonResponse(null, 404) }), error => error.status === 404 && /no longer available/.test(error.message));
    await assert.rejects(fetchHistoricalDetail('/session-expired', { fetchImpl: async () => jsonResponse(null, 401) }), error => error.status === 401 && /could not be loaded/.test(error.message));
    await assert.rejects(fetchHistoricalDetail('/server-error', { fetchImpl: async () => jsonResponse(null, 500) }), error => error.status === 500 && /could not be loaded/.test(error.message));
    await assert.rejects(fetchHistoricalDetail('/login-redirect', { fetchImpl: async () => ({ ...jsonResponse(validRecord), headers: { get: () => 'text/html; charset=utf-8' } }) }), /was not JSON/);
    await assert.rejects(fetchHistoricalDetail('/malformed-json', { fetchImpl: async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, text: async () => '{' }) }), /response was incomplete/);
    await assert.rejects(fetchHistoricalDetail('/incomplete', { fetchImpl: async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, text: async () => '{}' }) }), /response was incomplete/);
    await assert.rejects(fetchHistoricalDetail('/empty-record', { fetchImpl: async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, text: async () => '{"record":{}}' }) }), /response was incomplete/);
    await assert.rejects(fetchHistoricalDetail('/wrong-content-type', { fetchImpl: async () => ({ ...jsonResponse(validRecord), headers: { get: () => 'text/plain' } }) }), /was not JSON/);
    await assert.rejects(fetchHistoricalDetail('/missing-content-type', { fetchImpl: async () => ({ ...jsonResponse(validRecord), headers: { get: () => null } }) }), /was not JSON/);
});

test('a 200 response for a different source/id is rejected before rendering', async () => {
    await assert.rejects(fetchHistoricalDetail('/settings/historical-reports/conservation/38', {
        fetchImpl: async () => jsonResponse({ ...validRecord, source_id: 39 }),
        expectedIdentity: 'conservation:38',
    }), /did not match the selected record/);
});

test('local historical detail diagnostics are opt-in and emit timing metadata without record contents', () => {
    let now = 10;
    const summaries = [];
    const location = { hostname: 'cds-system.test', origin: 'https://cds-system.test', search: '?__cds_perf=1' };
    const performanceRef = {
        now: () => now,
        getEntriesByName: (name, type) => type === 'resource' ? [{
            entryType: 'resource', requestStart: 11, responseStart: 17, responseEnd: 20,
        }] : [],
    };
    assert.equal(createHistoricalDetailTrace({ source: 'conservation', id: 38, location: { ...location, search: '' }, performanceRef }), null);
    assert.equal(createHistoricalDetailTrace({ source: 'conservation', id: 38, location: { ...location, hostname: 'example.test' }, performanceRef }), null);

    const trace = createHistoricalDetailTrace({
        source: 'conservation', id: 38, location, performanceRef, activatedAt: 8,
        log: summary => summaries.push(summary),
    });
    assert.equal(trace.identity, 'conservation:38');
    assert.equal(trace.withDiagnostic('/settings/historical-reports/conservation/38'), 'https://cds-system.test/settings/historical-reports/conservation/38?__cds_perf=1');
    assert.equal(trace.withDiagnostic('https://other.test/settings/historical-reports/conservation/38'), 'https://other.test/settings/historical-reports/conservation/38');
    trace.mark('modal_shell_commit');
    trace.mark('request_dispatch');
    now = 13;
    trace.recordResponse({
        status: 200,
        headers: { get: name => ({
            'content-type': 'application/json',
            'server-timing': 'app_pipeline;dur=14.20, sql;dur=2.00;desc="queries 4"',
            'x-cds-perf-id': 'a1b2c3',
            'x-cds-perf-route': 'settings.historical-reports.show',
        }[name.toLowerCase()] || null) },
    });
    trace.mark('response_headers');
    trace.mark('body_read_start');
    now = 14;
    trace.mark('body_complete');
    trace.mark('json_parse_complete');
    trace.mark('contract_check_start');
    trace.recordPayloadIdentity('conservation', 38);
    now = 15;
    trace.mark('contract_check_complete');
    trace.mark('payload_validated');
    trace.mark('details_commit');
    trace.mark('details_two_frames');
    trace.finish('ready_after_two_frames');

    assert.equal(summaries.length, 1);
    assert.equal(summaries[0].route, '/settings/historical-reports/conservation/38');
    assert.equal(summaries[0].response_identity, 'conservation:38');
    assert.equal(summaries[0].identity_match, true);
    assert.deepEqual(summaries[0].payload_validation, { json_parse: 'valid', contract: 'valid' });
    assert.equal(summaries[0].status, 200);
    assert.equal(summaries[0].server_route, 'settings.historical-reports.show');
    assert.equal(summaries[0].timings_ms.activation_to_modal_commit, 2);
    assert.deepEqual(summaries[0].resource_timing, { request_to_response_start_ms: 6, response_body_ms: 3 });
    assert.doesNotMatch(JSON.stringify(summaries[0]), /cookie|authorization|remarks|attachment|base64/i);
});

test('close and row switching invalidate late responses; identities include source and id', async () => {
    const guard = createHistoricalDetailRequestGuard();
    const openHandler = page.slice(page.indexOf('const openDetails'), page.indexOf('const closeDetails'));
    const closeHandler = page.slice(page.indexOf('const closeDetails'), page.indexOf('const finishDetailsClose'));
    assert.match(closeHandler, /detailRequestGuardRef\.current\.invalidate\(\)/);
    assert.match(closeHandler, /detailControllerRef\.current\?\.abort\(\)/);
    assert.match(openHandler, /else if \(!detailsOpen && detailState === 'loading'\)[\s\S]*setDetailRetry/);

    const first = deferred();
    const firstToken = guard.begin();
    const firstResult = fetchHistoricalDetail('/row-a', { fetchImpl: () => first.promise })
        .then(record => guard.isCurrent(firstToken) ? record : null);

    guard.invalidate(); // detail dialog closes before the request resolves
    first.resolve(jsonResponse({ ...validRecord, source_id: 1 }));
    assert.equal(await firstResult, null);

    const second = deferred();
    const secondToken = guard.begin();
    const secondResult = fetchHistoricalDetail('/row-b', { fetchImpl: () => second.promise })
        .then(record => guard.isCurrent(secondToken) ? record : null);
    const third = deferred();
    const thirdToken = guard.begin(); // a different source/id became selected
    const thirdResult = fetchHistoricalDetail('/row-c', { fetchImpl: () => third.promise })
        .then(record => guard.isCurrent(thirdToken) ? record : null);

    second.resolve(jsonResponse({ ...validRecord, source_id: 2 }));
    third.resolve(jsonResponse({ ...validRecord, source: 'engp', source_id: 3 }));
    assert.equal(await secondResult, null);
    assert.deepEqual(await thirdResult, { ...validRecord, source: 'engp', source_id: 3 });
    assert.notEqual(historicalRecordIdentity({ source: 'conservation', id: 38 }), historicalRecordIdentity({ source: 'engp', id: 38 }));
});

test('close control, keyboard focus, and shared reduced-motion lifecycle stay scoped', () => {
    assert.match(modal, /showHeaderClose = true/);
    assert.match(modal, /showClose=\{showHeaderClose\}/);
    assert.match(page, /backLabel="Close details"/);
    assert.match(page, /showHeaderClose=\{false\}/);
    assert.match(page, /motion="soft"/);
    assert.match(page, /onExited=\{finishDetailsClose\}/);
    assert.match(modal, /useModalMotion\(open/);
    assert.match(motion, /prefers-reduced-motion: reduce/);
    assert.match(motion, /window\.clearTimeout\(timer\)/);
    assert.match(motion, /generationRef/);
    assert.match(styles, /\.cds-modal-motion-backdrop/);
    assert.match(styles, /\.cds-modal-motion-panel/);
    assert.match(table, /rowFocusVisibleOnly = false/);
    assert.match(table, /focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600/);
    assert.match(page, /rowFocusVisibleOnly/);
});
