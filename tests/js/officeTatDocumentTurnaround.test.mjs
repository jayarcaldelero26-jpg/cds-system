import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    documentAverageLabel,
    formatWorkingDays,
} from '../../resources/js/Pages/Admin/OfficeTat/documentTurnaroundPresentation.mjs';

test('document turnaround uses working-day durations and distinct empty states', () => {
    assert.equal(formatWorkingDays(0), '0 working days');
    assert.equal(formatWorkingDays(1), '1 working day');
    assert.equal(formatWorkingDays(2), '2 working days');
    assert.equal(documentAverageLabel({ reports: 0 }), 'No processed reports');
    assert.equal(documentAverageLabel({ reports: 2, received_count: 0, average_supporting_documents: 0 }), 'No completed turnaround yet');
    assert.equal(documentAverageLabel({ reports: 2, received_count: 1, average_supporting_documents: 0 }), 'Timing unavailable');
    assert.equal(documentAverageLabel({ reports: 3, average_supporting_documents: 2, average_working_days: 2.34 }), '2.34 working days');
    assert.equal(documentAverageLabel({ reports: 30, average_supporting_documents: 30, average_working_days: 1 / 30 }), '0.03 working days');
});

test('document turnaround renders the shared clickable participant table and full details on demand', async () => {
    const source = await readFile(new URL('../../resources/js/Pages/Admin/OfficeTat/DocumentTurnaround.jsx', import.meta.url), 'utf8');
    assert.match(source, /<CrudTable/);
    assert.match(source, /onRowClick=\{row => setDetailRow\(row\)\}/);
    assert.match(source, /focusRowOnClick/);
    assert.match(source, /rowAriaLabel=\{/);
    assert.match(source, /<CrudDetailsModal/);
    assert.match(source, /onClose=\{\(\) => setDetailRow\(null\)\}/);
    assert.match(source, /row\.documents\.map\(report =>/);
    assert.match(source, /First submission/);
    assert.match(source, /PENRO Records receipt/);
    assert.match(source, /Route timeline/);
    assert.match(source, /Working days from first submission until PENRO Records receives the report\./);
    assert.match(source, /Mon–Thu, excluding calendar holidays\./);
    assert.match(source, /setDetailRow\(next\)/);
    assert.doesNotMatch(source, /View details|Action column|office-tat-turnaround-participant/);
});

test('Office TAT page passes its selected assessment and renders the Document TAT panel', async () => {
    const source = await readFile(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
    assert.match(source, /import DocumentTurnaround from '\.\/DocumentTurnaround'/);
    assert.match(source, /documentTurnaround = null/);
    assert.match(source, /<DocumentTurnaround[\s\S]*?data=\{documentTurnaround\}/);
});
