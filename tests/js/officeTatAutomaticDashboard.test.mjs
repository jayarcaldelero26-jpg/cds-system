import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { isValidLiveAnalytics } from '../../resources/js/Pages/Admin/OfficeTat/liveRoutingAveragePayload.mjs';

const page = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const ranking = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
const routingAverage = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/LiveRoutingAverage.jsx', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../../app/Http/Controllers/OfficeTatController.php', import.meta.url), 'utf8');
const service = readFileSync(new URL('../../app/Services/OfficeTatComplianceAssessmentService.php', import.meta.url), 'utf8');

test('recognition screen offers authorized groups, period, date, and canonical module filters', () => {
    assert.match(page, /authorizedUnits\.filter\(unit => unitLabels\[unit\]\)/);
    assert.match(page, /label="Reporting period"/);
    assert.match(page, /label="As of date"/);
    assert.match(page, /label="Module"/);
    assert.match(page, /router\.get\('\/admin\/office-tat'/);
    assert.match(page, /Conservation and Development are ranked separately/);
    assert.doesNotMatch(page + ranking, /Average TAT|Historical|Preparation|Assessment settings/i);
});

test('recognition ranking displays TC, SE, OTPS, shared ranks, and the same-N formula', () => {
    assert.match(ranking, /max\(0, \(T − D\) ÷ T\) × 100/);
    assert.match(ranking, /OTPS.*80% TC \+ 20% SE/);
    assert.match(ranking, /TC descending, then SE descending/);
    assert.match(ranking, /share a competition rank, such as 1, 1, 3/);
    for (const label of ['Eligible reports', 'Received by cutoff', 'Assessed reports (N)', 'On time', 'TC', 'SE', 'OTPS']) {
        assert.ok(ranking.includes(label), `missing ranking label: ${label}`);
    }
});

test('current as-of and full-period results are distinguished and scoped ranks are labeled', () => {
    assert.match(ranking, /Current as-of ranking · provisional/);
    assert.match(ranking, /Final full-period recognition ranking/);
    assert.match(ranking, /its rank is not the full group ranking/);
    assert.match(ranking, /Future, unreceived obligations stay outside N/);
    assert.match(ranking, /Due but unreceived reports contribute zero to TC and SE/);
});

test('the page reads the automated source assessment without querying average or historical analytics', () => {
    assert.match(controller, /effectiveUnits\(\$request->user\(\)\)/);
    assert.match(controller, /recordedAssessments\(\$request->user\(\), \$selectedUnit/);
    const indexAction = controller.slice(0, controller.indexOf('public function liveRoutingAverage'));
    assert.doesNotMatch(indexAction, /\$analytics->analytics\(/);
    assert.doesNotMatch(indexAction, /\$assessments->assessments\(/);
    assert.doesNotMatch(indexAction, /historical|Average TAT|pamo_preparation/i);
});

test('live routing averages remain available in a separate live-events view', () => {
    assert.match(page, /Live routing average/);
    assert.match(page, /return_unit=.*selectedUnit/);
    assert.match(routingAverage, /Live CENRO routing average/);
    assert.match(routingAverage, /return_unit: returnUnit/);
    assert.match(routingAverage, /unit=\$\{encodeURIComponent\(returnUnit\)\}/);
    assert.match(routingAverage, /isValidLiveAnalytics/);
    assert.match(routingAverage, /role="alert"/);
    assert.match(routingAverage, /No CENRO office results are available for this period/);
    assert.match(routingAverage, /As of \{filters\.as_of_date \|\| asOfDate\}/);
    assert.match(routingAverage, /tat_completed_documents/);
    assert.match(routingAverage, /tat_average_elapsed_seconds/);
    assert.match(routingAverage, /No completed handoffs/);
    assert.doesNotMatch(routingAverage, /historical|preparation|manual_historical/i);
    const routingAction = controller.slice(controller.indexOf('public function liveRoutingAverage'), controller.indexOf('public function storeApprovalEvidence'));
    assert.match(routingAction, /'category' => 'CENRO'/);
    assert.match(routingAction, /'evidence_type' => 'live'/);
    assert.match(routingAction, /Admin\/OfficeTat\/LiveRoutingAverage/);
});


test('live routing payload distinguishes valid empty results from missing, mismatched, and malformed data', () => {
    const filters = { processing_period: '2026-H2', as_of_date: '2026-10-26' };
    const payload = { category: 'CENRO', evidence_type: 'live', ...filters, rows: [] };
    const zeroRow = {
        office: 'CENRO Baganga', documents_received: 0, tat_completed_documents: 0,
        tat_elapsed_seconds_total: 0, tat_average_elapsed_seconds: null, pending: 0, tat_missing_evidence: 0,
    };

    assert.equal(isValidLiveAnalytics(payload, filters), true);
    assert.equal(isValidLiveAnalytics({ ...payload, rows: [zeroRow] }, filters), true);
    assert.equal(isValidLiveAnalytics(null, filters), false);
    assert.equal(isValidLiveAnalytics({ ...payload, rows: null }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...payload, as_of_date: '2026-10-25' }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...payload, rows: [{ office: 'CENRO Baganga' }] }, filters), false);
});

test('canonical module filters include native PAMO families and exclude Management Plans', () => {
    assert.match(service, /function moduleOptions\(string \$unit\)/);
    assert.match(service, /PamoNativeSourceRegistry/);
    assert.match(service, /TYPE_PLAN/);
    assert.match(service, /function periodOptions\(\)/);
    assert.match(service, /recordedDevelopmentRows\(\$participants, \$periodKey, \$periodStart, \$periodEnd, \$cutoff, \$classifications, \$user, \$workflowFilter\)/);
});

test('source details expose identity, dates, T, D, individual efficiency, and exclusion reason', () => {
    for (const label of ['Workflow start', 'Authoritative deadline', 'Accepted PENRO receipt', 'T allowed', 'D used', 'Individual SEᵢ', 'Assessment:']) {
        assert.ok(ranking.includes(label), `missing evidence label: ${label}`);
    }
    assert.match(ranking, /source_reference/);
    assert.match(ranking, /individual_efficiency_percent/);
    assert.match(ranking, /Registry occurrence; no submitted source record/);
});
