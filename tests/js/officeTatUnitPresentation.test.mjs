import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { officeTatDisplayUnit } from '../../resources/js/Pages/Admin/OfficeTat/unitPresentation.mjs';

test('the recorded roster unit controls labels when Inertia props are temporarily out of sync', () => {
    assert.equal(officeTatDisplayUnit({ unit: 'conservation' }, 'development'), 'conservation');
    assert.equal(officeTatDisplayUnit({ unit: 'development' }, 'conservation'), 'development');
    assert.equal(officeTatDisplayUnit(null, 'development'), 'development');
    assert.equal(officeTatDisplayUnit({ unit: 'unexpected' }, 'unexpected'), 'conservation');
});

test('the authorized recognition unit stays aligned with the returned assessment and navigation', async () => {
    const source = await readFile(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /setReportUnit|const \[reportUnit/);
    assert.match(source, /const displayedReportUnit = officeTatDisplayUnit\(recordedAssessment, selectedUnit\)/);
    assert.match(source, /const unit = next\.unit \|\| displayedReportUnit/);
    assert.match(source, /value=\{displayedReportUnit\}/);
    assert.match(source, /selectedUnit=\{displayedReportUnit\}/);
    assert.match(source, /encodeURIComponent\(displayedReportUnit \|\| ''\)/);
});