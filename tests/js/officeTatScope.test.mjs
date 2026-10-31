import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { isValidLiveAnalytics } from '../../resources/js/Pages/Admin/OfficeTat/liveRoutingAveragePayload.mjs';
import { dispatchModalKeydown } from '../../resources/js/Components/ModalMotion/modalLayer.mjs';

const officeTat = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const historicalReports = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReports.jsx', import.meta.url), 'utf8');
const liveRoutingAverage = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/LiveRoutingAverage.jsx', import.meta.url), 'utf8');
const officeTatController = readFileSync(new URL('../../app/Http/Controllers/OfficeTatController.php', import.meta.url), 'utf8');
const complianceAssessments = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
const participantRowActivation = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/participantRowActivation.mjs', import.meta.url), 'utf8');
const detailsModal = readFileSync(new URL('../../resources/js/Components/Crud/CrudDetailsModal.jsx', import.meta.url), 'utf8');
const crudTable = readFileSync(new URL('../../resources/js/Components/Crud/CrudTable.jsx', import.meta.url), 'utf8');

test('Office TAT separates the two reporting groups and makes document turnaround the main measure', () => {
    assert.match(officeTat, /conservation:\s*'Conservation'/);
    assert.match(officeTat, /development:\s*'Development'/);
    assert.match(officeTat, /tabs=\{authorizedUnits\.filter\(unit => unitLabels\[unit\]\)\.map\(unit => \[unit, unitLabels\[unit\]\]\)\}/);
    assert.match(officeTat, /label="Reporting group"/);
    assert.match(officeTat, /<DocumentTurnaround/);
    assert.match(officeTat, /How this is calculated/);
    assert.doesNotMatch(officeTat, /CENRO processing average|PAMO preparation average/);
    assert.doesNotMatch(officeTat, /label="Average TAT office category"/);
});

test('historical date ranges keep optional To semantics through the shared date picker', () => {
    assert.match(historicalReports, /dateField\('historical-conducted-from', 'Date Conducted — From'[\s\S]*?true\)/);
    assert.match(historicalReports, /dateField\('historical-conducted-to', 'Date Conducted — To \(optional\)'/);
    assert.match(historicalReports, /actualDate=\{actualDate\}/);
    assert.match(historicalReports, /historical-revenue-deadline[\s\S]*?true, false,/);
    assert.doesNotMatch(historicalReports, /<input[^>]*type="date"/);
});

test('historical list and encoder render shared controls, modal, table, and protected upload', () => {
    for (const control of ['FilterToolbar', 'FloatingInput', 'FloatingSelect', 'ScopedOptionSelect', 'CrudFormModal', 'CrudTable', 'AttachmentDropzone', 'HistoricalRecordBadge']) {
        assert.match(historicalReports, new RegExp(control));
    }
    assert.match(historicalReports, /<option value="">Choose document type<\/option>/);
    assert.match(historicalReports, /onChange=\{event => set\('document_type', event\.target\.value\)\}/);
    assert.match(historicalReports, /errors=\{form\.errors\}/);
    assert.match(historicalReports, /processing=\{form\.processing\}/);
    assert.match(historicalReports, /value => set\('original_date_received_penro', value\)/);
    assert.match(historicalReports, /title="Encode historical report"[\s\S]*?icon=\{<Icon icon="lucide:archive" width="18" height="18" aria-hidden="true" \/>}/);
});

test('live routing average is CENRO live-only, weighted, and separate from report scoring', () => {
    assert.match(officeTatController, /'category' => 'CENRO'/);
    assert.match(officeTatController, /'evidence_type' => 'live'/);
    assert.match(liveRoutingAverage, /CENRO processing average/);
    assert.match(liveRoutingAverage, /const average = completed > 0 \? seconds \/ completed : null;/);
    assert.match(liveRoutingAverage, /This CENRO interval is separate from Conservation and Development TC, SE and overall score/);
    assert.doesNotMatch(liveRoutingAverage, /Recognition/i);
    assert.match(liveRoutingAverage, /Live routing data is unavailable or does not match the selected period and as-of date/);
});

test('live payload rejects wrong category, evidence type, dates and malformed rows', () => {
    const filters = { processing_period: '2026-H2', as_of_date: '2026-10-26' };
    const valid = {
        category: 'CENRO',
        evidence_type: 'live',
        processing_period: '2026-H2',
        as_of_date: '2026-10-26',
        rows: [{
            office: 'CENRO Baganga',
            documents_received: 2,
            tat_completed_documents: 1,
            tat_elapsed_seconds_total: 100,
            tat_average_elapsed_seconds: 100,
            pending: 1,
            tat_missing_evidence: 0,
        }],
    };

    assert.equal(isValidLiveAnalytics(valid, filters), true);
    assert.equal(isValidLiveAnalytics({ ...valid, category: 'PAMO' }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...valid, evidence_type: 'historical' }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...valid, processing_period: '2026-H1' }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...valid, as_of_date: '2026-10-25' }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...valid, rows: [{ ...valid.rows[0], pending: 'invalid' }] }, filters), false);
    assert.equal(isValidLiveAnalytics({ ...valid, rows: [] }, filters), true);
});
test('participant report details open accessibly and reset when the participant changes', () => {
    assert.match(complianceAssessments, /onRowClick=\{row => setDetailRow\(row\)\}/);
    assert.match(complianceAssessments, /focusRowOnClick/);
    assert.match(complianceAssessments, /rowFocusVisibleOnly/);
    assert.match(complianceAssessments, /rowAriaLabel=\{row => 'Open report details for ' \+ row\.name\}/);
    assert.match(crudTable, /tabIndex=\{clickable \? 0 : undefined\}/);
    assert.match(crudTable, /onKeyDown=\{event => activate\(event, row\)\}/);
    assert.match(crudTable, /aria-haspopup=\{clickable \? 'dialog' : undefined\}/);
    assert.match(participantRowActivation, /shouldActivateRow\(event, rowElement\)/);
    assert.match(complianceAssessments, /<CrudDetailsModal/);
    assert.match(complianceAssessments, /open=\{Boolean\(detailRow\)\}/);
    assert.match(complianceAssessments, /onClose=\{\(\) => setDetailRow\(null\)\}/);
    assert.ok(complianceAssessments.includes('label="Office / PA"'));
    assert.match(complianceAssessments, /key=\{String\(detailRow\.participant_key\)\}/);
    assert.match(complianceAssessments, /aria-expanded=\{expanded\}/);
    assert.match(complianceAssessments, /aria-controls=\{panelId\}/);
    assert.match(detailsModal, /closeLabel = 'Close Details'/);
    assert.match(detailsModal, /closeOnEscape = true/);
});
test('Escape closes the details modal while focus is on an expanded report accordion', () => {
    let closeCount = 0;
    let preventCount = 0;
    let stopCount = 0;
    const disclosure = { hasAttribute: name => name === 'data-cds-modal-disclosure' };
    const target = {
        closest: selector => selector === '[aria-expanded="true"]' ? disclosure : null,
    };
    const panel = { contains: node => node === disclosure };
    const entries = [{
        id: 'office-tat-details',
        panel,
        layer: { getClientRects: () => [{}] },
        zIndex: 50,
        order: 1,
        closeOnEscape: true,
        onEscape: () => { closeCount += 1; },
    }];
    const event = {
        key: 'Escape',
        target,
        preventDefault: () => { preventCount += 1; },
        stopImmediatePropagation: () => { stopCount += 1; },
    };

    assert.equal(dispatchModalKeydown(event, entries), true);
    assert.equal(closeCount, 1);
    assert.equal(preventCount, 1);
    assert.equal(stopCount, 1);
});
