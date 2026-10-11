import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { assessmentPeriodKey, parseAssessmentPeriodKey, selectAssessmentType } from '../../resources/js/Pages/Admin/OfficeTat/assessmentPeriodSelection.mjs';

const page = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');

test('assessment selection serializes semestral and annual keys without a semester for Annual', () => {
    assert.deepEqual(parseAssessmentPeriodKey('2026-H1'), { type: 'semestral', year: 2026, semester: 'H1' });
    assert.deepEqual(parseAssessmentPeriodKey('2026'), { type: 'annual', year: 2026, semester: null });
    assert.equal(assessmentPeriodKey({ type: 'semestral', year: 2026, semester: 'H2' }), '2026-H2');

    const annual = selectAssessmentType({ type: 'semestral', year: 2026, semester: 'H2' }, 'annual');
    assert.deepEqual(annual, { type: 'annual', year: 2026, semester: null });
    assert.equal(assessmentPeriodKey(annual), '2026');
    assert.equal(assessmentPeriodKey(selectAssessmentType(annual, 'semestral')), '2026-H1');
});

test('Office TAT exposes only assessment type/year/semester and sends the canonical selection', () => {
    assert.match(page, /label="Assessment type"/);
    assert.match(page, /label="Assessment year"/);
    assert.match(page, /assessment\?\.type === 'semestral' && <FloatingSelect label="Semester"/);
    assert.match(page, /delete query\.semester/);
    assert.match(page, /query\.processing_period = values\.processing_period \|\| assessmentPeriodKey\(assessment\)/);
    assert.doesNotMatch(page, /Processing quarter|options\?\.periods/);
    assert.match(page, /processing_period_label/);
});
