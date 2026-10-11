import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { presentOperationalStatus } from '../../resources/js/Utils/statusPresentation.mjs';
import { presentTimeliness } from '../../resources/js/Components/timelinessPresentation.mjs';

const statusComponent = readFileSync(new URL('../../resources/js/Components/OperationalStatus.jsx', import.meta.url), 'utf8');
const liveBadge = readFileSync(new URL('../../resources/js/Components/RecordTypeBadge.jsx', import.meta.url), 'utf8');
const historicalBadge = readFileSync(new URL('../../resources/js/Components/HistoricalRecordBadge.jsx', import.meta.url), 'utf8');
const historicalList = readFileSync(new URL('../../resources/js/Pages/Admin/Settings/HistoricalReports.jsx', import.meta.url), 'utf8');
const trackingPage = readFileSync(new URL('../../resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');
const pambTracker = readFileSync(new URL('../../resources/js/Pages/Bms/ReportSubmissionTracker.jsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

test('only canonical long pending and awaiting statuses receive compact presentation', () => {
    for (const [value, context] of [
        ['Awaiting Forward to CENRO CDS Chief', 'Awaiting Forward to CENRO CDS Chief'],
        ['Awaiting Receipt by Office of the PENRO', 'Awaiting Receipt by Office of the PENRO'],
        ['Awaiting Receipt by PENRO Records', 'Awaiting Receipt by PENRO Records'],
        ['Pending Submission by CENRO', 'Submission by CENRO'],
        ['Pending Receipt by PENRO', 'Pending Receipt by PENRO'],
        ['Pending Regional Endorsement', 'Pending Regional Endorsement'],
    ]) {
        assert.deepEqual(presentOperationalStatus(value), {
            badge: 'Pending', context, compact: true, state: 'pending',
        });
    }

    for (const value of ['Completed', 'Released', 'Late', 'Overdue', 'Needs Correction', 'Returned for Correction', 'Report Not Yet Submitted', 'Pending Submission', 'Pending']) {
        assert.equal(presentOperationalStatus(value).compact, false, value);
    }
});

test('compact pending status keeps its full accessible context outside the amber pill', () => {
    const caption = presentOperationalStatus('Pending Submission by CENRO');
    assert.equal(caption.context, 'Submission by CENRO');
    assert.equal(caption.badge, 'Pending');
    assert.equal(caption.context.includes('Pending Submission by CENRO'), false);
    assert.match(statusComponent, /role="group" aria-label=\{`\$\{presentation\.context\}; status Pending`\}/);
    assert.match(statusComponent, /block break-words text-xs font-semibold leading-4 text-gray-700 dark:text-gray-200[\s\S]*\{presentation\.context\}/);
    assert.match(statusComponent, /aria-label=\{`\$\{presentation\.context\}: Pending`\}/);
    assert.match(statusComponent, /ring-amber-200[\s\S]*>Pending</);
});

test('live PAMB rows suppress numeric historical evidence conditions in both status cells', () => {
    const branches = [...pambTracker.matchAll(/\{Boolean\(row\.is_historical\) && historicalKind !== 'conservation' &&/g)];
    assert.equal(branches.length, 2, 'Timeliness and Status of Submission use explicit boolean predicates');
    assert.equal(Boolean(0) && 'bms' !== 'conservation', false);
    assert.equal(Boolean(1) && 'bms' !== 'conservation', true);
    assert.equal(Boolean(1) && 'conservation' !== 'conservation', false);
});

test('only live Conservation pending rating values present as compact Not yet rated', () => {
    assert.deepEqual(presentTimeliness('Pending Submission by CENRO', { pendingAsUnrated: true }), {
        label: 'Not yet rated', unratedPending: true,
    });
    assert.deepEqual(presentTimeliness('Pending Receipt by PENRO', { pendingAsUnrated: true }), {
        label: 'Not yet rated', unratedPending: true,
    });
    assert.equal(presentTimeliness('Pending Submission by CENRO').label, 'Pending Submission by CENRO');
    for (const value of ['Outstanding', 'Late', 'No Data', 'Pending Regional Endorsement']) {
        assert.deepEqual(presentTimeliness(value, { pendingAsUnrated: true }), { label: value, unratedPending: false });
    }
    assert.match(pambTracker, /TimelinessBadge value=\{row\.timeliness\} pendingAsUnrated=\{!Boolean\(row\.is_historical\) && historicalKind === 'conservation'\}/);
    assert.match(pambTracker, /TimelinessBadge value=\{selectedReport\.timeliness\} pendingAsUnrated=\{!isHistorical && historicalKind === 'conservation'\}/);
});

test('Incoming stage and deadline badges keep the routing action and deadline presentation paths', () => {
    const deadlineStart = trackingPage.indexOf('key: "deadline_submission"');
    const deadlineEnd = trackingPage.indexOf('key: "open"', deadlineStart);
    const deadlineCell = trackingPage.slice(deadlineStart, deadlineEnd);
    const complianceValue = trackingPage.indexOf('routing.compliance_status || row.timeliness');
    const complianceDetail = trackingPage.slice(complianceValue - 180, complianceValue + 180);

    assert.match(trackingPage, /const Badge = \(\{ value \}\) => <OperationalStatus value=\{value\}/);
    assert.match(trackingPage, /label: "Current Status"[\s\S]*<Badge value=\{compactStatusFor\(row\)\} \/>/);
    assert.match(trackingPage, /label: "Required Action"[\s\S]*\{requiredActionFor\(row\)\}/);
    assert.match(deadlineCell, /\{plainDate\(row\.deadline_submission\) \|\|/);
    assert.match(deadlineCell, /<Badge value=\{routingFor\(row\)\.compliance_status\} \/>/);
    assert.ok(complianceValue >= 0, 'detail Compliance keeps the canonical compliance/timeliness value');
    assert.match(complianceDetail, /<Badge[\s\S]*value=\{/);
    assert.match(trackingPage, /routingFor\(row\)\.actions\?\.\[0\]\?\.action_label \|\| nextActionFor\(row\)/);
});

test('Live and Historical badges share pill geometry while preserving historical navigation and passive list behavior', () => {
    assert.match(liveBadge, /recordTypeBadgeBaseClass/);
    assert.match(liveBadge, /isHistorical[\s\S]*HistoricalRecordBadge/);
    assert.match(liveBadge, /aria-label="Live record"/);
    assert.match(historicalBadge, /recordTypeBadgeBaseClass/);
    assert.match(historicalBadge, /passive \? null/);
    assert.match(historicalBadge, /stopHistoricalRecordEvent/);
    assert.match(historicalList, /<HistoricalRecordBadge source=\{record\.source\} id=\{record\.id\} passive \/>/);
    assert.match(css, /record-type-badge__live-indicator::after[\s\S]*animation: record-type-live-pulse/);
    assert.match(css, /prefers-reduced-motion: reduce[\s\S]*record-type-badge__live-indicator::after \{ animation: none/);
});
