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

test('unit switching waits for server props before changing the visible report tab', async () => {
    const source = await readFile(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /setReportUnit|const \[reportUnit/);
    assert.match(source, /officeTatDisplayUnit\(recordedAssessment, selectedUnit\)/);
    assert.match(source, /selectedUnit=\{displayedReportUnit\}/);
});
