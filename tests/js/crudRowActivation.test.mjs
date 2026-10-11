import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { shouldActivateRow } from '../../resources/js/Components/Crud/rowActivation.mjs';

const entry = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReports.jsx', import.meta.url), 'utf8');
const table = readFileSync(new URL('../../resources/js/Components/Crud/CrudTable.jsx', import.meta.url), 'utf8');
const detail = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReportDetail.jsx', import.meta.url), 'utf8');
const preview = readFileSync(new URL('../../resources/js/Components/SubmissionTracking/DocumentPreviewDialog.jsx', import.meta.url), 'utf8');
const request = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/historicalDetailRequest.mjs', import.meta.url), 'utf8');

test('row activation handles click, Enter, and Space while leaving nested controls alone', () => {
    const row = {};
    const plainTarget = { closest: () => row };
    const link = {};
    const linkTarget = { closest: () => link };

    assert.equal(shouldActivateRow({ type: 'click', target: plainTarget }, row), true);
    assert.equal(shouldActivateRow({ type: 'keydown', key: 'Enter', target: row }, row), true);
    assert.equal(shouldActivateRow({ type: 'keydown', key: ' ', target: row }, row), true);
    assert.equal(shouldActivateRow({ type: 'keydown', key: 'ArrowDown', target: row }, row), false);
    assert.equal(shouldActivateRow({ type: 'click', target: linkTarget }, row), false);
    assert.equal(shouldActivateRow({ type: 'keydown', key: 'Enter', target: linkTarget }, row), false);
});

test('historical list opens protected details in a shared modal and cancels stale requests', () => {
    assert.match(entry, /onRowClick=\{openDetails\}/);
    assert.doesNotMatch(entry, /key: 'action', label: 'Record'/);
    assert.doesNotMatch(entry, /href=\{record\.url\} className/);
    assert.match(request, /headers: \{ Accept: 'application\/json' \}/);
    assert.match(entry, /const controller = new AbortController\(\)/);
    assert.match(entry, /return \(\) => \{[\s\S]*controller\.abort\(\)/);
    assert.match(entry, /detailRequestGuardRef\.current\.invalidate\(\)/);
    assert.match(entry, /closeOnOverlayClick/);
    assert.match(entry, /suspendKeyboardHandling=\{previewOpen\}/);
    assert.match(entry, /<HistoricalReportDetail record=\{detailRecord\} embedded/);
    assert.match(table, /onRowClick\(row, event\)/);
    assert.match(detail, /const sourceModuleHref = historicalSourceModuleHref\(record\)/);
    assert.match(detail, /embedded && sourceModuleHref && <Link href=\{sourceModuleHref\}/);
    assert.match(preview, /createPortal\(/);
    assert.match(preview, /useModalMotion\(open/);
    assert.match(preview, /onClose,/);
    assert.match(preview, /data-cds-modal-layer/);
});
