import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Compile the real components in memory. No browser, network, or output files.
async function component(name) {
    const componentPath = name.includes('/') ? name : `SubmissionTracking/${name}`;
    const inputPath = componentPath.startsWith('Pages/')
        ? `resources/js/${componentPath}.jsx`
        : `resources/js/Components/${componentPath}.jsx`;
    const bundle = await rolldown({
        input: resolve(inputPath),
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [{
            name: 'isolated-page-test-shims',
            resolveId(source) {
                if (source === '@inertiajs/react') return '\0inertia-ssr-shim';
                if (source === 'pdfjs-dist') return '\0pdfjs-ssr-shim';
                if (source === 'pdfjs-dist/build/pdf.worker.min.mjs?url') return '\0pdfjs-worker-ssr-shim';
            },
            load(id) {
                if (id === '\0pdfjs-ssr-shim') return 'export const GlobalWorkerOptions = {}; export const getDocument = () => { throw new Error("PDF rendering is unavailable in this markup fixture"); };';
                if (id === '\0pdfjs-worker-ssr-shim') return 'export default "/pdfjs-worker-fixture.mjs";';
                if (id === '\0inertia-ssr-shim') {
                    return 'export const Head = () => null; export const Link = () => null; export const router = {}; export const usePage = () => ({ props: {} }); export const useForm = () => ({});';
                }
                if (/\.(png|jpe?g|svg|webp|gif)$/.test(id)) return 'export default "test-image";';
            },
        }],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(createRequire(import.meta.url), module, module.exports);
    return module.exports;
}

const Preview = await component('DocumentPreviewDialog');
const PdfViewer = await component('PdfDocumentViewer');
const PambProgress = await component('PambMovProgress');
const PambActions = await component('PambMovActions');
const ReviewHistory = await component('SubmissionReviewHistory');
const AttachmentDropzone = await component('Attachments/AttachmentDropzone');
const RoutingTimeline = await component('DocumentRoutingTimeline');
const SubmissionTrackingPage = await component('Pages/SubmissionTracking/Index');
const SidebarDetails = SubmissionTrackingPage.SubmissionDetailsPanel;
const Progress = await component('SubmissionTrackingProgress');
const ReportContext = await component('SubmissionReportContext');
const NotificationComponents = await component('Notifications/NotificationBell');
const NotificationPanel = NotificationComponents.NotificationPanel;
const CrudFormModal = await component('Crud/CrudFormModal');
const UserManagement = await component('Pages/Admin/Users/Index');
const appStyles = readFileSync(resolve('resources/css/app.css'), 'utf8');
const previewDialogSource = readFileSync(resolve('resources/js/Components/SubmissionTracking/DocumentPreviewDialog.jsx'), 'utf8');
const submissionTrackingSource = readFileSync(resolve('resources/js/Pages/SubmissionTracking/Index.jsx'), 'utf8');
const render = row => renderToStaticMarkup(React.createElement(Preview, { open: true, row }));

const skippedPositionStep = (key, label) => ({
    key,
    label,
    status: 'skipped',
    display_status: 'skipped',
    display_status_label: 'Skipped by Routing Workflow Settings',
    event_type: null,
});

function capturedRouteRow(officeEnabled, tsdEnabled, version = 4) {
    const timeline = [
        { key: 'penro_records', label: 'PENRO Records', status: 'completed' },
        officeEnabled
            ? { key: 'office_penro', label: 'Office of the PENRO', status: 'completed' }
            : skippedPositionStep('office_initial_skipped', 'Office of the PENRO (initial routing)'),
        tsdEnabled
            ? { key: 'tsd_chief', label: 'PENRO TSD Chief', status: 'completed' }
            : skippedPositionStep('tsd_initial_skipped', 'PENRO TSD Chief'),
        ...(!officeEnabled
            ? [skippedPositionStep('office_final_skipped', 'Office of the PENRO (final review)')]
            : []),
        { key: 'transit_to_cds_focal', label: 'Forwarded to CDS Focal', status: 'current', display_status: 'current' },
        { key: 'penro_cds_focal', label: 'PENRO CDS Focal', status: 'pending' },
    ];

    return {
        source: 'bms',
        source_id: 2,
        can_transition: false,
        routing: {
            profile_label: 'Captured BMS route',
            current_stage: 'transit_to_cds_focal',
            current_status: 'In Transit',
            processing_percentage: 92,
            actions: [],
            routing_history: [],
            route_position: {
                version,
                office_penro_enabled: officeEnabled,
                penro_tsd_chief_enabled: tsdEnabled,
                snapshot_id: version === 1 ? 1 : 2,
            },
            timeline,
        },
    };
}

test('supported protected previews wait for a validated response before embedding a blob URL', () => {
    for (const mime_type of ['application/pdf', 'image/jpeg', 'image/png']) {
        const markup = render({ current_document: { name: 'synthetic', mime_type, can_preview: true, preview_url: '/attachments/aws-report/1/report?preview=1' }, mov_url: '/overview' });
        assert.match(markup, /Loading document preview/);
        assert.doesNotMatch(markup, /<iframe|<img|\/attachments\/aws-report\/1\/report/);
        assert.doesNotMatch(markup, /\/overview/);
    }
    assert.match(previewDialogSource, /startProtectedPreview\(url,/);
    assert.match(previewDialogSource, /<PdfDocumentViewer blob=\{current\.blob\}/);
    assert.match(previewDialogSource, /<img src=\{current\.url\}/);
});

test('PDF viewer exposes Fit Page, zoom, and bounded page controls', () => {
    const markup = renderToStaticMarkup(React.createElement(PdfViewer, { blob: new Blob(), title: 'Portrait report' }));
    assert.match(markup, /aria-label="PDF controls"/);
    assert.match(markup, /aria-label="Zoom out"/);
    assert.match(markup, />Fit Page</);
    assert.match(markup, /aria-label="Zoom in"/);
    assert.match(markup, /aria-label="Zoom percentage"/);
    assert.match(markup, /aria-label="Previous page" disabled/);
    assert.match(markup, /aria-label="Next page" disabled/);
    assert.match(markup, /class="[^"]*overflow-auto[^"]*" role="region" aria-label="PDF page"/);
    assert.match(markup, /class="hidden" aria-label="Portrait report, page 1 of 0"/);
    assert.match(markup, /Preparing PDF preview/);
    assert.match(markup, /aria-label="Zoom percentage"[^>]*>—%/);
    assert.match(markup, /Page — of —/);
});

test('unsupported and unverified MIME never embed, even with a PDF filename', () => {
    for (const mime_type of [null, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']) {
        const markup = render({ current_document: { name: 'looks-like.pdf', mime_type, can_preview: true, preview_url: '/attachments/source/1/mov?preview=1', download_url: '/attachments/source/1/mov?download=1' } });
        assert.doesNotMatch(markup, /<iframe|<img/);
        assert.match(markup, /Download Current Copy/);
        assert.match(markup, mime_type ? /cannot be previewed/ : /could not be verified/);
    }
});

test('missing current descriptor cannot revive other document URLs', () => {
    const markup = render({ current_document: { can_preview: true }, mov_url: '/overview', effective_document: { url: '/archive' }, mov_attachment: { url: '/history', mime_type: 'application/pdf' } });
    assert.doesNotMatch(markup, /<iframe|<img|\/overview|\/archive|\/history|Download Current Copy/);
    assert.match(markup, /No MOV\/report attachment is available/);
});

test('PAMB MOV Review retains milestones and actions without a second overall percentage or bar', () => {
    const markup = renderToStaticMarkup(React.createElement(PambProgress, {
        row: {
            mov_processing: {
                applicable: true,
                status_key: 'ready_for_release',
                percent: 70,
                status_label: 'Reviewed by CENRO CDS Chief - Ready for Release',
                workflow_status: 'Ready for CENRO Records Release',
                milestones: [{ key: 'submitted', label: 'MOV Submitted for Review', complete: true }],
                turnaround: { label: 'On time', deadline: '2026-09-30' },
            },
            mov_url: '/protected/current-mov',
            pamb_action_flags: { can_release: true },
            cenro_release_applicable: true,
        },
    }));

    assert.match(markup, /MOV Review/);
    assert.match(markup, /Ready for CENRO Records Release/);
    assert.match(markup, /MOV Submitted for Review/);
    assert.match(markup, /Release/);
    assert.doesNotMatch(markup, /MOV Processing Progress|70%|role="progressbar"|View MOV|protected\/current-mov/);
});

test('extracted MOV actions render for the current review owner', () => {
    const markup = renderToStaticMarkup(React.createElement(PambActions, {
        row: {
            mov_processing: { applicable: true, status_key: 'submitted_for_review' },
            pamb_action_flags: { can_review: true, can_release: false },
        },
        onReview: () => {},
    }));

    assert.match(markup, /Ready for Release/);
    assert.match(markup, /Needs Correction/);
    assert.doesNotMatch(markup, /Submit for Review|>Release</);
});

test('Submission Review History renders MOV and routing event actor context in its details surface', () => {
    const markup = renderToStaticMarkup(React.createElement(ReviewHistory, {
        row: {
            date_conducted: '2026-09-28',
            mov_processing: {
                applicable: true,
                review_history: [{
                    event_key: 'ready_for_release',
                    event_label: 'Marked Ready for Release',
                    recorded_by: 'Chief Reviewer',
                    recorded_role: 'CENRO CDS Chief',
                    recorded_office: 'CENRO Mati',
                    recorded_at: '2026-09-28T10:00:00+08:00',
                    remarks: 'Reviewed the signed copy.',
                }],
            },
            routing: { routing_history: [] },
        },
    }));

    assert.match(markup, /MOV Review History/);
    assert.match(markup, /Marked Ready for Release/);
    assert.match(markup, /Chief Reviewer/);
    assert.match(markup, /CENRO CDS Chief/);
    assert.match(markup, /CENRO Mati/);
    assert.match(markup, /Reviewed the signed copy\./);
});

test('Full Details hides duplicate inline routing history while Review history keeps distinct corrections, override metadata, and attachments', () => {
    const routingHistory = [
        {
            id: 301, key: 'returned_for_correction:301', label: 'Returned for Correction',
            occurred_at: '2026-09-28T10:00:00+08:00', recorded_by: 'CENRO Records User',
            actor_category: 'CENRO Records', actor_office: 'CENRO Baganga', remarks: 'First cycle correction.',
            correction: true, from: 'CENRO Records', to: 'CENRO CDS Focal Person',
            correction_reason: 'Missing signature', correction_detail: 'Add the chair signature.',
            administrative_override: true, override_for_category: 'CENRO CDS Chief', override_for_office: 'CENRO Baganga',
            attachment: { name: 'cycle-one.pdf', size: 2048, preview_url: '/safe/cycle-one', download_url: '/safe/cycle-one?download=1' },
        },
        {
            id: 302, key: 'returned_for_correction:302', label: 'Returned for Correction',
            occurred_at: '2026-09-28T10:00:00+08:00', recorded_by: 'CENRO Records User',
            actor_category: 'CENRO Records', actor_office: 'CENRO Baganga', remarks: 'Second cycle correction.',
            correction: true, from: 'CENRO Records', to: 'CENRO CDS Focal Person',
            correction_reason: 'Missing signature', correction_detail: 'Use the current signed version.',
            administrative_override: false,
            attachment: { name: 'cycle-two.pdf', size: 3072, preview_url: '/safe/cycle-two', download_url: '/safe/cycle-two?download=1' },
        },
    ];
    const row = {
        can_transition: false,
        routing: {
            profile_label: 'Conservation routing', actions: [], routing_history: routingHistory,
            timeline: [{ key: 'current', label: 'CENRO CDS Focal Person', status: 'current' }],
        },
    };
    const timeline = renderToStaticMarkup(React.createElement(RoutingTimeline, { row, hideRoutingHistory: true }));
    const history = renderToStaticMarkup(React.createElement(ReviewHistory, { row: { routing: { routing_history: routingHistory } } }));
    assert.doesNotMatch(timeline, /Complete Routing \/ Correction History|First cycle correction|cycle-one\.pdf/);
    assert.match(timeline, /Canonical Routing Progress/);
    assert.equal((history.match(/Returned for Correction/g) || []).length, 2);
    assert.match(history, /Admin Override/);
    assert.match(history, /Override for: CENRO CDS Chief/);
    assert.match(history, /CENRO Baganga/);
    assert.match(history, /First cycle correction\./);
    assert.match(history, /Second cycle correction\./);
    assert.match(history, /cycle-one\.pdf/);
    assert.match(history, /cycle-two\.pdf/);
    assert.match(history, /href="\/safe\/cycle-one"/);
    assert.match(history, /href="\/safe\/cycle-two"/);
});

test('attachment dropzone renders its accessible control and dark theme style hook', () => {
    const markup = renderToStaticMarkup(React.createElement(AttachmentDropzone, {
        id: 'routing-attachment',
        label: 'Updated Official Copy',
        files: [],
        onChange: () => {},
        accept: '.pdf',
        acceptedTypesHint: 'PDF',
        canManage: true,
    }));

    assert.match(markup, /data-cds-attachment-dropzone/);
    assert.match(markup, /<button[^>]*type="button"[^>]*id="routing-attachment"/);
    assert.match(markup, /Updated Official Copy/);
    assert.match(markup, /dark:text-slate-200/);
    assert.match(appStyles, /\.dark \.cds-file-dropzone\s*\{[^}]*background:\s*#111827;/);
    assert.match(appStyles, /\.dark \.cds-file-dropzone:hover[\s\S]*?background:\s*#10251f;/);
});

test('routing timeline omits absent summary values and wraps long office destinations', () => {
    const destination = 'Office of the PENRO Records and Information Management Unit';
    const markup = renderToStaticMarkup(React.createElement(RoutingTimeline, {
        row: {
            can_transition: false,
            routing: {
                last_updated: null,
                recorded_by: null,
                actions: [],
                routing_history: [],
                timeline: [{
                    key: 'records-forwarded',
                    label: 'Forwarded to Records',
                    status: 'completed',
                    event_type: 'forwarded',
                    occurred_at: '2026-09-28T10:00:00+08:00',
                    from: 'CENRO Community Environment and Natural Resources Unit',
                    to: destination,
                    office: destination,
                }],
            },
        },
    }));

    assert.match(markup, /Forwarded to Records/);
    assert.match(markup, new RegExp(destination));
    assert.match(markup, /break-words/);
    assert.doesNotMatch(markup, /Last Updated|Recorded By/);
});

test('terminal release presents Completed without a Current badge, pending age, or routing action', () => {
    const markup = renderToStaticMarkup(React.createElement(RoutingTimeline, {
        row: {
            can_transition: true,
            routing: {
                profile_label: 'CENRO-to-PENRO canonical routing',
                current_stage: 'released_to_regional',
                current_location: 'Regional Office',
                current_status: 'Completed',
                pending_since: null,
                working_days_pending: null,
                actions: [],
                routing_history: [],
                timeline: [{
                    key: 'released_to_regional',
                    label: 'Released / Endorsed to Regional Office',
                    status: 'current',
                    display_status: 'completed',
                    display_status_label: 'Completed',
                    event_type: 'released',
                    occurred_at: '2026-10-03T18:08:15+08:00',
                    pending_since: null,
                    working_days_pending: null,
                }],
            },
        },
    }));

    assert.match(markup, /Released \/ Endorsed to Regional Office/);
    assert.match(markup, /bg-green-50 text-green-800[^>]*>Completed<\/span>/);
    assert.doesNotMatch(markup, />Current<|Pending Since|Pending:|>Pending<|<button/);
    assert.match(submissionTrackingSource, /canAdminRoutingOverride && details && details\.routing\?\.actions\?\.length > 0/);
});

test('captured Office and TSD settings hide only synthetic skipped rows in the sidebar, Full Details, and Full Timeline', () => {
    for (const [officeEnabled, tsdEnabled] of [[true, true], [false, true], [true, false], [false, false]]) {
        const row = capturedRouteRow(officeEnabled, tsdEnabled);
        const detailsMarkup = renderToStaticMarkup(React.createElement(RoutingTimeline, { row }));
        const fullTimelineMarkup = renderToStaticMarkup(React.createElement(RoutingTimeline, {
            row,
            expandAll: true,
            onExpandAllChange: () => {},
        }));
        const skipped = row.routing.timeline.filter(step => step.status === 'skipped');
        const sidebarRow = {
            ...row,
            routing: {
                ...row.routing,
                current_stage: 'sidebar-current',
                timeline: [
                    { key: 'sidebar-previous', label: 'Previous real checkpoint', status: 'completed' },
                    ...skipped,
                    { key: 'sidebar-current', label: 'Current timeline stage', status: 'current' },
                    { key: 'sidebar-next', label: 'Next real checkpoint', status: 'pending' },
                ],
            },
        };
        const sidebarMarkup = renderToStaticMarkup(React.createElement(SidebarDetails, {
            row: sidebarRow,
            onViewFullDetails: () => {},
        }));

        for (const markup of [detailsMarkup, fullTimelineMarkup, sidebarMarkup]) {
            assert.doesNotMatch(markup, /Skipped by Routing Workflow Settings/);
        }
        assert.match(sidebarMarkup, /Previous real checkpoint/);
        assert.match(sidebarMarkup, /Current timeline stage/);
        assert.match(sidebarMarkup, /Next real checkpoint/);
        assert.equal(detailsMarkup.includes('Office of the PENRO'), officeEnabled);
        assert.equal(detailsMarkup.includes('PENRO TSD Chief'), tsdEnabled);
        assert.equal(fullTimelineMarkup.includes('Office of the PENRO'), officeEnabled);
        assert.equal(fullTimelineMarkup.includes('PENRO TSD Chief'), tsdEnabled);
    }

    const olderAllEnabledSnapshot = capturedRouteRow(true, true, 1);
    olderAllEnabledSnapshot.current_routing_settings = {
        version: 4,
        office_penro_enabled: false,
        penro_tsd_chief_enabled: false,
    };
    const olderMarkup = renderToStaticMarkup(React.createElement(RoutingTimeline, {
        row: olderAllEnabledSnapshot,
        expandAll: true,
        onExpandAllChange: () => {},
    }));
    assert.match(olderMarkup, /Office of the PENRO/);
    assert.match(olderMarkup, /PENRO TSD Chief/);
    assert.match(submissionTrackingSource, /This report uses routing version \{details\.routing\.route_position\.version\}/);

    const historicalEventsOnDisabledRoute = capturedRouteRow(false, false);
    historicalEventsOnDisabledRoute.routing.timeline.push(
        {
            key: 'historical-office-receipt',
            label: 'Historical Office of the PENRO receipt',
            status: 'completed',
            display_status: 'completed',
            event_type: 'received',
        },
        {
            key: 'historical-tsd-forward',
            label: 'Historical PENRO TSD Chief forwarding',
            status: 'completed',
            display_status: 'completed',
            event_type: 'forwarded',
        },
    );
    const historicalMarkup = renderToStaticMarkup(React.createElement(RoutingTimeline, {
        row: historicalEventsOnDisabledRoute,
        expandAll: true,
        onExpandAllChange: () => {},
    }));
    assert.match(historicalMarkup, /Historical Office of the PENRO receipt/);
    assert.match(historicalMarkup, /Historical PENRO TSD Chief forwarding/);
    assert.doesNotMatch(historicalMarkup, /Skipped by Routing Workflow Settings/);
});

test('disabled skipped rows are filtered before sidebar slicing and full timeline expansion counts', () => {
    const row = capturedRouteRow(false, false);
    const officeSkipped = skippedPositionStep('office_initial_skipped', 'Office of the PENRO (initial routing)');
    const tsdSkipped = skippedPositionStep('tsd_initial_skipped', 'PENRO TSD Chief');
    const fullTimelineRow = {
        ...row,
        routing: {
            ...row.routing,
            current_stage: 'current',
            timeline: [
                { key: 'current', label: 'Current timeline stage', status: 'current' },
                { key: 'next', label: 'Next checkpoint', status: 'pending' },
                officeSkipped,
                tsdSkipped,
                skippedPositionStep('office_final_skipped', 'Office of the PENRO (final review)'),
                { key: 'later-1', label: 'Later checkpoint one', status: 'pending' },
                { key: 'later-2', label: 'Later checkpoint two', status: 'pending' },
                { key: 'later-3', label: 'Later checkpoint three', status: 'pending' },
            ],
        },
    };
    const fullTimelineMarkup = renderToStaticMarkup(React.createElement(RoutingTimeline, { row: fullTimelineRow }));
    assert.doesNotMatch(fullTimelineMarkup, /Skipped by Routing Workflow Settings/);
    assert.match(fullTimelineMarkup, /Show 3 more steps/);

    const sidebarRow = {
        ...row,
        routing: {
            ...row.routing,
            current_stage: 'sidebar-current',
            timeline: [
                { key: 'sidebar-previous', label: 'Previous real checkpoint', status: 'completed' },
                officeSkipped,
                tsdSkipped,
                { key: 'sidebar-current', label: 'Current timeline stage', status: 'current' },
                { key: 'sidebar-next', label: 'Next real checkpoint', status: 'pending' },
            ],
        },
    };
    const sidebarMarkup = renderToStaticMarkup(React.createElement(SidebarDetails, {
        row: sidebarRow,
        onViewFullDetails: () => {},
    }));
    assert.doesNotMatch(sidebarMarkup, /Skipped by Routing Workflow Settings/);
    assert.match(sidebarMarkup, /Previous real checkpoint/);
});

test('active one-hundred-percent stages remain Current and Ready for Release remains an active MOV phase', () => {
    const activeRouting = renderToStaticMarkup(React.createElement(RoutingTimeline, {
        row: {
            can_transition: true,
            routing: {
                profile_label: 'CENRO-to-PENRO canonical routing',
                current_stage: 'penro_cds_chief',
                processing_percentage: 100,
                actions: [],
                routing_history: [],
                timeline: [{
                    key: 'penro_cds_chief', label: 'PENRO CDS Chief', status: 'current', display_status: 'current',
                    occurred_at: '2026-10-02T09:00:00+08:00', pending_since: '2026-10-02', working_days_pending: 1,
                }],
            },
        },
    }));
    const readyMov = renderToStaticMarkup(React.createElement(PambProgress, {
        row: {
            mov_processing: {
                applicable: true,
                status_key: 'ready_for_release',
                workflow_status: 'Ready for CENRO Records Release',
                status_label: 'Reviewed by CENRO CDS Chief - Ready for Release',
                milestones: [{ key: 'released_by_cenro', label: 'Released by CENRO to PENRO', complete: false, current: false }],
                turnaround: { label: 'DAY 0 OF 7 · 9 WORKING DAYS REMAINING', deadline: '2026-10-20', remaining: 9 },
            },
            pamb_action_flags: {},
        },
    }));

    assert.match(activeRouting, />Current<|Current checkpoint/);
    assert.doesNotMatch(activeRouting, />Completed<|Pending Since/);
    assert.match(readyMov, /Ready for CENRO Records Release/);
    assert.match(readyMov, /DAY 0 OF 7/);
    assert.doesNotMatch(readyMov, />Completed</);
});

test('Manual PAMB canonical workspace payload renders through the shared details timeline', () => {
    const row = {
        source: 'conservation',
        workflow_key: 'updating_pamb_manual',
        pamb_routing_applicable: false,
        can_transition: false,
        routing_timeline: [],
        routing: {
            profile_key: 'canonical_cenro_penro_regional',
            profile_label: 'CENRO-to-PENRO canonical routing',
            current_stage: 'transit_to_cenro_chief',
            current_location: 'CENRO CDS Chief',
            current_status: 'In Transit',
            processing_percentage: 20,
            next_expected_action: 'Receive',
            actions: [],
            routing_history: [],
            timeline: [
                { key: 'cenro_preparation', label: 'CENRO CDS Focal Person', status: 'completed', event_type: 'stage' },
                { key: 'transit_to_cenro_chief', label: 'Forwarded to CENRO CDS Chief', status: 'current', event_type: 'forwarded', from: 'CENRO CDS Focal Person', to: 'CENRO CDS Chief', office: 'CENRO Mati', occurred_at: '2026-08-03T09:00:00+08:00' },
                { key: 'cenro_chief', label: 'CENRO CDS Chief', status: 'pending', action_label: 'Receive' },
            ],
        },
    };
    const markup = renderToStaticMarkup(React.createElement(RoutingTimeline, { row }));
    const progress = renderToStaticMarkup(React.createElement(Progress, { row }));

    assert.match(markup, /Canonical Routing Progress/);
    assert.match(markup, /CENRO-to-PENRO canonical routing/);
    assert.match(markup, /Forwarded to CENRO CDS Chief/);
    assert.match(markup, /CENRO CDS Focal Person/);
    assert.match(markup, /Receive/);
    assert.match(progress, /aria-valuenow="20"/);
    assert.doesNotMatch(markup, /PAMB detailed routing|MOV Review/);
});

test('user organization details render saved office and protected-area assignments', () => {
    const markup = renderToStaticMarkup(React.createElement(UserManagement.UserOrganizationDetails, {
        user: { office_designated: 'CENRO Mati', protected_area_name: 'Mount Hamiguitan Range Wildlife Sanctuary' },
    }));

    assert.match(markup, /Office/);
    assert.match(markup, /CENRO Mati/);
    assert.match(markup, /Protected Area \/ PAMO Assignment/);
    assert.match(markup, /Mount Hamiguitan Range Wildlife Sanctuary/);
});

test('compact rows and Full Details use the normalized routing percentage as their only overall bars', () => {
    const row = {
        routing: { processing_percentage: 35 },
        mov_processing: { applicable: true, percent: 100 },
    };
    const markup = renderToStaticMarkup(React.createElement(Progress, { row }));

    assert.match(markup, /role="progressbar"/);
    assert.match(markup, /aria-valuenow="35"/);
    assert.doesNotMatch(markup, /aria-valuenow="100"/);
});

test('Full Details routing progress renders the existing percentage for populated PAMB, TWC, and ENGP records', () => {
    const fixtures = [
        ['Regular PAMB', { workflow_key: 'regular_pamb', routing: { processing_percentage: 35 } }, '35'],
        ['Special PAMB', { workflow_key: 'special_pamb', routing: { processing_percentage: 55 } }, '55'],
        ['TWC', { workflow_key: 'twc_meetings', routing: { processing_percentage: 80 } }, '80'],
        ['ENGP', { workflow_key: 'site_visit', routing: { processing_percentage: 20 } }, '20'],
    ];

    for (const [label, row, percentage] of fixtures) {
        const markup = renderToStaticMarkup(React.createElement('section', {
            'aria-label': 'Routing progress and current official document',
        }, React.createElement('h2', null, 'Processing Progress'), React.createElement(Progress, { row })));
        assert.match(markup, /aria-label="Routing progress and current official document"/);
        assert.match(markup, new RegExp(`aria-valuenow="${percentage}"`), label);
        assert.match(markup, new RegExp(`${percentage}%`), label);
    }
});

test('100 percent processing stays distinct from pending terminal custody', () => {
    const markup = renderToStaticMarkup(React.createElement(Progress, {
        row: { routing_complete: false, routing: { processing_percentage: 100 } },
    }));
    assert.match(markup, /aria-label="Processing Progress"/);
    assert.match(markup, /aria-valuenow="100"/);
    assert.match(markup, /Processing is at 100%; final custody routing is still pending\./);
    const completed = renderToStaticMarkup(React.createElement(Progress, {
        row: { routing_complete: true, routing: { processing_percentage: 100 } },
    }));
    assert.doesNotMatch(completed, /final custody routing is still pending/);
});

test('notification rows render readable light and dark text, focus styling, and raw clear controls', () => {
    const calls = [];
    const props = {
        onRetry: () => calls.push('retry'),
        onOpen: notification => calls.push(['open', notification.id]),
        onClear: () => calls.push('clear'),
        state: {
            unread_count: 1,
            notifications: [{ id: 8, title: 'Correction received', message: 'Returned by CENRO Records', created_at: '2026-10-03T00:00:00Z', severity: 'danger', url: '/submission-tracking' }],
        },
    };
    const markup = renderToStaticMarkup(React.createElement(NotificationPanel, props));
    assert.match(markup, /text-gray-900 dark:text-white[^>]*>Correction received/);
    assert.match(markup, /text-gray-700 dark:text-gray-200[^>]*>Returned by CENRO Records/);
    assert.match(markup, /text-gray-500 dark:text-gray-400/);
    assert.match(markup, /focus-visible:ring-2/);
    assert.match(markup, /Clear Notifications/);
    assert.doesNotMatch(markup, /text-white\/85|text-white\/70/);
    assert.doesNotMatch(markup, /Clear Notifications[\s\S]*?data-cds-action/);
    const findButton = (node, label) => {
        if (!React.isValidElement(node)) return null;
        if (node.type === 'button' && node.props.children === label) return node;
        const children = React.Children.toArray(node.props.children);
        for (const child of children) {
            const result = findButton(child, label);
            if (result) return result;
        }
        return null;
    };
    const findButtonContaining = (node, label) => {
        if (!React.isValidElement(node)) return null;
        const contains = child => {
            if (child === label) return true;
            if (!React.isValidElement(child)) return false;
            return React.Children.toArray(child.props.children).some(contains);
        };
        if (node.type === 'button' && contains(node)) return node;
        return React.Children.toArray(node.props.children).map(child => findButtonContaining(child, label)).find(Boolean) || null;
    };
    const tree = NotificationPanel(props);
    findButton(tree, 'Clear Notifications').props.onClick();
    const rowButton = findButtonContaining(tree, 'Correction received');
    rowButton.props.onClick();
    assert.deepEqual(calls, ['clear', ['open', 8]]);
});

test('notification panel renders an explicit retry error state without showing an empty result', () => {
    const markup = renderToStaticMarkup(React.createElement(NotificationPanel, {
        state: { unread_count: 0, notifications: [] },
        requestError: 'Notifications could not be loaded. Check your connection and try again.',
        onRetry() {}, onOpen() {}, onClear() {},
    }));
    assert.match(markup, /role="alert"/);
    assert.match(markup, />Retry</);
    assert.doesNotMatch(markup, /No new notifications\.<\/div>/);
});

test('correction confirmation submit uses warning while other form saves retain primary styling and guards', () => {
    const renderModal = (saveVariant, processing = false) => renderToStaticMarkup(React.createElement(CrudFormModal, {
        open: true,
        mode: 'edit',
        title: 'Return for Correction',
        saveLabel: 'Return for Correction',
        saveVariant,
        processing,
        onClose() {},
        onSubmit() {},
    }));
    assert.match(renderModal('warning'), /data-cds-action-variant="warning"[^>]*>Return for Correction/);
    assert.match(renderModal('warning', true), /data-cds-action-variant="warning"[^>]*disabled=""[^>]*>Updating/);
    assert.match(renderModal(undefined), /data-cds-action-variant="primary"[^>]*>Return for Correction/);
    const source = readFileSync(resolve('resources/js/Pages/SubmissionTracking/Index.jsx'), 'utf8');
    assert.match(source, /saveVariant=\{genericAction\?\.correction \? "warning" : "primary"\}/);
    assert.match(source, /saveVariant=\{String\(routingStage\?\.key \|\| routingStage\?\.stage_key \|\| ""\)\.includes\("return_for_correction"\)/);
});

test('shared report context uses honest ENGP values and expands actual PAMB dates', () => {
    const engp = renderToStaticMarkup(React.createElement(ReportContext, {
        row: {
            tracking_number: 'ENGP-2026-001',
            module: 'ENGP Quarterly Report',
            document_type: 'Quarterly Report',
            target_office: 'PENRO Davao Oriental',
            period_label: 'Quarter 3, 2026',
            protected_area: null,
            date_received_penro: null,
        },
        expanded: true,
    }));
    assert.match(engp, /ENGP-2026-001/);
    assert.match(engp, /PENRO Davao Oriental/);
    assert.match(engp, /Quarter 3, 2026/);
    assert.doesNotMatch(engp, /Protected Area|PENRO Receipt/);

    const populatedEngp = renderToStaticMarkup(React.createElement(ReportContext, {
        row: {
            tracking_number: 'ENGP-2026-042',
            module: 'Site Visit',
            activity_name: 'Field verification at Banao Watershed',
            document_type: 'Quarterly Report',
            target_office: 'CENRO Baganga',
            period_label: 'Quarter 2, 2026',
            protected_area: null,
            date_received_penro: '2026-07-14',
        },
        expanded: true,
    }));
    assert.match(populatedEngp, /ENGP-2026-042/);
    assert.match(populatedEngp, /Site Visit/);
    assert.match(populatedEngp, /Field verification at Banao Watershed/);
    assert.match(populatedEngp, /CENRO Baganga/);
    assert.match(populatedEngp, /Quarter 2, 2026/);
    assert.match(populatedEngp, /PENRO Receipt/);
    assert.doesNotMatch(populatedEngp, /Protected Area/);

    const pamb = renderToStaticMarkup(React.createElement(ReportContext, {
        row: {
            tracking_number: 'CDS-2026-002',
            module: 'Regular PAMB',
            document_type: 'Minutes',
            target_office: 'CENRO Mati',
            protected_area: 'Example Protected Area',
            reporting_period: 'Quarter 3',
            date_conducted: '2026-08-03',
            date_report_released_cenro: '2026-08-04',
            date_received_penro: null,
        },
        expanded: true,
    }));
    assert.match(pamb, /Example Protected Area/);
    assert.match(pamb, /CENRO Release/);
    assert.doesNotMatch(pamb, /PENRO Receipt/);
});
