import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { isValidLiveAnalytics } from '../../resources/js/Pages/Admin/OfficeTat/liveRoutingAveragePayload.mjs';

const page = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const performance = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
const routingAverage = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/LiveRoutingAverage.jsx', import.meta.url), 'utf8');
const pamoPreparation = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/PamoPreparationAverage.jsx', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../../app/Http/Controllers/OfficeTatController.php', import.meta.url), 'utf8');
const service = readFileSync(new URL('../../app/Services/OfficeTatComplianceAssessmentService.php', import.meta.url), 'utf8');

test('Office TAT keeps group filters and presents document turnaround before separate submission performance', () => {
    assert.match(page, /Head title="Office TAT"/);
    assert.match(page, /<DocumentTurnaround/);
    assert.match(page, /<ComplianceFirstAssessments/);
    assert.match(page, /label="Reporting group"/);
    assert.match(page, /FilterToolbar label="Filters"/);
    assert.match(page, /label="Module"/);
    assert.match(page, /router\.get\('\/admin\/office-tat'/);
    assert.doesNotMatch(page, /CENRO processing average|PAMO preparation average/);
    assert.match(page, /How this is calculated/);
    assert.match(controller, /Admin\/OfficeTat\/PamoPreparationAverage/);
    assert.match(controller, /canViewLiveRoutingAverage/);
    assert.match(controller, /canViewPamoPreparationAverage/);
});

test('the results table uses the requested processed-report labels and independent group metrics', () => {
    for (const label of ['Office / PA', 'Reports processed', 'Received by PENRO', 'On time', 'TC', 'SE', 'Overall score', 'Rank']) {
        assert.ok(performance.includes(label), 'missing result label: ' + label);
    }
    for (const label of ['Not available yet', 'No assessed reports', 'Score unavailable', 'Not ranked yet']) {
        assert.ok(performance.includes(label), 'missing unavailable state: ' + label);
    }
    assert.doesNotMatch(performance, /Reports submitted|Reports due|View details/);
    assert.doesNotMatch(performance, /Recognition/i);
    assert.doesNotMatch(performance, /Coverage gaps|distinct evidence warnings|showCoverageDetails|Evidence or coverage issues|Eligible \/ received|Assessed N|Not applicable/);
});

test('formulas and ranking explanation share one optional disclosure', () => {
    assert.match(page, /How this is calculated/);
    assert.match(page, /max\(0, \(T - D\) \/ T\)/);
    assert.match(page, /Overall score remains 80% TC \+ 20% SE/);
    assert.equal((page.match(/<details\b/g) || []).length, 1);
    for (const step of ['early-submission percentage', 'verified report volume', 'verified highly technical reports on time', 'approved tie-resolution process']) {
        assert.ok(page.includes(step), 'missing ranking step: ' + step);
    }
});

test('participant details preserve accessible report accordions and source date efficiency facts', () => {
    assert.match(performance, /aria-expanded=\{expanded\}/);
    assert.match(performance, /aria-controls=\{panelId\}/);
    assert.match(performance, /hidden=\{!expanded\}/);
    assert.match(performance, /recorded_obligation_details/);
    for (const label of ['Report start', 'Due date', 'Received by PENRO Records', 'Allowed time (T)', 'Time used (D)', 'Efficiency for this report']) {
        assert.ok(performance.includes(label), 'missing report detail: ' + label);
    }
});

test('document turnaround uses a clickable participant row and the shared closable details modal', () => {
    const turnaround = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/DocumentTurnaround.jsx', import.meta.url), 'utf8');
    assert.match(turnaround, /onRowClick=\{row => setDetailRow\(row\)\}/);
    assert.match(turnaround, /focusRowOnClick/);
    assert.match(turnaround, /rowAriaLabel=\{/);
    assert.match(turnaround, /<CrudDetailsModal/);
    assert.match(turnaround, /onClose=\{\(\) => setDetailRow\(null\)\}/);
    assert.doesNotMatch(turnaround, /View details|Action/);
    assert.match(turnaround, /setDetailRow\(next\)/);
});

test('participant results remain independent from full group ranking and unknown N is not zero', () => {
    assert.match(service, /function participantResultState\(array \$row, bool \$requiredCountKnown/);
    assert.match(service, /required_count_status'\] = 'unknown'/);
    assert.match(service, /required_count_status'\] = .*'confirmed_zero'/);
    assert.match(service, /result_reason'\] = \$reason \?\? 'Required report count is not available yet\.'/);
    assert.match(service, /Not ranked yet/);
});

test('live routing average remains separate and validates its returned data', () => {
    assert.match(routingAverage, /CENRO processing average/);
    assert.match(routingAverage, /isValidLiveAnalytics/);
    assert.match(routingAverage, /tat_completed_documents/);
    assert.match(routingAverage, /tat_average_elapsed_seconds/);
    assert.match(controller, /'category' => 'CENRO'/);
    assert.match(controller, /'evidence_type' => 'live'/);
    assert.match(controller, /Admin\/OfficeTat\/LiveRoutingAverage/);
});

test('PAMO preparation view reuses the existing preparation measurement separately from CENRO', () => {
    assert.match(pamoPreparation, /PAMO preparation average/);
    assert.match(pamoPreparation, /First saved PAMO draft to the next accepted PAMO handoff/);
    assert.match(pamoPreparation, /overall\.average_elapsed_seconds/);
    assert.match(pamoPreparation, /separate from TC, SE and overall score/);
    assert.match(controller, /pamo_preparation/);
});

test('live routing payload rejects missing, mismatched, and malformed data', () => {
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
