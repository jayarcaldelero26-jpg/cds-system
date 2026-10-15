import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../../resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');
const actions = await readFile(new URL('../../resources/js/Components/SubmissionTracking/PambMovActions.jsx', import.meta.url), 'utf8');

test('PAMB MOV prerequisites and decisions render in the shared custody action group', () => {
    const start = page.indexOf('Available Custody Actions');
    const end = page.indexOf('row.current_document &&', start);
    const group = page.slice(start, end);
    assert.ok(start >= 0 && end > start, 'shared action group and following document panel are present');
    assert.match(group, /<PambMovActions row=/);
    assert.equal((page.match(/<PambMovActions row=/g) || []).length, 1);
    assert.match(group, /custodyActions\.map/);
});

test('PAMB action controls use the shared compact button spacing and keep Submit for Review truthful', () => {
    assert.match(actions, /Submit for Review/);
    assert.match(actions, /Resubmit for Review/);
    assert.match(actions, /rounded-lg px-3 py-2 text-xs/);
});
