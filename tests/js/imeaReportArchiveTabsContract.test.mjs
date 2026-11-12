import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../../resources/js/Pages/Imea/ReportSubmissions.jsx', import.meta.url), 'utf8');
const tracker = await readFile(new URL('../../resources/js/Pages/Bms/ReportSubmissionTracker.jsx', import.meta.url), 'utf8');

test('IMEA report submissions pass verified archive tab counts to the shared tracker', () => {
    assert.match(page, /archiveTabCounts = null/);
    assert.match(page, /archiveTabCounts=\{archiveTabCounts\}/);
    assert.match(tracker, /archiveTabCounts && <ReportArchiveTabs counts=\{archiveTabCounts\} queryKey=\{archiveQueryKey\} \/>/);
});
