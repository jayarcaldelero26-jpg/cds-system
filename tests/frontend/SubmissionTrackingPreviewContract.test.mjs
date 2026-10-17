import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { inlinePreviewType } from '../../resources/js/Utils/protectedDocumentPreview.mjs';

// Compile the real components in memory. No browser, network, or output files.
function pageHookRuntime() {
    const updates = [];
    let cursor = 0;
    let values = [];
    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        values[index] = typeof initial === 'function' ? initial() : initial;
        return [values[index], next => {
            values[index] = typeof next === 'function' ? next(values[index]) : next;
            updates.push({ index, value: values[index] });
        }];
    };
    runtime.useRef = initial => {
        cursor += 1;
        return { current: initial };
    };
    runtime.useEffect = () => {};
    runtime.useMemo = callback => callback();
    return {
        runtime,
        updates,
        beginRender() { cursor = 0; values = []; updates.length = 0; },
    };
}

function persistentPageHookRuntime() {
    const updates = [];
    let cursor = 0;
    const values = [];
    const refs = [];
    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        if (!(index in values)) values[index] = typeof initial === 'function' ? initial() : initial;
        return [values[index], next => {
            values[index] = typeof next === 'function' ? next(values[index]) : next;
            updates.push({ index, value: values[index] });
        }];
    };
    runtime.useRef = initial => {
        const index = cursor++;
        if (!(index in refs)) refs[index] = { current: initial };
        return refs[index];
    };
    runtime.useEffect = () => {};
    runtime.useLayoutEffect = () => {};
    runtime.useMemo = callback => callback();
    runtime.useCallback = callback => callback;
    return {
        runtime,
        updates,
        beginRender() { cursor = 0; updates.length = 0; },
        reset() { cursor = 0; values.length = 0; refs.length = 0; updates.length = 0; },
    };
}

async function component(name, { stubAuthenticatedLayout = false, hooks = React } = {}) {
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
                if (stubAuthenticatedLayout && source === '@/Layouts/AuthenticatedLayout') return '\0authenticated-layout-ssr-shim';
                if (source === 'pdfjs-dist') return '\0pdfjs-ssr-shim';
                if (source === 'pdfjs-dist/build/pdf.worker.min.mjs?url') return '\0pdfjs-worker-ssr-shim';
                if (source.endsWith('.css')) return '\0submission-tracking-css-shim';
            },
            load(id) {
                if (id === '\0submission-tracking-css-shim') return '';
                if (id === '\0authenticated-layout-ssr-shim') {
                    return 'import React from "react"; export default function AuthenticatedLayout({ title, children }) { return React.createElement(React.Fragment, null, React.createElement("title", null, title), children); }';
                }
                if (id === '\0pdfjs-ssr-shim') return 'export const GlobalWorkerOptions = {}; export const getDocument = () => { throw new Error("PDF rendering is unavailable in this markup fixture"); };';
                if (id === '\0pdfjs-worker-ssr-shim') return 'export default "/pdfjs-worker-fixture.mjs";';
                if (id === '\0inertia-ssr-shim') {
                    return 'import React from "react"; export const Head = () => null; export const Link = ({ href, children, ...props }) => React.createElement("a", { ...props, href }, children); export const router = { get(...args) { globalThis.__trackingRouterCalls?.push(args); } }; export const usePage = () => ({ props: {} }); export const useForm = (data = {}) => ({ data, processing: false, errors: {}, setData() {}, reset() {}, clearErrors() {}, post() {} });';
                }
                if (/\.(png|jpe?g|svg|webp|gif)$/.test(id)) return 'export default "test-image";';
            },
        }],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs', codeSplitting: false });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(id => id === 'react' ? hooks : createRequire(import.meta.url)(id), module, module.exports);
    return module.exports;
}

const Preview = await component('DocumentPreviewDialog');
const PdfViewer = await component('PdfDocumentViewer');
const PambProgress = await component('PambMovProgress');
const PambActions = await component('PambMovActions');
const ReviewHistory = await component('SubmissionReviewHistory');
const AttachmentDropzone = await component('Attachments/AttachmentDropzone');
const RoutingTimeline = await component('DocumentRoutingTimeline');
const SubmissionTrackingPage = await component('Pages/SubmissionTracking/Index', { stubAuthenticatedLayout: true });
const trackingHooks = pageHookRuntime();
const InteractiveSubmissionTrackingPage = await component('Pages/SubmissionTracking/Index', {
    stubAuthenticatedLayout: true,
    hooks: trackingHooks.runtime,
});
const detailsJourneyHooks = persistentPageHookRuntime();
const DetailsJourneyPage = await component('Pages/SubmissionTracking/Index', {
    stubAuthenticatedLayout: true,
    hooks: detailsJourneyHooks.runtime,
});
const FinalArchivesPage = await component('Pages/SubmissionTracking/Archives', { stubAuthenticatedLayout: true });
const SidebarDetails = SubmissionTrackingPage.SubmissionDetailsPanel;
const TrackingProgressCard = SubmissionTrackingPage.SubmissionTrackingDetailsProgressCard;
const OfficialReportFileSection = await component('OfficialReportFileSection');
const FinalArchiveActions = await component('FinalArchiveRecordActions');
const FinalArchiveFilterFields = await component('FinalArchivesFilterFields');
const FinalArchiveStatusTabs = await component('FinalArchiveStatusTabs');
const Progress = await component('SubmissionTrackingProgress');
const ReportContext = await component('SubmissionReportContext');
const NotificationComponents = await component('Notifications/NotificationBell');
const NotificationPanel = NotificationComponents.NotificationPanel;
const CrudFormModal = await component('Crud/CrudFormModal');
const UserManagement = await component('Pages/Admin/Users/Index');
const appStyles = readFileSync(resolve('resources/css/app.css'), 'utf8');
const previewDialogSource = readFileSync(resolve('resources/js/Components/SubmissionTracking/DocumentPreviewDialog.jsx'), 'utf8');
const sharedReaderSource = readFileSync(resolve('resources/js/Components/SubmissionTracking/SubmissionTrackingReaderDialog.jsx'), 'utf8');
const pdfViewerSource = readFileSync(resolve('resources/js/Components/SubmissionTracking/PdfDocumentViewer.jsx'), 'utf8');
const submissionTrackingSource = readFileSync(resolve('resources/js/Pages/SubmissionTracking/Index.jsx'), 'utf8');
const render = row => renderToStaticMarkup(React.createElement(Preview, { open: true, row }));

function findElement(root, predicate) {
    if (!React.isValidElement(root)) return null;
    if (predicate(root)) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
    return null;
}

test('selected Submission Tracking details link only to an exact verified final archive', () => {
    const row = { ...capturedRouteRow(true, true), has_verified_final_archive: true };
    const markup = renderToStaticMarkup(React.createElement(SidebarDetails, { row, canViewFinalArchives: true }));

    assert.match(markup, /Final returned documents/);
    assert.match(markup, /href="\/submission-tracking\/archives\?source=bms&amp;record=2"/);
    assert.match(markup, /Open this report in Archives/);
    assert.match(markup, />Open Archives</);

    const hidden = renderToStaticMarkup(React.createElement(SidebarDetails, {
        row: { ...row, has_verified_final_archive: false },
        canViewFinalArchives: true,
    }));
    assert.doesNotMatch(hidden, /Final returned documents|Open Archives/);

    const initialPamoOutgoing = {
        ...row,
        source: 'conservation',
        source_id: 40,
        workflow_key: 'restoration_plan_5_year',
        activity_name: 'Restoration Plan Workshop',
        routing_complete: false,
        has_verified_final_archive: false,
        routing: {
            ...row.routing,
            current_stage: 'transit_to_cenro_focal',
            current_status: 'In Transit',
            in_transit_to: 'CENRO Baganga',
            processing_percentage: 10,
        },
    };
    const initialPamoMarkup = renderToStaticMarkup(React.createElement(SidebarDetails, {
        row: initialPamoOutgoing,
        canViewFinalArchives: true,
        pamoWorkspace: true,
        context: { view: 'outgoing' },
    }));
    assert.match(initialPamoMarkup, /Restoration Plan Workshop/);
    assert.match(initialPamoMarkup, /CENRO Baganga/);
    assert.doesNotMatch(initialPamoMarkup, /Final returned documents|Open Archives/);

    for (const lifecycleFixture of [
        { routing_complete: true, has_verified_final_archive: false },
        { receipt: { id: 7 }, has_verified_final_archive: false },
        { latest_manifest: { status: 'PENDING' }, has_verified_final_archive: false },
        { latest_manifest: { status: 'FAILED' }, has_verified_final_archive: false },
        { legacy_dispatch_archive: true, has_verified_final_archive: false },
        { source: 'unregistered-source', has_verified_final_archive: true },
    ]) {
        const premature = renderToStaticMarkup(React.createElement(SidebarDetails, {
            row: { ...row, ...lifecycleFixture },
            canViewFinalArchives: true,
        }));
        assert.doesNotMatch(premature, /Final returned documents|Open Archives/);
    }

    const deniedReader = renderToStaticMarkup(React.createElement(SidebarDetails, {
        row,
        canViewFinalArchives: false,
    }));
    assert.doesNotMatch(deniedReader, /Final returned documents|Open Archives/);
});

test('global Archives page restores the shared banner and only renders real pagination', () => {
    const sampleRows = [{
        id: 'bms:8:1', source: 'bms', source_id: 8, module: 'BMS Final Return',
        protected_area: 'Mount Hamiguitan Range Wildlife Sanctuary',
        manifest: { id: 23, status: 'ARCHIVED', finalized_at: '2026-10-16T13:00:00Z', files: [
            { id: 81, name: 'BMS-8-final-signed.pdf', mime_type: 'application/pdf', status: 'ARCHIVED', preview_url: '/protected/81?preview=1', download_url: '/protected/81?download=1' },
        ] },
    }];
    const actualPages = [{ label: '&laquo; Previous', url: '/archives?page=1' }, { label: '1', url: '/archives?page=1', active: true }, { label: '2', url: '/archives?page=2' }, { label: 'Next &raquo;', url: '/archives?page=2' }];
    const markup = renderToStaticMarkup(React.createElement(FinalArchivesPage, { archives: { data: sampleRows, links: actualPages, total: 2 } }));

    assert.match(markup, /<title>Archives<\/title>/);
    assert.match(markup, /<h1[^>]*>Archives<\/h1>/);
    assert.equal((markup.match(/<h1\b/g) || []).length, 1);
    assert.match(markup, /Find final signed returns, earlier Dispatch copies, and reports waiting for archive steps\./);
    assert.match(markup, /data-page-header-variant="default"/);
    assert.match(markup, /aria-label="Pagination"/);
    assert.match(markup, /BMS-8-final-signed\.pdf/);
    assert.doesNotMatch(markup, /Final Archives/);

    const noPages = renderToStaticMarkup(React.createElement(FinalArchivesPage, { archives: { data: sampleRows, links: [], total: 1 } }));
    assert.doesNotMatch(noPages, /aria-label="Pagination"/);
    assert.doesNotMatch(noPages, /rounded-xl border border-slate-100 bg-white px-4 py-3/);
});

test('standard current-document and PAMO official-file actions pass their selected row unchanged to the shared preview state', () => {
    const standardRow = {
        source: 'bms', source_id: 8, module: 'BMS Final Return',
        current_document: { id: 8001, name: 'BMS-8-working.pdf', can_preview: true, preview_url: '/submission-tracking/bms/8/current?preview=1', download_url: '/submission-tracking/bms/8/current?download=1' },
    };
    const pamoRow = {
        source: 'conservation', source_id: 40, module: 'PAMO Five-Year Restoration Plan',
        current_document: { id: 4001, name: 'PAMO-40-current.pdf', can_preview: true, preview_url: '/submission-tracking/conservation/40/current?preview=1', download_url: '/submission-tracking/conservation/40/current?download=1' },
    };
    const opened = [];

    const standardTree = TrackingProgressCard({ details: standardRow, onPreview: value => opened.push(value) });
    const standardButton = findElement(standardTree, element => element.props.children === 'Preview Current Document');
    assert.ok(standardButton);
    standardButton.props.onClick();
    assert.equal(opened[0], standardRow, 'standard Incoming/Outgoing/History rows retain the exact selected source record');

    const pamoTree = OfficialReportFileSection({ row: pamoRow, onPreview: value => opened.push(value) });
    const pamoButton = findElement(pamoTree, element => element.props.children === 'Preview file');
    assert.ok(pamoButton);
    pamoButton.props.onClick();
    assert.equal(opened[1], pamoRow, 'PAMO For Action/Sent/Completed retain the exact selected official report row');
    assert.equal(opened[1].current_document, pamoRow.current_document);
});

test('selected report View Full Details opens the complete source-bound details while Preview remains separate', () => {
    const previousRoute = globalThis.route;
    globalThis.route = name => name;

    const standardWithoutPreview = {
        source: 'bms', source_id: 8, tracking_number: 'BMS-8',
        module: 'BMS Final Return', activity_name: 'BMSFR Activity',
        document_type: 'BMSFR', protected_area: 'Mount Hamiguitan Range Wildlife Sanctuary',
        reporting_period: '2026', target_office: 'CENRO Baganga',
        current_document: { id: 'bms-8-doc', name: 'Legacy-8.docx', can_preview: false },
        routing: { current_stage: 'awaiting_penro_receipt', current_status: 'Awaiting Receipt by PENRO CDS Chief', processing_percentage: 45, actions: [], timeline: [], routing_history: [] },
        can_transition: false,
    };
    const standardWithPreview = {
        ...standardWithoutPreview, source_id: 9, tracking_number: 'BMS-9',
        current_document: { id: 'bms-9-doc', name: 'BMS-9-working.pdf', can_preview: true, mime_type: 'application/pdf', preview_url: '/submission-tracking/bms/9/current?preview=1', download_url: '/submission-tracking/bms/9/current?download=1' },
    };
    const nativePamo = {
        ...standardWithoutPreview, source: 'conservation', source_id: 41,
        tracking_number: 'PAMO-41', module: 'PAMO TWC Meetings',
        activity_name: 'TWC Meetings', document_type: 'PAMB Minutes',
        current_document: undefined, pamb_routing_applicable: true,
        canonical_custody_applicable: false,
        routing_timeline: [{ key: 'pamb_review', label: 'PAMO Review', status: 'current' }],
    };
    const genericPamo = {
        ...standardWithoutPreview, source: 'conservation', source_id: 40,
        tracking_number: 'PAMO-40', module: 'Five-Year Restoration Plan',
        activity_name: 'Restoration Plan Workshop', document_type: 'BMSFR',
        current_document: undefined, pamb_routing_applicable: false,
        canonical_custody_applicable: true,
    };

    const renderPage = (rows, pamoWorkspace = false) => {
        detailsJourneyHooks.beginRender();
        return DetailsJourneyPage.default({
            workspaceQueues: { incoming: rows, outgoing: [], history: [] },
            filters: {}, filterOptions: {},
            trackingContext: { view: 'incoming', selected_record: null },
            pagination: { current_page: 1, per_page: 25, has_more: false },
            pamoWorkspace,
        });
    };
    const panelFor = tree => findElement(tree, element => element.type?.name === 'SubmissionDetailsPanel');
    const tableFor = tree => findElement(tree, element => Array.isArray(element.props?.rows) && typeof element.props?.onRowClick === 'function');
    const detailsModalFor = tree => findElement(tree, element => element.type?.name === 'CrudDetailsModal' && (element.props.title === 'Submission Details' || element.props.title === 'Report Details'));
    const modalChildrenFor = modal => React.createElement(React.Fragment, null, modal?.props.children);
    const reportContextFor = modal => findElement(modalChildrenFor(modal), element => element.props?.expanded === true && element.props?.row?.source);

    try {
        detailsJourneyHooks.reset();
        let page = renderPage([standardWithoutPreview, standardWithPreview]);
        tableFor(page).props.onRowClick(standardWithoutPreview);
        page = renderPage([standardWithoutPreview, standardWithPreview]);
        assert.deepEqual(tableFor(page).props.selectedKeys, ['bms-8']);
        let panel = panelFor(page);
        assert.equal(panel.props.row, standardWithoutPreview, 'the selected row remains bound to the summary');
        let panelTree = panel.type(panel.props);
        let panelMarkup = renderToStaticMarkup(React.createElement(panel.type, panel.props));
        assert.match(panelMarkup, /Preview unavailable for this account/);
        assert.match(panelMarkup, /View Full Details/);
        assert.match(panelMarkup, /xl:max-h-\[calc\(100dvh-2rem\)\]/);
        assert.match(panelMarkup, /overflow-y-auto/);
        const viewDetails = findElement(panelTree, element => element.props?.children === 'View Full Details');
        assert.ok(viewDetails, 'the report details action remains available without preview access');
        viewDetails.props.onClick();

        page = renderPage([standardWithoutPreview, standardWithPreview]);
        let detailsModal = detailsModalFor(page);
        assert.equal(detailsModal?.props.open, true, 'View Full Details opens the complete report details modal');
        assert.equal(detailsModal.props.title, 'Submission Details');
        let reportContext = reportContextFor(detailsModal);
        assert.equal(reportContext?.props.row, standardWithoutPreview, 'the exact selected BMS source/record supplies report details');
        assert.equal(reportContext.props.expanded, true);
        const standardContext = renderToStaticMarkup(React.createElement(ReportContext, { row: reportContext.props.row, expanded: true }));
        assert.match(standardContext, /BMS Final Return/);
        assert.match(standardContext, /BMSFR Activity/);
        assert.match(standardContext, /Mount Hamiguitan Range Wildlife Sanctuary/);
        assert.match(standardContext, /2026/);
        let previewDialog = findElement(page, element => element.type?.name === 'DocumentPreviewDialog');
        assert.equal(previewDialog.props.open, false, 'View Details does not open or substitute the file reader');

        detailsModal.props.onClose();
        page = renderPage([standardWithoutPreview, standardWithPreview]);
        tableFor(page).props.onRowClick(standardWithPreview);
        page = renderPage([standardWithoutPreview, standardWithPreview]);
        panel = panelFor(page);
        panelTree = panel.type(panel.props);
        const preview = findElement(panelTree, element => element.props?.children === 'Preview');
        assert.ok(preview);
        preview.props.onClick();
        page = renderPage([standardWithoutPreview, standardWithPreview]);
        previewDialog = findElement(page, element => element.type?.name === 'DocumentPreviewDialog');
        assert.equal(previewDialog.props.open, true);
        assert.equal(previewDialog.props.row, standardWithPreview, 'Preview uses the selected working-file descriptor for the exact BMS record');
        assert.equal(previewDialog.props.presentation, 'document-only', 'standard and PAMO queue file previews omit archive-only Details and History sections');
        assert.equal(detailsModalFor(page).props.open, false, 'Preview remains a separate action from View Details');
        previewDialog.props.onClose();
        page = renderPage([standardWithoutPreview, standardWithPreview]);
        assert.equal(panelFor(page).props.row, standardWithPreview, 'closing Preview retains queue selection and record context');
        assert.deepEqual(tableFor(page).props.selectedKeys, ['bms-9']);

        for (const pamoRow of [genericPamo, nativePamo]) {
            detailsJourneyHooks.reset();
            page = renderPage([pamoRow], true);
            tableFor(page).props.onRowClick(pamoRow);
            page = renderPage([pamoRow], true);
            panel = panelFor(page);
            assert.equal(panel.props.row, pamoRow);
            panel.type(panel.props);
            const pamoDetailsAction = findElement(panel.type(panel.props), element => element.props?.children === 'View Full Details');
            assert.ok(pamoDetailsAction, 'PAMO details stay available when there is no current document');
            pamoDetailsAction.props.onClick();
            page = renderPage([pamoRow], true);
            detailsModal = detailsModalFor(page);
            assert.equal(detailsModal?.props.open, true);
            assert.equal(detailsModal.props.title, 'Report Details');
            reportContext = reportContextFor(detailsModal);
            assert.equal(reportContext?.props.row, pamoRow, 'the PAMO source identity is not replaced by a primary archive descriptor');
            const pamoContext = renderToStaticMarkup(React.createElement(ReportContext, { row: pamoRow, expanded: true }));
            assert.match(pamoContext, new RegExp(pamoRow.activity_name));
            assert.match(pamoContext, new RegExp(pamoRow.reporting_period));
            if (pamoRow.pamb_routing_applicable) {
                assert.ok(findElement(modalChildrenFor(detailsModal), element => element.props?.row === pamoRow && Object.hasOwn(element.props, 'hideCurrentProcessing')), 'native PAMO/PAMB details retain their source-specific timeline');
            } else {
                assert.ok(findElement(modalChildrenFor(detailsModal), element => element.props?.row === pamoRow && Object.hasOwn(element.props, 'hideRoutingHistory')), 'generic PAMO details retain their routing timeline');
            }
            previewDialog = findElement(page, element => element.type?.name === 'DocumentPreviewDialog');
            assert.equal(previewDialog.props.open, false, 'a report without a previewable attachment remains details-only');
        }
    } finally {
        if (previousRoute === undefined) delete globalThis.route;
        else globalThis.route = previousRoute;
        detailsJourneyHooks.reset();
    }
});

test('final archive action panel renders eligibility states and dispatches its visible button callbacks', () => {
    const buttonLabels = markup => [...markup.matchAll(/<button[^>]*>(.*?)<\/button>/g)].map(match => match[1]);
    const beforeReceipt = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: { can_receive_final: true, can_archive_final: false, latest_manifest: null },
    }));
    assert.deepEqual(buttonLabels(beforeReceipt), ['Receive Final MOVs from Regional Office']);

    const readyToArchive = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: { can_receive_final: false, can_archive_final: true, latest_manifest: null },
    }));
    assert.deepEqual(buttonLabels(readyToArchive), ['Attach Signed Set and Archive']);

    const denied = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: {
            can_receive_final: false,
            can_archive_final: false,
            latest_manifest: null,
            final_action_unavailable_reason: 'This report is assigned to PENRO Davao Oriental for final receipt.',
        },
    }));
    assert.deepEqual(buttonLabels(denied), []);
    assert.match(denied, /This report is assigned to PENRO Davao Oriental for final receipt\./);

    const failed = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: {
            can_receive_final: false,
            can_archive_final: true,
            latest_manifest: { id: 15, status: 'FAILED', retry_available: true },
            final_action_unavailable_reason: 'The final archive is incomplete. Use Retry Archive to resume the retained file set.',
        },
    }));
    assert.deepEqual(buttonLabels(failed), ['Retry Archive']);

    const processing = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: {
            can_receive_final: false,
            can_archive_final: true,
            latest_manifest: { id: 17, status: 'PROCESSING', processing_claim_active: true, retry_available: false },
        },
    }));
    assert.deepEqual(buttonLabels(processing), []);

    const expired = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: {
            can_receive_final: false,
            can_archive_final: true,
            latest_manifest: { id: 18, status: 'PROCESSING', processing_claim_active: false, retry_available: true },
        },
    }));
    assert.deepEqual(buttonLabels(expired), ['Retry Archive']);

    const archived = renderToStaticMarkup(React.createElement(FinalArchiveActions, {
        selected: {
            can_receive_final: false,
            can_archive_final: false,
            latest_manifest: { id: 16, status: 'ARCHIVED' },
            final_action_unavailable_reason: 'This report already has a verified final archive. No additional receipt or upload is available.',
        },
    }));
    assert.deepEqual(buttonLabels(archived), []);
    assert.match(archived, /verified final archive/);

    const calls = [];
    const tree = FinalArchiveActions({
        selected: { can_receive_final: true, can_archive_final: false, latest_manifest: null },
        onReceive: () => calls.push('receive'),
        onAddArchive: () => calls.push('archive'),
        onRetry: () => calls.push('retry'),
    });
    const findButtons = node => {
        if (!node || typeof node !== 'object') return [];
        const self = node.type === 'button' ? [node] : [];
        const children = React.Children.toArray(node.props?.children).flatMap(findButtons);
        return [...self, ...children];
    };
    findButtons(tree)[0].props.onClick();
    assert.deepEqual(calls, ['receive']);
});

test('Final Archives filter fields render readable labels and values and forward control changes', () => {
    const changes = {};
    const props = {
        module: 'BMS',
        protectedArea: 'Mount Hamiguitan Range Wildlife Sanctuary',
        office: 'PENRO Davao Oriental',
        year: '2026',
        filterOptions: {
            modules: ['BMS'],
            protectedAreas: ['Mount Hamiguitan Range Wildlife Sanctuary'],
            offices: ['PENRO Davao Oriental'],
            years: ['2026'],
        },
        onModuleChange: event => { changes.module = event.target.value; },
        onProtectedAreaChange: event => { changes.protectedArea = event.target.value; },
        onOfficeChange: event => { changes.office = event.target.value; },
        onYearChange: event => { changes.year = event.target.value; },
    };
    const markup = renderToStaticMarkup(React.createElement(FinalArchiveFilterFields, props));

    for (const value of [
        'Report module', 'Protected Area', 'Originating office', 'Reporting year',
        'BMS', 'Mount Hamiguitan Range Wildlife Sanctuary', 'PENRO Davao Oriental', '2026',
    ]) assert.ok(markup.includes(value), `rendered filter panel includes ${value}`);
    assert.equal((markup.match(/role="combobox"/g) || []).length, 4);
    assert.doesNotMatch(markup, /Search reports and archives|final-archive-search/);

    const tree = FinalArchiveFilterFields(props);
    const controls = [];
    const visit = node => {
        if (!node || typeof node !== 'object') return;
        if (node.props?.id) controls.push(node);
        React.Children.toArray(node.props?.children).forEach(visit);
    };
    visit(tree);
    for (const [id, value] of [
        ['final-archive-module', 'imea'],
        ['final-archive-pa', 'pa-8'],
        ['final-archive-office', 'penro-other'],
        ['final-archive-year', '2025'],
    ]) {
        controls.find(control => control.props.id === id).props.onChange({ target: { value } });
    }
    assert.deepEqual(changes, { module: 'imea', protectedArea: 'pa-8', office: 'penro-other', year: '2025' });
});

test('Final Archives status tabs keep counts, selected semantics, and only render available queues', () => {
    const changes = [];
    const props = {
        value: 'archives', archivesTotal: 0, awaitingArchiveTotal: 3, awaitingReceiptTotal: 2,
        onChange: value => changes.push(value),
    };
    const markup = renderToStaticMarkup(React.createElement(FinalArchiveStatusTabs, props));

    assert.match(markup, /role="tablist" aria-label="Archives status"/);
    assert.match(markup, /aria-selected="true"[^>]*>[\s\S]*?Archives[\s\S]*?0/);
    assert.match(markup, /Awaiting Archive[\s\S]*?3/);
    assert.match(markup, /Awaiting Final Receipt[\s\S]*?2/);

    const tree = FinalArchiveStatusTabs(props);
    const tabs = React.Children.toArray(tree.props.children);
    tabs[2].props.onClick();
    assert.deepEqual(changes, ['receipt']);

    const onlyVerified = renderToStaticMarkup(React.createElement(FinalArchiveStatusTabs, {
        value: 'archives', archivesTotal: 0, awaitingArchiveTotal: 0, awaitingReceiptTotal: 0,
    }));
    assert.equal((onlyVerified.match(/role="tab"/g) || []).length, 1);
    assert.match(onlyVerified, />Archives</);
    assert.doesNotMatch(onlyVerified, /Awaiting Archive|Awaiting Final Receipt/);
});

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
    // This SSR fixture has no DOM. The dialog intentionally renders through a portal,
    // so verify its source contract here and its response handling in the unit suite.
    assert.equal(typeof document, 'undefined');
    for (const mime_type of ['application/pdf', 'image/jpeg', 'image/png']) {
        const markup = render({ current_document: { name: 'synthetic', mime_type, can_preview: true, preview_url: '/attachments/aws-report/1/report?preview=1' }, mov_url: '/overview' });
        assert.equal(markup, '');
        assert.equal(inlinePreviewType(mime_type), mime_type);
    }
    assert.match(previewDialogSource, /if \(!open \|\| !url \|\| !inline \|\| !canPreview\)/);
    assert.match(previewDialogSource, /startProtectedPreview\(url,/);
    assert.match(previewDialogSource, /preview\?\.requestKey === requestKey \? preview : null/);
    assert.match(previewDialogSource, /current\?\.status === 'ready'/);
    assert.match(previewDialogSource, /Loading document preview/);
    assert.match(previewDialogSource, /<PdfDocumentViewer[\s\S]*?blob=\{current\.blob\}/);
    assert.match(previewDialogSource, /<img src=\{current\.url\}/);
    assert.match(previewDialogSource, /downloadUrl=\{canDownload \? downloadUrl : null\}/);
    assert.match(sharedReaderSource, /if \(!present \|\| typeof document === 'undefined'\) return null;/);
    assert.match(sharedReaderSource, /return createPortal\(/);
    assert.match(sharedReaderSource, /useModalMotion\(open/);
    assert.match(sharedReaderSource, /data-cds-modal-layer/);
    assert.doesNotMatch(previewDialogSource, /<iframe/);
});

test('PDF viewer exposes Fit Page, bounded zoom, continuous scrolling, and accessible page navigation', () => {
    const markup = renderToStaticMarkup(React.createElement(PdfViewer, { blob: new Blob(), title: 'Portrait report' }));
    assert.match(markup, /aria-label="PDF controls"/);
    assert.match(markup, /aria-label="Zoom out"/);
    assert.match(markup, />Fit Page</);
    assert.match(markup, /aria-label="Zoom in"/);
    assert.match(markup, /aria-label="Zoom percentage"/);
    assert.match(markup, /aria-label="Go to page"/);
    assert.match(markup, /class="[^"]*overflow-auto[^"]*" role="region" aria-label="PDF pages"/);
    assert.doesNotMatch(markup, /aria-label="Previous page"|aria-label="Next page"/);
    assert.match(markup, /Preparing PDF preview/);
    assert.match(markup, /aria-label="Zoom percentage"[^>]*>—%/);
    assert.match(markup, /Page — of —/);
    assert.match(pdfViewerSource, /Math\.abs\(number - pageNumber\) <= 1/);
    assert.match(pdfViewerSource, /onWheel/);
    assert.match(pdfViewerSource, /if \(!event\.ctrlKey/);
    assert.match(pdfViewerSource, /onPointerDown/);
    assert.match(pdfViewerSource, /onKeyDown=\{handleKeyDown\}/);
    assert.match(pdfViewerSource, /PageDown/);
    assert.match(pdfViewerSource, /setPageInput\(event\.target\.value\)/);
    assert.match(pdfViewerSource, /onBlur=\{commitPageInput\}/);
    assert.match(pdfViewerSource, /if \(active \|\| renderedKey === null\) return;/);
});

test('unsupported and unverified MIME stay download-only, even with a PDF filename', () => {
    assert.equal(typeof document, 'undefined');
    for (const mime_type of [null, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']) {
        const markup = render({ current_document: { name: 'looks-like.pdf', mime_type, can_preview: true, preview_url: '/attachments/source/1/mov?preview=1', download_url: '/attachments/source/1/mov?download=1' } });
        assert.equal(markup, '');
        assert.equal(inlinePreviewType(mime_type), null);
    }
    assert.match(previewDialogSource, /This document type cannot be previewed in the browser\./);
    assert.match(previewDialogSource, /Preview is unavailable because the file type could not be verified\./);
    assert.match(previewDialogSource, /canDownload && <a href=\{downloadUrl\}/);
    assert.match(previewDialogSource, /<PdfDocumentViewer[\s\S]*?blob=\{current\.blob\}/);
    assert.match(previewDialogSource, /<img src=\{current\.url\}/);
    assert.doesNotMatch(previewDialogSource, /<iframe/);
});

test('missing current descriptor cannot revive other document URLs', () => {
    assert.equal(typeof document, 'undefined');
    const markup = render({ current_document: { can_preview: true }, mov_url: '/overview', effective_document: { url: '/archive' }, mov_attachment: { url: '/history', mime_type: 'application/pdf' } });
    assert.equal(markup, '');
    assert.match(previewDialogSource, /: !url \? <p[^>]*>No MOV\/report attachment is available for this submission\./);
    assert.match(previewDialogSource, /const attachment = attachmentOverride \|\| row\?\.current_document;/);
    assert.doesNotMatch(previewDialogSource, /row\?\.(?:mov_url|effective_document|mov_attachment)/);
    assert.doesNotMatch(previewDialogSource, /<iframe/);
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
    assert.match(markup, /Return MOV to CENRO Focal for Correction/);
    assert.doesNotMatch(markup, /Submit for Review|>Release</);
});

test('correction receipt gates resubmission and editing controls in the shared MOV details', () => {
    const row = received => ({
        source_url: '/conservation-reports/regular_pamb/1/edit',
        mov_url: '/protected/current-mov',
        mov_processing: { applicable: true, status_key: 'needs_correction', review_remarks: 'Attach the signed page.' },
        pamb_action_flags: { can_submit: received, can_review: false, can_release: false },
    });
    const waitingMarkup = renderToStaticMarkup(React.createElement(PambProgress, { row: row(false) }));
    assert.doesNotMatch(waitingMarkup, /Edit \/ Correct Submission|Resubmit for Review/);

    const receivedMarkup = renderToStaticMarkup(React.createElement(PambProgress, { row: row(true) }));
    assert.match(receivedMarkup, /Edit \/ Correct Submission/);
    assert.match(receivedMarkup, /Resubmit for Review/);
});

test('legacy verdict-only correction shows one missing return action on both details surfaces', () => {
    const correctionAction = {
        key: 'return_to_cenro_focal',
        action_label: 'Return Report to CENRO Focal',
        label: 'Return Report to CENRO Focal for Correction',
        correction: true,
    };
    const row = {
        source: 'conservation',
        source_id: 12,
        target_office: 'CENRO Mati',
        canonical_custody_applicable: true,
        pamb_routing_applicable: true,
        can_transition: true,
        mov_processing: { applicable: true, status_key: 'needs_correction', review_remarks: 'Existing verdict.' },
        pamb_action_flags: { can_submit: false, can_review: false },
        routing: {
            current_stage: 'cenro_chief',
            current_status: 'Needs Correction',
            next_expected_action: 'Return Report to CENRO Focal',
            actions: [correctionAction],
            timeline: [],
            routing_history: [],
        },
        routing_timeline: [],
    };
    const sidebar = renderToStaticMarkup(React.createElement(SidebarDetails, { row }));
    const fullTimeline = renderToStaticMarkup(React.createElement(RoutingTimeline, { row }));
    for (const markup of [sidebar, fullTimeline]) {
        assert.match(markup, /Return Report to CENRO Focal/);
        assert.doesNotMatch(markup, /Ready for Release|Return MOV to CENRO Focal for Correction|Submit for Review/);
    }
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
    assert.match(timeline, /Routing Progress/);
    assert.equal((history.match(/Returned for Correction/g) || []).length, 2);
    assert.match(history, /Admin Override/);
    assert.match(history, /Override for: CENRO CDS Chief/);
    assert.match(history, /CENRO Baganga/);
    assert.match(history, /First cycle correction\./);
    assert.match(history, /Second cycle correction\./);
    assert.match(history, /cycle-one\.pdf/);
    assert.match(history, /cycle-two\.pdf/);
    assert.equal((history.match(/>Preview<\/button>/g) || []).length, 2);
    assert.equal((history.match(/>Download<\/button>/g) || []).length, 2);
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

test('synthetic PAMO origin renders provenance without an invented incoming handoff', () => {
    const markup = renderToStaticMarkup(React.createElement(RoutingTimeline, {
        row: {
            can_transition: false,
            routing: {
                profile_label: 'Conservation routing',
                actions: [],
                routing_history: [],
                timeline: [{
                    key: 'pamo_origin',
                    label: 'Report prepared by PAMO',
                    status: 'completed',
                    display_status: 'completed',
                    display_status_label: 'Origin',
                    event_type: 'origin',
                    from: null,
                    to: null,
                    office: 'PAMO Aliwagwag',
                    occurred_at: null,
                }],
            },
        },
    }));

    assert.match(markup, /Routing Progress/);
    assert.match(markup, /Shows the completed, current and upcoming stages of this report\./);
    assert.match(markup, /Report prepared by PAMO/);
    assert.match(markup, />Origin<\/span>/);
    assert.match(markup, /Office: PAMO Aliwagwag/);
    assert.doesNotMatch(markup, /From:|To:|STAGE/);
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

    assert.match(markup, /Routing Progress/);
    assert.match(markup, /Shows the completed, current and upcoming stages of this report\./);
    assert.doesNotMatch(markup, /CENRO-to-PENRO canonical routing/);
    assert.match(markup, /Forwarded to CENRO CDS Chief/);
    assert.match(markup, /CENRO CDS Focal Person/);
    assert.match(markup, /Receive/);
    assert.match(progress, /aria-valuenow="20"/);
    assert.doesNotMatch(markup, /PAMB detailed routing|MOV Review/);
});

test('PAMO tracking labels stay distinct while the general tracking labels remain intact', () => {
    const pamoEmpty = renderToStaticMarkup(React.createElement(SidebarDetails, { row: null, pamoWorkspace: true }));
    const generalEmpty = renderToStaticMarkup(React.createElement(SidebarDetails, { row: null }));
    assert.match(pamoEmpty, /No report selected/);
    assert.match(pamoEmpty, /There are no reports available in this view/);
    assert.match(generalEmpty, /No submission selected/);
    assert.match(generalEmpty, /There are no records available in this queue/);
    assert.match(submissionTrackingSource, /incoming: "For Action"/);
    assert.match(submissionTrackingSource, /outgoing: "Sent Reports"/);
    assert.match(submissionTrackingSource, /history: "Completed Reports"/);
    assert.match(submissionTrackingSource, /incoming: "Incoming Submissions"/);
    assert.match(submissionTrackingSource, /outgoing: "Outgoing Submissions"/);
    assert.match(submissionTrackingSource, /history: "Submission History"/);
    assert.match(submissionTrackingSource, /Recipient's Next Expected Action/);
    assert.match(submissionTrackingSource, /Shown for reference at the destination office/);
});

function renderTrackingWorkspace({ view = 'incoming', pamoWorkspace = true, filters = {} } = {}) {
    return renderToStaticMarkup(React.createElement(SubmissionTrackingPage.default, {
        workspaceQueues: { incoming: [], outgoing: [], history: [] },
        filters,
        filterOptions: {
            modules: ['Conservation'],
            protectedAreas: [{ id: 4, name: 'Sample Protected Area' }],
            statuses: ['In Review'],
        },
        trackingContext: { view, selected_record: null },
        pagination: { current_page: 1, per_page: 25, has_more: false },
        pamoWorkspace,
    }));
}

test('PAMO For Action, Sent Reports, and Completed Reports render one shared compact search/filter group', () => {
    const activeFilters = {
        search: 'forest',
        module: 'Conservation',
        protected_area_id: '4',
        status: 'In Review',
    };
    const expectedViews = [
        ['incoming', 'For Action'],
        ['outgoing', 'Sent Reports'],
        ['history', 'Completed Reports'],
    ];

    for (const [view, heading] of expectedViews) {
        const markup = renderTrackingWorkspace({ view, filters: activeFilters });
        assert.match(markup, new RegExp(`<title>${heading}</title>`));
        assert.match(markup, new RegExp(`<h1[^>]*>${heading}</h1>`));
        assert.match(markup, /role="group" aria-label="PAMO search and filters"/);
        assert.match(markup, /placeholder="Search reports"/);
        assert.match(markup, /<label[^>]*for="submission-tracking-search"[^>]*>Search reports<\/label>/);
        assert.match(markup, /value="forest"/);
        assert.match(markup, /Filters.*4 selected/);
        assert.match(markup, /Clear all/);
        assert.doesNotMatch(markup, /New draft/);
    }
});

test('PAMO Tracking search, filter changes, clear, view, and selected source context stay connected', () => {
    const previousRoute = globalThis.route;
    const previousCalls = globalThis.__trackingRouterCalls;
    globalThis.route = name => name;

    try {
        for (const view of ['incoming', 'outgoing', 'history']) {
            trackingHooks.beginRender();
            const calls = [];
            globalThis.__trackingRouterCalls = calls;
            const filters = {
                search: 'forest', module: 'Conservation', protected_area_id: '4', status: 'In Review',
            };
            const tree = InteractiveSubmissionTrackingPage.default({
                workspaceQueues: { incoming: [], outgoing: [], history: [] },
                filters,
                filterOptions: {
                    modules: ['Conservation'],
                    protectedAreas: [{ id: 4, name: 'Sample Protected Area' }],
                    statuses: ['In Review'],
                },
                trackingContext: {
                    view,
                    selected_record: { source: 'conservation', source_id: 71 },
                    selected_source: 'conservation',
                    selected_id: 71,
                },
                pagination: { current_page: 1, per_page: 25, has_more: false },
                pamoWorkspace: true,
            });
            const toolbar = findElement(tree, element => element.props.searchId === 'submission-tracking-search');
            assert.ok(toolbar);
            assert.equal(toolbar.props.search, 'forest');
            toolbar.props.onSearchChange({ target: { value: 'woodland' } });
            assert.ok(trackingHooks.updates.some(update => update.value === 'woodland'));

            const module = findElement(toolbar.props.children, element => element.props.id === 'submission-tracking-module');
            const area = findElement(toolbar.props.children, element => element.props.id === 'submission-tracking-area');
            const status = findElement(toolbar.props.children, element => element.props.id === 'submission-tracking-status');
            assert.equal(module.props.value, 'Conservation');
            assert.equal(area.props.value, '4');
            assert.equal(status.props.value, 'In Review');

            module.props.onChange({ target: { value: 'BMS' } });
            area.props.onChange({ target: { value: '9' } });
            status.props.onChange({ target: { value: 'Received' } });
            toolbar.props.onClear();
            assert.equal(calls.length, 4);

            for (const [path, query] of calls) {
                assert.equal(path, 'submission-tracking.index');
                assert.equal(query.view, view);
                assert.equal(query.source, 'conservation');
                assert.equal(query.source_id, 71);
            }
            assert.equal(calls[0][1].module, 'BMS');
            assert.equal(calls[1][1].protected_area_id, '9');
            assert.equal(calls[2][1].status, 'Received');
            assert.equal(calls[3][1].search, undefined);
            assert.equal(calls[3][1].module, undefined);
            assert.equal(calls[3][1].protected_area_id, undefined);
            assert.equal(calls[3][1].status, undefined);
            assert.equal(calls[3][1].page, undefined);
            assert.deepEqual(calls[3][2], { preserveState: true, preserveScroll: true, replace: true });
        }
    } finally {
        if (previousRoute === undefined) delete globalThis.route;
        else globalThis.route = previousRoute;
        if (previousCalls === undefined) delete globalThis.__trackingRouterCalls;
        else globalThis.__trackingRouterCalls = previousCalls;
    }
});

test('standard Submission Tracking retains its separate layout and Clear all control', () => {
    const markup = renderTrackingWorkspace({ view: 'incoming', pamoWorkspace: false, filters: { search: 'forest' } });
    assert.match(markup, /<title>Submission Tracking<\/title>/);
    assert.match(markup, /Search protected area, report, or office/);
    assert.doesNotMatch(markup, /PAMO search and filters/);
    assert.match(markup, /Clear all/);
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
