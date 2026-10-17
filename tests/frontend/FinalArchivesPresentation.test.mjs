import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { fitPageScale, fitWidthScale } from '../../resources/js/Utils/pdfViewerGeometry.mjs';
import {
    archiveFileTitle,
    archiveFiles,
    archiveHasReadableFiles,
    archiveHasVerifiedFiles,
    archivePendingStatus,
    archiveReportTitle,
    archiveRowContext,
    archiveRowDate,
    archiveRowIdentity,
} from '../../resources/js/Utils/finalArchivePresentation.mjs';

function stateHooks(initialStates = {}) {
    let cursor = 0;
    const states = [];
    const refs = [];
    const effects = [];
    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        if (!(index in states)) states[index] = index in initialStates ? initialStates[index] : typeof initial === 'function' ? initial() : initial;
        return [states[index], next => { states[index] = typeof next === 'function' ? next(states[index]) : next; }];
    };
    runtime.useRef = initial => {
        const index = cursor++;
        if (!(index in refs)) refs[index] = { current: initial };
        return refs[index];
    };
    runtime.useEffect = callback => { effects.push(callback); };
    runtime.useLayoutEffect = () => {};
    runtime.useMemo = callback => callback();
    runtime.useCallback = callback => callback;
    return {
        runtime,
        beginRender() { cursor = 0; },
        setState(index, value) { states[index] = value; },
        setRef(index, value) { refs[index] = refs[index] || { current: null }; refs[index].current = value; },
        runEffects() { return effects.splice(0).map(effect => effect()).filter(cleanup => typeof cleanup === 'function'); },
    };
}

async function loadComponent(path, hooks = React, { stubPortal = false } = {}) {
    const shimPlugin = {
        name: 'archive-reader-render-shims',
        resolveId(source) {
            if (stubPortal && source === 'react-dom') return '\0archive-portal-shim';
            if (source === 'pdfjs-dist') return '\0archive-pdfjs-shim';
            if (source === 'pdfjs-dist/build/pdf.worker.min.mjs?url') return '\0archive-pdfjs-worker-shim';
            if (source.endsWith('.css')) return '\0archive-css-shim';
        },
        load(id) {
            if (id === '\0archive-portal-shim') return 'export const createPortal = node => node;';
            if (id === '\0archive-pdfjs-shim') return 'export const GlobalWorkerOptions = {}; export const getDocument = () => { throw new Error("PDF rendering is unavailable in this markup fixture"); };';
            if (id === '\0archive-pdfjs-worker-shim') return 'export default "/pdfjs-worker-fixture.mjs";';
            if (id === '\0archive-css-shim') return '';
        },
    };
    const bundle = await rolldown({
        input: resolve(path),
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [shimPlugin],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(id => id === 'react' ? hooks : createRequire(import.meta.url)(id), module, module.exports);
    return module.exports.default || module.exports;
}

function findElement(root, predicate) {
    if (!React.isValidElement(root)) return null;
    if (predicate(root)) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
    return null;
}

const bms = {
    id: 'bms:8:22', source: 'bms', source_id: 8, module: 'BMS Final Return', title: 'BMS Final Return',
    protected_area: 'Mount Hamiguitan Range Wildlife Sanctuary',
    manifest: { status: 'ARCHIVED', finalized_at: '2026-10-16T13:00:00Z', files: [
        { id: 81, name: 'BMS-8-final-signed.pdf', mime_type: 'application/pdf', status: 'ARCHIVED', preview_url: '/protected/81?preview=1', download_url: '/protected/81?download=1' },
    ] },
};
const sharedPambFilename = 'Regular_PAMB_Meetings_Minutes_2026.pdf';
const restorationPlan = {
    id: 'conservation:40:51', source: 'conservation', source_id: 40,
    module: '5 Year Restoration Plan Preparation', title: 'Restoration Plan Workshop',
    protected_area: 'Baganga Mangrove Swamp Forest Reserve',
    manifest: { id: 51, revision: 1, status: 'ARCHIVED', files: [
        { id: 401, name: sharedPambFilename, mime_type: 'application/pdf', status: 'ARCHIVED', preview_url: '/protected/401?preview=1', download_url: '/protected/401?download=1' },
    ] },
};
const bmsWithSharedFilename = {
    ...bms, module: 'BMS Report', title: 'Conduct 1st Semester Biodiversity Monitoring System',
    manifest: { ...bms.manifest, files: [
        { ...bms.manifest.files[0], name: sharedPambFilename },
    ] },
};
const multi = {
    id: 'conservation:39:33', source: 'conservation', source_id: 39, module: 'Conservation report', title: 'Aliwagwag restoration visit',
    protected_area: 'Aliwagwag Protected Landscape',
    manifest: { status: 'ARCHIVED', finalized_at: '2026-10-15T13:00:00Z', files: [
        { id: 391, name: 'Aliwagwag-return.pdf', mime_type: 'application/pdf', status: 'ARCHIVED', preview_url: '/protected/391?preview=1', download_url: '/protected/391?download=1' },
        { id: 392, name: 'Aliwagwag-annex.docx', mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', status: 'ARCHIVED', preview_url: '/protected/392?preview=1', download_url: '/protected/392?download=1' },
    ] },
};
const earlierDispatch = {
    id: 'conservation:39:dispatch:14', source: 'conservation', source_id: 39,
    module: 'Conservation report', title: 'Regular PAMB Meetings', protected_area: 'Current source area',
    earlier_dispatch_copy: true,
    manifest: { id: 'dispatch-14', status: 'EARLIER_DISPATCH_COPY', archived_at: '2026-10-11T16:09:53Z', files: [
        { id: 14, name: 'regular-pamb.pdf', mime_type: 'application/pdf', status: 'ARCHIVED', preview_url: '/dispatch/14?preview=1', download_url: '/dispatch/14?download=1' },
    ] },
};

test('archive presentation keeps one record per card and reports real file-set state', () => {
    assert.equal(archiveFileTitle(bms), 'BMS-8-final-signed.pdf');
    assert.equal(archiveFileTitle({ title: 'Field inspection', module: 'BMS' }), 'Field inspection');
    assert.equal(archiveReportTitle(restorationPlan), '5 Year Restoration Plan Preparation · Restoration Plan Workshop');
    assert.equal(archiveReportTitle(bmsWithSharedFilename), 'BMS Report · Conduct 1st Semester Biodiversity Monitoring System');
    assert.equal(archiveFileTitle(restorationPlan), sharedPambFilename);
    assert.equal(archiveFileTitle(bmsWithSharedFilename), sharedPambFilename);
    assert.equal(archiveReportTitle({ source: 'conservation', source_id: 41, title: '', module: '', manifest: { files: [{ name: sharedPambFilename }] } }), 'Conservation report #41');
    assert.equal(archiveRowIdentity(restorationPlan), 'conservation:40');
    assert.equal(archiveRowIdentity(bmsWithSharedFilename), 'bms:8');
    assert.equal(archiveFiles(multi).length, 2);
    assert.equal(archiveHasVerifiedFiles(bms), true);
    assert.equal(archiveHasVerifiedFiles({ ...bms, manifest: { ...bms.manifest, status: 'PROCESSING' } }), false);
    assert.equal(archiveHasVerifiedFiles({ ...bms, manifest: { ...bms.manifest, files: [{ ...bms.manifest.files[0], status: 'FAILED' }] } }), false);
    assert.equal(archiveHasVerifiedFiles(earlierDispatch), false, 'a prior Dispatch file is never represented as a verified final return');
    assert.equal(archiveHasReadableFiles(earlierDispatch), true, 'the protected endpoint rechecks an earlier copy on open');
    assert.equal(archiveRowDate(earlierDispatch, 'archives'), earlierDispatch.manifest.archived_at);
    assert.equal(archiveRowIdentity(bms), 'bms:8');
    assert.equal(archiveRowContext({ ...bms, title: 'BMS Final Return' }), 'Mount Hamiguitan Range Wildlife Sanctuary');
    assert.deepEqual(archivePendingStatus({ manifest: { status: 'PROCESSING', retry_available: false } }, 'awaiting'), { label: 'Processing', state: 'processing' });
    assert.deepEqual(archivePendingStatus({ manifest: { status: 'PROCESSING', retry_available: true } }, 'awaiting'), { label: 'Ready to retry', state: 'retry' });
    assert.deepEqual(archivePendingStatus({ manifest: { status: 'FAILED' } }, 'awaiting'), { label: 'Failed', state: 'failed' });
    assert.deepEqual(archivePendingStatus({}, 'receipt'), { label: 'Awaiting Final Receipt', state: 'pending' });
});

test('earlier Dispatch copy cards are labeled and their protected reader makes no final-return claim', async () => {
    const hooks = stateHooks();
    const Card = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveCard.jsx', hooks.runtime);
    hooks.beginRender();
    let card = Card({ row: earlierDispatch, onOpen() {} });
    assert.match(renderToStaticMarkup(card), /Earlier Dispatch copy/);
    findElement(card, element => element.type === 'button' && element.props['aria-haspopup'] === 'menu').props.onClick({ stopPropagation() {} });
    hooks.beginRender();
    card = Card({ row: earlierDispatch, onOpen() {} });
    assert.ok(findElement(card, element => element.type === 'button' && element.props.role === 'menuitem' && element.props.children === 'Open earlier copy'));

    const Reader = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx', hooks.runtime, { stubPortal: true });
    const previousDocument = globalThis.document;
    globalThis.document = { body: {} };
    try {
        const selected = {
            source: earlierDispatch.source, source_id: earlierDispatch.source_id,
            earlier_dispatch_copy: {
                archive_id: 14, archived_at: earlierDispatch.manifest.archived_at,
                availability_note: 'Availability is rechecked when the earlier copy is opened.',
                file: earlierDispatch.manifest.files[0],
            },
            latest_manifest: null, receipt: null, can_receive_final: false, can_archive_final: false,
            final_action_unavailable_reason: 'The report\'s Protected Area and supervising-office assignment do not resolve to an eligible Regional-return PENRO under the current jurisdiction rule. A records review is needed before a receipt can be recorded.',
        };
        const props = { open: true, selected, initialTab: 'details', onClose() {} };
        hooks.beginRender();
        let tree = Reader(props);
        tree = tree.type(tree.props);
        const markup = renderToStaticMarkup(tree);
        assert.match(markup, /This is an earlier Dispatch archive copy/);
        assert.match(markup, /Availability is rechecked when the earlier copy is opened/);
        assert.match(markup, /Earlier Dispatch copy/);
        assert.doesNotMatch(markup, /verified final archive|Signed documents/);
        assert.match(markup, /archive-tab-document/);
        assert.match(markup, /archive-tab-details/);
        assert.match(markup, /archive-tab-history/);
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
    }
});

test('Grid/List switching changes only presentation and preserves the paginated record set and selection', async () => {
    const Results = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveResults.jsx');
    const Toggle = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveDisplayToggle.jsx');
    const queryContext = { tab: 'archives', filters: { search: 'BMS', protected_area: 'Mount Hamiguitan Range Wildlife Sanctuary' }, page: 4, selected: 'bms:8' };
    const before = structuredClone(queryContext);
    let mode = 'grid';
    const toggler = Toggle({ value: mode, onChange: next => { mode = next; } });
    const listButton = findElement(toggler, element => element.type === 'button' && element.props['aria-pressed'] === false);
    assert.ok(listButton, 'List mode control is rendered');
    listButton.props.onClick();
    assert.equal(mode, 'list');
    assert.deepEqual(queryContext, before);

    const props = { rows: [restorationPlan, bmsWithSharedFilename, multi], tab: queryContext.tab, selectedIdentity: queryContext.selected, columns: [
        { key: 'name', label: 'Report / original file', render: row => `${archiveReportTitle(row)} · File: ${archiveFiles(row)[0]?.name || ''}` },
    ], onOpen() {} };
    const grid = renderToStaticMarkup(React.createElement(Results, { ...props, displayMode: 'grid' }));
    const list = renderToStaticMarkup(React.createElement(Results, { ...props, displayMode: mode }));
    assert.match(grid, /data-archive-display="grid" data-archive-result-count="3"/);
    assert.equal((grid.match(/data-final-archive-card=/g) || []).length, 3, 'a two-part set still occupies one archive card');
    assert.match(grid, /aria-current="true"/);
    assert.match(list, /data-archive-display="list" data-archive-result-count="3"/);
    assert.equal((list.match(/<tr[^>]*aria-label=/g) || []).length, 3, 'list mode contains the same server rows');
    for (const name of [
        '5 Year Restoration Plan Preparation · Restoration Plan Workshop',
        'BMS Report · Conduct 1st Semester Biodiversity Monitoring System',
        sharedPambFilename,
        'Aliwagwag-return.pdf',
    ]) {
        assert.ok(grid.includes(name), `grid includes ${name}`);
        assert.ok(list.includes(name), `list includes ${name}`);
    }
    assert.match(list, /selected record/);
    assert.deepEqual(queryContext, before);
});

test('card activation and its menu are isolated and expose only supported single-file download', async () => {
    const hooks = stateHooks();
    const Card = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveCard.jsx', hooks.runtime);
    const opened = [];
    const props = { row: bms, tab: 'archives', selected: true, onOpen: (...args) => opened.push(args) };
    hooks.beginRender();
    let tree = Card(props);
    const openButton = findElement(tree, element => element.type === 'button' && String(element.props['aria-label'] || '').startsWith('Open BMS Final Return'));
    openButton.props.onClick();
    assert.equal(opened[0][0], bms);
    assert.equal(opened[0][1], 'document');

    hooks.beginRender();
    tree = Card(props);
    const menuButton = findElement(tree, element => element.type === 'button' && element.props['aria-haspopup'] === 'menu');
    let stopped = false;
    menuButton.props.onClick({ stopPropagation() { stopped = true; } });
    assert.equal(stopped, true);
    hooks.beginRender();
    tree = Card(props);
    const detailsItem = findElement(tree, element => element.type === 'button' && element.props.role === 'menuitem' && element.props.children === 'Details');
    detailsItem.props.onClick();
    assert.equal(opened.at(-1)[1], 'details');

    hooks.beginRender();
    tree = Card({ row: bms, tab: 'archives', onOpen() {} });
    findElement(tree, element => element.type === 'button' && element.props['aria-haspopup'] === 'menu').props.onClick({ stopPropagation() {} });
    hooks.beginRender();
    tree = Card({ row: bms, tab: 'archives', onOpen() {} });
    const download = findElement(tree, element => element.type === 'a' && element.props.role === 'menuitem' && element.props.children === 'Download');
    assert.equal(download.props.href, '/protected/81?download=1');

    hooks.beginRender();
    tree = Card({ row: multi, tab: 'archives', onOpen() {} });
    findElement(tree, element => element.type === 'button' && element.props['aria-haspopup'] === 'menu').props.onClick({ stopPropagation() {} });
    hooks.beginRender();
    tree = Card({ row: multi, tab: 'archives', onOpen() {} });
    assert.equal(findElement(tree, element => element.type === 'a' && element.props.role === 'menuitem' && element.props.children === 'Download'), null);

    hooks.beginRender();
    tree = Card({ row: { ...bms, manifest: { ...bms.manifest, status: 'PROCESSING' } }, tab: 'awaiting', onOpen() {} });
    findElement(tree, element => element.type === 'button' && element.props['aria-haspopup'] === 'menu').props.onClick({ stopPropagation() {} });
    hooks.beginRender();
    tree = Card({ row: { ...bms, manifest: { ...bms.manifest, status: 'PROCESSING' } }, tab: 'awaiting', onOpen() {} });
    assert.equal(findElement(tree, element => element.props.role === 'menuitem' && /^(Preview|Download)$/.test(element.props.children)), null, 'incomplete manifests never expose stale descriptors');
});

test('card grid markup contains no protected document preview request or file URL', async () => {
    const Results = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveResults.jsx');
    const markup = renderToStaticMarkup(React.createElement(Results, { rows: [bms, multi], displayMode: 'grid', onOpen() {} }));
    assert.doesNotMatch(markup, /protected\/(?:81|391|392)\?preview=1/);
    assert.doesNotMatch(markup, /protected\/(?:81|391|392)\?download=1/);
    assert.match(markup, /2 files/);
});

test('card menu closes on Escape and restores focus to its trigger', async () => {
    const hooks = stateHooks();
    const Card = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveCard.jsx', hooks.runtime);
    const originalDocument = globalThis.document;
    const originalFrame = globalThis.requestAnimationFrame;
    const listeners = new Map();
    let focusCount = 0;
    globalThis.document = {
        addEventListener(name, callback) { listeners.set(name, callback); },
        removeEventListener(name, callback) { if (listeners.get(name) === callback) listeners.delete(name); },
    };
    globalThis.requestAnimationFrame = callback => { callback(); return 1; };
    hooks.setRef(2, { focus() { focusCount += 1; } });
    try {
        hooks.beginRender();
        let tree = Card({ row: bms, onOpen() {} });
        findElement(tree, element => element.type === 'button' && element.props['aria-haspopup'] === 'menu').props.onClick({ stopPropagation() {} });
        hooks.beginRender();
        tree = Card({ row: bms, onOpen() {} });
        const cleanup = hooks.runEffects();
        assert.ok(findElement(tree, element => element.props.role === 'menu'));
        assert.equal(typeof listeners.get('keydown'), 'function');
        let defaultPrevented = false;
        let propagationStopped = false;
        listeners.get('keydown')({
            key: 'Escape',
            preventDefault() { defaultPrevented = true; },
            stopPropagation() { propagationStopped = true; },
        });
        hooks.beginRender();
        tree = Card({ row: bms, onOpen() {} });
        assert.equal(findElement(tree, element => element.props.role === 'menu'), null);
        assert.equal(defaultPrevented, true);
        assert.equal(propagationStopped, true);
        assert.equal(focusCount, 1);
        cleanup.forEach(dispose => dispose());
        assert.equal(listeners.size, 0);
    } finally {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
        if (originalFrame === undefined) delete globalThis.requestAnimationFrame;
        else globalThis.requestAnimationFrame = originalFrame;
    }
});

test('compact reader keeps the protected file mounted across tabs and layout changes', async () => {
    const hooks = stateHooks();
    const Reader = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx', hooks.runtime, { stubPortal: true });
    const originalDocument = globalThis.document;
    globalThis.document = { body: {} };
    const selected = {
        ...multi,
        latest_manifest: { ...multi.manifest, files: multi.manifest.files.map(file => file.id === 392 ? { ...file, name: 'Aliwagwag-annex.pdf', mime_type: 'application/pdf' } : file) },
        routing_timeline: [{ id: 1, label: 'Returned to PENRO', occurred_at: '2026-10-15T12:00:00Z' }],
        can_receive_final: false,
        can_archive_final: false,
    };
    try {
        const props = { open: true, selected, initialTab: 'document', onClose() {} };
        hooks.beginRender();
        let tree = Reader(props);
        tree = tree.type(tree.props);
        const dialog = findElement(tree, element => element.type === 'section' && element.props.role === 'dialog');
        assert.ok(dialog);
        assert.equal(dialog.props['aria-label'], archiveReportTitle(selected), 'the report/workflow heading stays primary when a file changes');
        assert.match(dialog.props.className, /w-\[min\(960px,calc\(100vw-32px\)\)\]/);
        const documentTab = findElement(tree, element => element.props.id === 'archive-tab-document');
        const fileSelector = findElement(tree, element => element.props.id === 'archive-reader-file');
        assert.ok(documentTab);
        assert.ok(fileSelector, 'the multiple signed files have a compact selector');
        assert.equal(fileSelector.props.value, 391);
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.file.id, 391);
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.viewerOptions.layoutKey, 'document:false:391');

        fileSelector.props.onChange({ target: { value: '392' } });
        hooks.beginRender();
        tree = Reader(props);
        tree = tree.type(tree.props);
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.file.id, 392);

        findElement(tree, element => element.props.id === 'archive-tab-details').props.onClick();
        hooks.beginRender();
        tree = Reader(props);
        tree = tree.type(tree.props);
        const hiddenDocumentPanel = findElement(tree, element => element.props.id === 'archive-tabpanel-document');
        assert.equal(hiddenDocumentPanel.props.hidden, true);
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.file.id, 392, 'the selected preview remains mounted after switching to Details');
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.viewerOptions.layoutKey, 'details:false:392');
        assert.ok(findElement(tree, element => element.props.id === 'archive-tabpanel-history'));

        findElement(tree, element => element.type === 'button' && element.props['aria-label'] === 'Expand reader').props.onClick();
        hooks.beginRender();
        tree = Reader(props);
        tree = tree.type(tree.props);
        assert.match(findElement(tree, element => element.type === 'section' && element.props.role === 'dialog').props.className, /h-\[100dvh\]/);
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.file.id, 392, 'Expand preserves the selected file without changing its protected URL');
        assert.equal(findElement(tree, element => element.props.file?.id)?.props.viewerOptions.layoutKey, 'details:true:392');
    } finally {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    }
});

test('single-file reader presents report identity while preserving the exact original filename', async () => {
    const hooks = stateHooks();
    const Reader = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx', hooks.runtime, { stubPortal: true });
    const originalDocument = globalThis.document;
    globalThis.document = { body: {} };
    try {
        const selected = { ...restorationPlan, latest_manifest: restorationPlan.manifest, receipt: null, can_receive_final: false, can_archive_final: false };
        hooks.beginRender();
        let tree = Reader({ open: true, selected, initialTab: 'document', onClose() {} });
        tree = tree.type(tree.props);
        const dialog = findElement(tree, element => element.type === 'section' && element.props.role === 'dialog');
        const filename = findElement(tree, element => element.props['data-archive-original-filename'] !== undefined);
        assert.equal(dialog.props['aria-label'], archiveReportTitle(selected));
        assert.equal(filename.props.title, sharedPambFilename);
        assert.equal(filename.props.children, sharedPambFilename);
    } finally {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    }
});

test('reader shows only server-eligible pending actions and hides completed actions', async () => {
    const Reader = await loadComponent('resources/js/Components/SubmissionTracking/FinalArchiveReader.jsx', React, { stubPortal: true });
    const originalDocument = globalThis.document;
    globalThis.document = { body: {} };
    try {
        const completed = renderToStaticMarkup(React.createElement(Reader, {
            open: true,
            selected: { ...bms, latest_manifest: bms.manifest, can_receive_final: false, can_archive_final: false, final_action_unavailable_reason: 'This report already has a verified final archive.' },
            onClose() {},
        }));
        assert.match(completed, /BMS-8-final-signed\.pdf/);
        assert.doesNotMatch(completed, /Receive Final MOVs|Retry Archive|No additional receipt or upload/);
        assert.doesNotMatch(completed, /Final Regional-return documents|Custody and archive history|Close Preview/);

        const pending = renderToStaticMarkup(React.createElement(Reader, {
            open: true,
            initialTab: 'details',
            selected: {
                source: 'bms', source_id: 8, module: 'BMS Final Return', can_receive_final: false, can_archive_final: true,
                latest_manifest: { id: 23, status: 'FAILED', retry_available: true, files: [] },
            },
            onClose() {}, onRetry() {},
        }));
        assert.match(pending, /Archive verification failed\. The receipt and signed files are retained for retry\./);
        assert.match(pending, /Retry Archive/);

        const unauthorized = renderToStaticMarkup(React.createElement(Reader, {
            open: true,
            initialTab: 'details',
            selected: {
                source: 'bms', source_id: 8, module: 'BMS Final Return', can_receive_final: false, can_archive_final: false,
                final_action_unavailable_reason: 'The PENRO CDS Focal account category is required.',
                latest_manifest: { id: 23, status: 'FAILED', retry_available: false, files: [] },
            },
            onClose() {}, onRetry() {},
        }));
        assert.match(unauthorized, /The PENRO CDS Focal account category is required\./);
        assert.doesNotMatch(unauthorized, /<button[^>]*>Retry Archive<\/button>/);
    } finally {
        if (originalDocument === undefined) delete globalThis.document;
        else globalThis.document = originalDocument;
    }
});

test('archive PDF mode adds fit-width and one authorized download control without changing shared previews', async () => {
    const Viewer = await loadComponent('resources/js/Components/SubmissionTracking/PdfDocumentViewer.jsx');
    const archiveMarkup = renderToStaticMarkup(React.createElement(Viewer, {
        archiveReader: true, blob: new Blob(), downloadUrl: '/protected/81?download=1', downloadName: 'final.pdf',
    }));
    const sharedMarkup = renderToStaticMarkup(React.createElement(Viewer, { blob: new Blob() }));
    assert.match(archiveMarkup, />Fit page</);
    assert.match(archiveMarkup, />Fit width</);
    assert.match(archiveMarkup, /href="\/protected\/81\?download=1"[^>]*download="final\.pdf"/);
    assert.ok(archiveMarkup.includes('of —'));
    assert.doesNotMatch(sharedMarkup, /Fit width|protected\/81\?download/);
});

test('archive PDF controls apply measured Fit page, Fit width, resize, and manual zoom to the actual viewer scale', async () => {
    const hooks = stateHooks({
        8: 3,
        9: { 1: { width: 612, height: 792 }, 2: { width: 612, height: 792 }, 3: { width: 612, height: 792 } },
        13: { width: 800, height: 600 },
        15: 'ready',
    });
    const stage = { clientWidth: 800, clientHeight: 600, scrollTo() {} };
    hooks.setRef(0, stage);
    const originalFrame = globalThis.requestAnimationFrame;
    globalThis.requestAnimationFrame = () => 1;
    try {
        const Viewer = await loadComponent('resources/js/Components/SubmissionTracking/PdfDocumentViewer.jsx', hooks.runtime);
        const props = { archiveReader: true, blob: new Blob() };
        const percentage = tree => findElement(tree, element => element.props['aria-label'] === 'Zoom percentage').props.children;
        hooks.beginRender();
        let tree = Viewer(props);
        assert.equal(percentage(tree), `${Math.round(fitPageScale(612, 792, 768, 568) * 100)}%`, 'the reader opens with the full page fitted to measured width and height');

        findElement(tree, element => element.type === 'button' && element.props.children === 'Fit width').props.onClick();
        hooks.beginRender();
        tree = Viewer(props);
        assert.equal(percentage(tree), `${Math.round(fitWidthScale(612, 768) * 100)}%`, 'Fit width fills the measured inner width');

        findElement(tree, element => element.type === 'button' && element.props['aria-label'] === 'Zoom in').props.onClick();
        hooks.beginRender();
        tree = Viewer(props);
        assert.equal(percentage(tree), `${Math.round(fitWidthScale(612, 768) * 1.2 * 100)}%`, 'manual zoom changes the PDF render scale');

        hooks.setState(13, { width: 700, height: 500 });
        hooks.beginRender();
        tree = Viewer(props);
        assert.equal(percentage(tree), `${Math.round(fitWidthScale(612, 668) * 1.2 * 100)}%`, 'Fit width recomputes when the reader is resized');

        findElement(tree, element => element.type === 'button' && element.props.children === 'Fit page').props.onClick();
        hooks.beginRender();
        tree = Viewer(props);
        assert.equal(percentage(tree), `${Math.round(fitPageScale(612, 792, 668, 468) * 100)}%`, 'Fit page recomputes against resized width and height');
    } finally {
        if (originalFrame === undefined) delete globalThis.requestAnimationFrame;
        else globalThis.requestAnimationFrame = originalFrame;
    }
});
