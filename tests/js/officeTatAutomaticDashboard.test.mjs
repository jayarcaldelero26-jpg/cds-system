import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const performance = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../../app/Http/Controllers/OfficeTatController.php', import.meta.url), 'utf8');
const assessment = readFileSync(new URL('../../app/Services/OfficeTatComplianceAssessmentService.php', import.meta.url), 'utf8');

test('Office TAT exposes only Report Performance and live Average Office Processing Time', () => {
    assert.match(page, /tabs=\{\[\["report-performance", "Report Performance"\], \["average-tat", "Average Office Processing Time"\]\]\}/);
    assert.match(page, /query\.evidence_type = 'live'/);
    assert.match(controller, /\$filters\['evidence_type'\] = 'live'/);
    assert.match(page, /Average Office Processing Time/);
    assert.doesNotMatch(page, /Historical date gap|Historical Date Gap|historicalCompleted|includeHistorical|evidenceType/);
    assert.doesNotMatch(page, /Advanced Assessment Settings|Assessment settings|Official complete-period assessment/);
    assert.match(page, /label="Assessment type"/);
    assert.match(page, /label="Assessment year"/);
});

test('Report Performance sends the recorded live projection and explains its actual denominators', () => {
    assert.match(page, /data=\{recordedAssessment\}/);
    assert.match(controller, /recordedAssessments\(\$request->user\(\), \$selectedUnit/);
    const indexAction = controller.slice(controller.indexOf('public function index('), controller.indexOf('public function storeApprovalEvidence'));
    assert.doesNotMatch(indexAction, /->assessments\(/);
    assert.match(performance, /Eligible live reports only/);
    assert.match(performance, /Eligible reports/);
    assert.match(performance, /Received by selected date/);
    assert.match(performance, /Due reports assessed/);
    assert.match(performance, /80% Timeliness Compliance \+ 20% Submission Efficiency/);
    assert.match(performance, /Time allowed/);
    assert.match(performance, /Time used/);
    assert.match(performance, /source start ' \+ item\.start_date/);
    assert.match(performance, /accepted PENRO receipt ' \+ item\.received/);
    assert.match(performance, /not a days-late value/);
    assert.doesNotMatch(performance, /Official complete-period assessment|Assessment settings|<OfficeTatAssessmentSettings/);
});

test('stored assessment policy, tie handling and source classification remain available', () => {
    assert.match(assessment, /recorded-live-source-v1/);
    assert.match(assessment, /saveTieDecision/);
    assert.match(assessment, /technical_on_time_count/);
});
