import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../../resources/js/Pages/SubmissionTracking/Archives.jsx', import.meta.url), 'utf8');
const actions = await readFile(new URL('../../resources/js/Utils/finalArchiveActions.mjs', import.meta.url), 'utf8');
const actionPanel = await readFile(new URL('../../resources/js/Components/SubmissionTracking/FinalArchiveRecordActions.jsx', import.meta.url), 'utf8');
const statusTabs = await readFile(new URL('../../resources/js/Components/SubmissionTracking/FinalArchiveStatusTabs.jsx', import.meta.url), 'utf8');
const reader = await readFile(new URL('../../resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx', import.meta.url), 'utf8');
const readerDialog = await readFile(new URL('../../resources/js/Components/SubmissionTracking/SubmissionTrackingReaderDialog.jsx', import.meta.url), 'utf8');
const trackingPage = await readFile(new URL('../../resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');
const trackingPreview = await readFile(new URL('../../resources/js/Components/SubmissionTracking/DocumentPreviewDialog.jsx', import.meta.url), 'utf8');
const routingAttachment = await readFile(new URL('../../resources/js/Components/SubmissionTracking/RoutingAttachmentField.jsx', import.meta.url), 'utf8');
const card = await readFile(new URL('../../resources/js/Components/SubmissionTracking/FinalArchiveCard.jsx', import.meta.url), 'utf8');
const results = await readFile(new URL('../../resources/js/Components/SubmissionTracking/FinalArchiveResults.jsx', import.meta.url), 'utf8');
const toggle = await readFile(new URL('../../resources/js/Components/SubmissionTracking/FinalArchiveDisplayToggle.jsx', import.meta.url), 'utf8');

test('Archives page uses the same paginated records for Grid and List and opens a compact reader', () => {
    assert.match(statusTabs, /label: 'Archives'/);
    assert.match(statusTabs, /Awaiting Archive/);
    assert.match(statusTabs, /Awaiting Final Receipt/);
    assert.match(actionPanel, /Receive Final MOVs from Regional Office/);
    assert.match(actionPanel, /Attach Signed Set and Archive/);
    assert.match(actionPanel, /Retry Archive/);
    assert.match(page, /useState\('grid'\)/);
    assert.match(page, /<FinalArchiveDisplayToggle value=\{displayMode\} onChange=\{setDisplayMode\}/);
    assert.match(page, /<FinalArchiveResults rows=\{visibleRows\} displayMode=\{displayMode\}/);
    assert.match(results, /rows=\{safeRows\}/);
    assert.match(results, /onRowClick=\{row => onOpen\?\.\(row, 'document'\)\}/);
    assert.match(toggle, /onChange\?\.\('grid'\)/);
    assert.match(toggle, /onChange\?\.\('list'\)/);
    assert.match(card, /aria-label=\{`Open \$\{title\}/);
    assert.match(card, /role="menu"/);
    assert.match(card, /event\.key !== 'Escape'/);
    assert.match(page, /paginationLinks\.length > 3 && <div className="mt-4"><LaravelPaginationLinks links=\{paginationLinks\}/);
    assert.match(page, /<PageHeader title="Archives" description="Find final signed returns, earlier Dispatch copies, and reports waiting for archive steps\."/);
    assert.match(page, /Final signed Regional return/);
    assert.match(page, /Earlier Dispatch copy/);
    assert.doesNotMatch(page, /rounded-xl border border-slate-100 bg-white px-4 py-3/);
    assert.match(page, /open=\{Boolean\(selected\) && !modal\}/);
    assert.match(readerDialog, /role="tab"/);
    assert.match(readerDialog, /aria-label="Close reader"/);
    assert.match(readerDialog, /aria-label=\{expanded \? 'Restore reader size' : 'Expand reader'\}/);
    assert.match(readerDialog, /useModalMotion\(open/);
    assert.match(actionPanel, /selected\.latest_manifest\?\.retry_available === true/);
    assert.match(reader, /Archive verification is processing\./);
    assert.match(page, /shouldPollFinalArchiveStatus/);
    assert.match(page, /only: \['selected'\]/);
    assert.match(page, /FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS/);
    assert.doesNotMatch(page, /router\.reload\(/);
    assert.doesNotMatch(page, /Regional-return copies remain scoped to each report source/);
});

test('receipt date, signed files, and confirmation submit through one archive form', () => {
    assert.equal((page.match(/<CrudFormModal open=/g) || []).length, 1);
    assert.match(page, /type="datetime-local"/);
    assert.match(actions, /final-archives\.store/);
    assert.match(actions, /final-archives\.retry/);
    assert.match(actions, /latest_manifest\?\.retry_available !== true/);
    assert.match(reader, /FinalArchiveRecordActions/);
    assert.match(reader, /activeFile\?\.preview_url/);
    assert.match(reader, /activeFile\?\.download_url/);
    assert.match(page, /Receive and Archive/);
    assert.match(page, /earliestManilaReceiptInput/);
    assert.match(page, /I reviewed this complete returned file set and confirm its signatures are complete/);
    assert.match(page, /selected\?\.receipt \? 'Attach Signed Set and Archive' : 'Receive and Archive'/);
    assert.doesNotMatch(page, /makes no Drive call/);
    assert.match(page, /PamoReportPendingAnimation intent="archive"/);
    assert.match(page, /page\?\.props\?\.flash\?\.archive_status === 'archived'/);
});

test('earlier Dispatch copies retain a distinct label and remain outside final receipt and retry states', () => {
    assert.match(reader, /This is an earlier Dispatch archive copy/);
    assert.match(reader, /isEarlierDispatchCopy = !selected\?\.latest_manifest/);
    assert.match(reader, /archiveIsVerified \|\| isEarlierDispatchCopy/);
    assert.match(reader, /Earlier Dispatch copy/);
    assert.match(page, /row\.earlier_dispatch_copy \? 'Earlier Dispatch copy' : 'Final signed Regional return'/);
    assert.match(page, /Earlier Dispatch copies are labeled separately and do not prove a Regional receipt/);
});

test('only the archive reader reserves the fixed toolbar space; Tracking preview remains separate from full details', () => {
    assert.match(reader, /avoidBottomDebugbar\s*\/>/);
    assert.match(readerDialog, /avoidBottomDebugbar = false/);
    assert.doesNotMatch(trackingPreview, /avoidBottomDebugbar/);
    assert.doesNotMatch(routingAttachment, /avoidBottomDebugbar/);
    assert.match(trackingPage, /presentation="document-only"/);
    assert.match(trackingPage, /onPreview=\{setPreviewRow\}/);
    assert.match(trackingPage, /View Full Details/);
});

test('signature confirmation follows the complete multi-file selection and its private preview', () => {
    assert.match(page, /signature_confirmation: false/);
    assert.match(page, /multiple required disabled=\{archiveForm\.processing\}/);
    assert.match(page, /signature_confirmation: false/);
    assert.match(page, /complete returned file set and confirm its signatures are complete/);
    assert.match(page, /does not cryptographically validate signatures/);
    assert.match(page, /URL\.createObjectURL\(file\)/);
    assert.doesNotMatch(page, /maxFiles=\{1\}|multiple=\{false\}/);
});

test('archive redirect and selected-page failures stop the overlay and preserve the current intent', () => {
    assert.match(actions, /onHttpException: response =>[\s\S]*?return false/);
    assert.match(actions, /onNetworkError: \(\) =>[\s\S]*?return false/);
    assert.match(actions, /registerFinalArchivePageGuard/);
    assert.match(page, /archiveRequestError && !selected && <div role="alert"/);
    assert.match(page, /finalArchiveVisitErrorOptions\(handleArchiveRequestFailure\)/);
    assert.match(page, /archiveForm\.processing \? <PamoReportPendingAnimation intent="archive"/);
    assert.match(reader, /retrying && <div[^>]*data-cds-busy-presentation="success-sized"/);
    assert.match(reader, /<PamoReportPendingAnimation intent="retry"/);
    assert.match(page, /Receipt recorded; archive needs retry/);
    assert.match(page, /flash\.archive_error_code === 'google_drive_invalid_grant'/);
    assert.match(page, /restore authorization before retrying this retained manifest/);
    assert.match(actions, /The receipt or archive may already be recorded/);
    assert.doesNotMatch(page, /router\.reload\(/);
});
