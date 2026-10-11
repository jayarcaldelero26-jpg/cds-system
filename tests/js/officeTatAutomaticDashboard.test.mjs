import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const performance = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
const settings = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/OfficeTatAssessmentSettings.jsx', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../../app/Http/Controllers/OfficeTatController.php', import.meta.url), 'utf8');
const assessment = readFileSync(new URL('../../app/Services/OfficeTatComplianceAssessmentService.php', import.meta.url), 'utf8');

test('Office TAT defaults to the two shared views and keeps assessment period controls simple', () => {
    assert.match(page, /tabs=\{\[\["report-performance", "Report Performance"\], \["average-tat", "Average TAT"\]\]\}/);
    assert.match(page, /label="Assessment type"/);
    assert.match(page, /label="Assessment year"/);
    assert.match(page, /assessment\?\.type === 'semestral' && <FloatingSelect label="Semester"/);
    assert.match(page, /query\.processing_period = values\.processing_period \|\| assessmentPeriodKey\(assessment\)/);
    assert.doesNotMatch(page, /Monthly assessment|Quarterly assessment|assessment_period.*quarter/i);
});

test('Report Performance renders one authorized unit and separates live records from official coverage', () => {
    assert.match(page, /data=\{recordedAssessment\}/);
    assert.match(page, /authorizedUnits=\{authorizedUnits\}/);
    assert.match(controller, /recordedAssessments\(\$request->user\(\), \$selectedUnit/);
    assert.match(controller, /effectiveUnits\(\$request->user\(\)\)/);
    assert.match(performance, /Only unique live source identities with supported dates are scored/);
    assert.match(performance, /Official complete-period assessment/);
    assert.match(performance, /rowFocusVisibleOnly/);
    assert.match(performance, /selectedUnit, periodKey, asOfDate/);
});

test('settings stay behind one optional configure-gated Assessment settings action', () => {
    assert.match(performance, /<Button[^>]*>[\s\S]*?Assessment settings<\/Button>/);
    assert.match(performance, /title="Assessment settings"/);
    assert.match(performance, /canConfigure && complianceAssessments/);
    assert.match(performance, /<OfficeTatAssessmentSettings/);
    assert.match(settings, /Edit one selected PA workflow/);
    assert.match(settings, /TechnicalClassificationEditor/);
    assert.match(assessment, /recorded-live-source-v1/);
    assert.match(assessment, /saveTieDecision/);
});
