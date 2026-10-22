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
    assert.match(performance, /full-period coverage incomplete/);
    assert.match(performance, /No eligible reports (?:could be assessed|were assessable) for the selected filters and date/);
    assert.match(performance, /Selected-date due status/);
    assert.match(performance, /Not matched to a registered report/);
    assert.match(performance, /function displaySourceType\(sourceKey\)[\s\S]*?return String\(sourceKey \|\| ''\)\.toLowerCase\(\) === 'engp' \? 'ENGP' : sourceKey;/);
    assert.match(performance, /Source reference/);
    assert.match(performance, /Eligible reports/);
    assert.match(performance, /Received by selected date/);
    assert.match(performance, /Assessed reports \(N\)/);
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

test('Average TAT wording describes completed intervals and pending handoffs', () => {
    assert.match(page, /No completed (?:office handoffs|intervals)/);
    assert.doesNotMatch(page, /one sample per source document|interval sample|Completed samples|completed report samples/i);
});
test('unsupported source detail renders absent dates safely and keeps identity exclusion separate', () => {
    assert.match(performance, /return 'Unavailable';/);
    assert.match(performance, /item\.start_date \|\| 'Incomplete'/);
    assert.match(performance, /item\.deadline \|\| 'Incomplete'/);
    assert.match(performance, /item\.received \|\|/);
    assert.match(performance, /No accepted receipt by cutoff|Not recorded by selected date/);
    assert.match(performance, /entries\.filter\(\(\[, value\]\) => value !== null && value !== undefined/);
    assert.match(performance, /Not matched to a registered report/);
    assert.match(performance, /Selected-date due status/);
});
