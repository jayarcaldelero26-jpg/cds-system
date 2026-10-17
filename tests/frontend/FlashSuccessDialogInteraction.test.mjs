import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';

function createPageRuntime() {
    const states = new Map();
    const refs = new Map();
    const effects = new Map();
    let cursor = 0;
    let pending = [];

    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        if (!states.has(index)) states.set(index, typeof initial === 'function' ? initial() : initial);
        return [states.get(index), next => states.set(index, typeof next === 'function' ? next(states.get(index)) : next)];
    };
    runtime.useRef = initial => {
        const index = cursor++;
        if (!refs.has(index)) refs.set(index, { current: initial });
        return refs.get(index);
    };
    runtime.useEffect = (callback, dependencies = undefined) => {
        const index = cursor++;
        const previous = effects.get(index);
        const changed = !previous || !dependencies || dependencies.length !== previous.dependencies?.length
            || dependencies.some((value, position) => !Object.is(value, previous.dependencies[position]));
        if (changed) pending.push({ index, callback, dependencies });
    };
    runtime.useCallback = (callback, dependencies) => {
        const [memoized, setMemoized] = runtime.useState(() => ({ callback, dependencies }));
        if (!dependencies || dependencies.length !== memoized.dependencies?.length
            || dependencies.some((value, position) => !Object.is(value, memoized.dependencies[position]))) {
            setMemoized({ callback, dependencies });
            return callback;
        }
        return memoized.callback;
    };
    runtime.useMemo = callback => callback();
    return {
        runtime,
        render(Component, ...args) {
            cursor = 0;
            pending = [];
            return Component(...args);
        },
        flushEffects() {
            const work = pending;
            pending = [];
            for (const item of work) {
                effects.get(item.index)?.cleanup?.();
                const cleanup = item.callback();
                effects.set(item.index, { dependencies: item.dependencies, cleanup });
            }
        },
        unmount() {
            for (const effect of effects.values()) effect.cleanup?.();
            effects.clear();
        },
    };
}

async function loadArchivesAndFlashDialog(runtime) {
    const componentStubs = new Map([
        ['@/Layouts/AuthenticatedLayout', 'layout'],
        ['@/Components/Form/FilterToolbar', 'component'],
        ['@/Components/Crud/CrudFormModal', 'component'],
        ['@/Components/Crud/CrudSection', 'component'],
        ['@/Components/Attachments/AttachmentDropzone', 'component'],
        ['@/Components/Crud/FilePreviewPanel', 'component'],
        ['@/Components/Crud/LaravelPaginationLinks', 'component'],
        ['@/Components/PageHeader', 'component'],
        ['@/Components/SubmissionTracking/FinalArchiveResults', 'results'],
        ['@/Components/SubmissionTracking/FinalArchiveDisplayToggle', 'display'],
        ['@/Components/SubmissionTracking/FinalArchiveReader', 'reader'],
        ['@/Components/SubmissionTracking/FinalArchiveStatusTabs', 'tabs'],
        ['@/Components/SubmissionTracking/FinalArchivesFilterFields', 'component'],
        ['@/Components/PamoReportPendingAnimation', 'component'],
        ['@/Components/SuccessDialog', 'success'],
    ]);
    const utilityStubs = new Map([
        ['@/Utils/dateFormatters', 'dates'],
        ['@/Utils/finalArchivePresentation.mjs', 'presentation'],
        ['@/Utils/archiveThumbnailLoader.mjs', 'thumbnail'],
        ['@/Utils/finalArchiveActions.mjs', 'actions'],
        ['@/Utils/finalArchiveFilters.mjs', 'filters'],
        ['@/Utils/finalArchiveReceiptTime.mjs', 'receipt-time'],
        ['@/Utils/finalArchiveStatusPolling.mjs', 'polling'],
    ]);
    const plugin = {
        name: 'archives-flash-page-interaction-fixture',
        resolveId(source) {
            if (source === 'virtual:archives-flash-entry') return '\0archives-flash-entry';
            if (source === '@inertiajs/react') return '\0archives-inertia';
            if (source === '@iconify/react') return '\0archives-icon';
            const stub = componentStubs.get(source) || utilityStubs.get(source);
            return stub ? `\0archives-${stub}-${source}` : undefined;
        },
        load(id) {
            if (id === '\0archives-flash-entry') return [
                "import Archives from '@/Pages/SubmissionTracking/Archives.jsx';",
                "import FlashSuccessDialog from '@/Components/FlashSuccessDialog.jsx';",
                'export { Archives, FlashSuccessDialog };',
            ].join('\n');
            if (id === '\0archives-inertia') return [
                'export const router = globalThis.__archivesFlashFixture.router;',
                'export const usePage = () => globalThis.__archivesFlashFixture.page;',
                'export const useForm = value => ({ data: { ...value }, errors: {}, processing: false, reset() {}, clearErrors() {}, setData() {}, post() {} });',
            ].join('\n');
            if (id === '\0archives-icon') return 'export function Icon(props) { return null; }';
            if (id.includes('-layout-')) return 'import React from "react"; export default function AuthenticatedLayout(props) { return React.createElement("main", null, props.children); }';
            if (id.includes('-results-')) return 'import React from "react"; export default function FinalArchiveResults(props) { return React.createElement("archive-results", props); }';
            if (id.includes('-reader-')) return 'import React from "react"; export default function FinalArchiveReader(props) { return React.createElement("archive-reader", props); }';
            if (id.includes('-display-')) return 'import React from "react"; export default function FinalArchiveDisplayToggle(props) { return React.createElement("archive-display", props); }';
            if (id.includes('-tabs-')) return 'import React from "react"; export default function FinalArchiveStatusTabs(props) { return React.createElement("archive-tabs", props); }';
            if (id.includes('-success-')) return 'import React from "react"; export default function SuccessDialog(props) { return React.createElement("success-dialog", props); }';
            if (id.includes('-dates-')) return 'export const formatManila = value => String(value || "");';
            if (id.includes('-presentation-')) return [
                'export const archiveFiles = row => row?.manifest?.files || [];',
                'export const archiveReportTitle = row => row?.title || "Archive";',
                'export const archiveRowContext = row => row?.protected_area || "";',
                'export const archiveRowDate = () => null;',
                'export const archiveRowIdentity = row => row ? `${row.source}:${row.source_id}` : "";',
                'export const archivePendingStatus = () => ({ state: "pending", label: "Pending" });',
            ].join('\n');
            if (id.includes('-thumbnail-')) return 'export const archiveThumbnailScopeKey = auth => auth?.user?.id ? `user:${auth.user.id}` : "";';
            if (id.includes('-actions-')) return [
                'export const finalArchiveRequestFailureMessage = () => "Request failed";',
                'export const finalArchiveVisitErrorOptions = () => ({});',
                'export const postFinalArchive = () => {};',
                'export const postFinalArchiveRetry = () => {};',
                'export const registerFinalArchivePageGuard = () => () => {};',
            ].join('\n');
            if (id.includes('-filters-')) return 'export const applyFinalArchiveFilters = () => {};';
            if (id.includes('-receipt-time-')) return 'export const defaultManilaReceiptInput = () => ""; export const earliestManilaReceiptInput = () => "";';
            if (id.includes('-polling-')) return 'export const FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS = 1000; export const hasFinalArchiveStatusPollAttemptsRemaining = () => false; export const shouldPollFinalArchiveStatus = () => false;';
            if (id.includes('-component-')) return 'export default function Component() { return null; }';
        },
    };
    const bundle = await rolldown({
        input: 'virtual:archives-flash-entry',
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [plugin],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(id => id === 'react' ? runtime : createRequire(import.meta.url)(id), module, module.exports);
    return module.exports;
}

function findElement(root, type) {
    if (!React.isValidElement(root)) return null;
    if (root.type === type || root.type?.name === type) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, type);
        if (found) return found;
    }
    return null;
}

async function loadFlashSuccessDialog(runtime) {
    const plugin = {
        name: 'flash-success-interaction-fixture',
        resolveId(source) {
            if (source === '@inertiajs/react') return '\0flash-inertia-shim';
            if (source === '@/Components/SuccessDialog') return '\0flash-success-shim';
        },
        load(id) {
            if (id === '\0flash-inertia-shim') return [
                'export const router = globalThis.__flashSuccessFixture.router;',
                'export const usePage = () => globalThis.__flashSuccessFixture.page;',
            ].join('\n');
            if (id === '\0flash-success-shim') return 'export default function SuccessDialog(props) { return { type: "success-dialog", props }; }';
        },
    };
    const bundle = await rolldown({
        input: resolve('resources/js/Components/FlashSuccessDialog.jsx'),
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [plugin],
        resolve: { alias: { '@': resolve('resources/js') } },
        transform: { jsx: { runtime: 'automatic' } },
    });
    const { output } = await bundle.generate({ format: 'cjs' });
    await bundle.close();
    const module = { exports: {} };
    new Function('require', 'module', 'exports', output[0].code)(id => id === 'react' ? runtime : createRequire(import.meta.url)(id), module, module.exports);
    return module.exports.default || module.exports;
}

function dialogProps(tree) {
    assert.equal(tree?.type?.name, 'SuccessDialog');
    return tree.props;
}

test('one operation flash stays consumed across Archives reads, history restores, and remounts', async () => {
    const listeners = new Map();
    const router = {
        on(name, callback) {
            const entries = listeners.get(name) || new Set();
            entries.add(callback);
            listeners.set(name, entries);
            return () => entries.delete(callback);
        },
        emit(name, detail) {
            for (const callback of listeners.get(name) || []) callback({ detail });
        },
    };
    const message = 'The complete signed final set was verified and archived.';
    const page = { flash: {}, props: { flash: { success: message } } };
    globalThis.__flashSuccessFixture = { router, page };
    const hooks = createPageRuntime();
    const Dialog = await loadFlashSuccessDialog(hooks.runtime);

    // A refreshed/restored page may still carry the Laravel prop. It is not a new mutation event.
    let tree = hooks.render(Dialog);
    hooks.flushEffects();
    tree = hooks.render(Dialog);
    assert.equal(dialogProps(tree).open, false);

    // The actual mutation response carries one Inertia v3 page.flash payload.
    page.flash = { success: message };
    page.props.flash = { success: message };
    tree = hooks.render(Dialog);
    hooks.flushEffects();
    tree = hooks.render(Dialog);
    assert.equal(dialogProps(tree).open, true);
    dialogProps(tree).onClose();
    tree = hooks.render(Dialog);
    assert.equal(dialogProps(tree).open, false);

    // Opening/closing a reader, filters, paging, polling, and Back/Forward are GET navigations.
    for (const navigation of ['open-reader', 'close-reader', 'awaiting', 'archives', 'grid-list', 'filter', 'page', 'partial-poll', 'back', 'forward']) {
        page.flash = {};
        page.props.flash = { success: message };
        router.emit('success', { page, visitId: navigation });
        tree = hooks.render(Dialog);
        hooks.flushEffects();
        tree = hooks.render(Dialog);
        assert.equal(dialogProps(tree).open, false, `${navigation} does not replay the consumed operation notice`);
    }

    // Inertia history state strips page.flash; remounting with stale legacy props stays quiet.
    hooks.unmount();
    const remounted = createPageRuntime();
    const RemountedDialog = await loadFlashSuccessDialog(remounted.runtime);
    page.flash = {};
    page.props.flash = { success: message };
    tree = remounted.render(RemountedDialog);
    remounted.flushEffects();
    tree = remounted.render(RemountedDialog);
    assert.equal(dialogProps(tree).open, false);

    // A later, distinct operation with identical wording gets a fresh payload identity.
    page.flash = { success: message };
    page.props.flash = { success: message };
    tree = remounted.render(RemountedDialog);
    remounted.flushEffects();
    tree = remounted.render(RemountedDialog);
    assert.equal(dialogProps(tree).open, true);

    page.flash = { error: 'The archive could not be verified.' };
    page.props.flash = { error: 'The archive could not be verified.' };
    tree = remounted.render(RemountedDialog);
    remounted.flushEffects();
    tree = remounted.render(RemountedDialog);
    assert.equal(dialogProps(tree).open, true, 'changing page props does not dismiss an active success dialog');
    dialogProps(tree).onClose();
    tree = remounted.render(RemountedDialog);
    assert.equal(dialogProps(tree).open, false);
    remounted.unmount();
    delete globalThis.__flashSuccessFixture;
});

test('the real Archives open/close callbacks issue GETs and cannot replay the shared mutation notice', async () => {
    const calls = [];
    const page = { flash: {}, props: { flash: {}, auth: { user: { id: 73 }, canViewFinalArchives: true } } };
    const listeners = new Map();
    const router = {
        on(name, callback) {
            const entries = listeners.get(name) || new Set();
            entries.add(callback);
            listeners.set(name, entries);
            return () => entries.delete(callback);
        },
        emit(name, event) { for (const callback of listeners.get(name) || []) callback({ detail: event }); },
        get(url, data, options) {
            calls.push({ method: 'GET', url, data, options });
            page.flash = {};
            // Partial GETs can retain the previous legacy shared prop while v3 flash is empty.
            router.emit('success', { page });
        },
        post(url) { calls.push({ method: 'POST', url }); },
    };
    globalThis.__archivesFlashFixture = { page, router };
    globalThis.__flashSuccessFixture = { page, router };
    globalThis.route = name => name === 'final-archives.index' ? '/submission-tracking/archives' : `/${name}`;
    globalThis.window = {
        location: { search: '?page=3&receipt_page=2&ignored=drop' },
        setTimeout: () => 1,
        clearTimeout() {},
        requestAnimationFrame: callback => callback(),
        cancelAnimationFrame() {},
    };

    const dialogHooks = createPageRuntime();
    const pageHooks = createPageRuntime();
    const { Archives } = await loadArchivesAndFlashDialog(pageHooks.runtime);
    const Dialog = await loadFlashSuccessDialog(dialogHooks.runtime);
    const message = 'The complete signed final set was verified and archived.';

    page.flash = { success: message };
    page.props.flash.success = message;
    let dialogTree = dialogHooks.render(Dialog);
    dialogHooks.flushEffects();
    dialogTree = dialogHooks.render(Dialog);
    assert.equal(dialogTree.props.open, true);
    dialogTree.props.onClose();
    assert.equal(dialogHooks.render(Dialog).props.open, false);

    const row = {
        source: 'conservation', source_id: 41, title: 'TWC Meetings · TWC Meeting',
        protected_area: 'Baganga Mangrove Swamp Forest Reserve',
        manifest: { id: 9, revision: 1, status: 'ARCHIVED', files: [{ id: 91, name: 'TWC.pdf', mime_type: 'application/pdf', status: 'ARCHIVED', preview_url: '/protected/91', download_url: '/protected/91?download=1' }] },
    };
    const pageTree = pageHooks.render(Archives, { archives: { data: [row], links: [], total: 1 } });
    const results = findElement(pageTree, 'FinalArchiveResults');
    assert.ok(results, 'the actual Archives page renders its archive result component');
    results.props.onOpen(row);
    dialogTree = dialogHooks.render(Dialog);
    dialogHooks.flushEffects();
    assert.equal(dialogHooks.render(Dialog).props.open, false, 'opening the reader does not replay the legacy prop');

    const tabs = findElement(pageTree, 'FinalArchiveStatusTabs');
    const display = findElement(pageTree, 'FinalArchiveDisplayToggle');
    tabs.props.onChange('awaiting');
    display.props.onChange('list');
    const reader = findElement(pageTree, 'FinalArchiveReader');
    assert.ok(reader, 'the actual Archives page renders its reader callback');
    reader.props.onClose();
    dialogTree = dialogHooks.render(Dialog);
    dialogHooks.flushEffects();
    assert.equal(dialogHooks.render(Dialog).props.open, false, 'closing the reader does not replay the legacy prop');

    assert.deepEqual(calls.map(call => call.method), ['GET', 'GET']);
    assert.equal(calls[0].url, '/submission-tracking/archives');
    assert.deepEqual(calls[0].data, { page: '3', receipt_page: '2', source: 'conservation', record: 41 });
    assert.deepEqual(calls[1].data, { page: '3', receipt_page: '2' });
    assert.ok(calls.every(call => !['POST', 'PUT', 'PATCH', 'DELETE'].includes(call.method)));
    delete globalThis.__archivesFlashFixture;
    delete globalThis.__flashSuccessFixture;
    delete globalThis.route;
    delete globalThis.window;
});
