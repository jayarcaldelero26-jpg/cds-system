import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

function stateHooks() {
    let cursor = 0;
    const states = [];
    const refs = [];
    const effects = [];
    const runtime = Object.create(React);
    runtime.useState = initial => {
        const index = cursor++;
        if (!(index in states)) states[index] = typeof initial === 'function' ? initial() : initial;
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
        clearEffects() { effects.length = 0; },
        runEffects() { return effects.splice(0).map(effect => effect()).filter(cleanup => typeof cleanup === 'function'); },
    };
}

async function loadComponent(path, hooks) {
    const bundle = await rolldown({
        input: resolve(`resources/js/Components/SubmissionTracking/${path}.jsx`),
        external: id => id === 'react' || id.startsWith('react/'),
        plugins: [{
            name: 'shared-reader-integration-fixtures',
            resolveId(source) {
                if (source === 'react-dom') return '\0shared-reader-portal';
                if (source === '@/Utils/protectedDocumentPreview.mjs') return '\0shared-reader-protected-preview';
                if (source === '@/Components/ModalMotion/useModalMotion') return '\0shared-reader-modal-motion';
                if (source === '@/Components/Attachments/AttachmentDropzone') return '\0shared-reader-attachment-dropzone';
                if (source === '@/Utils/downloadWithProgress') return '\0shared-reader-download-progress';
                if (source === '@/Components/Button') return '\0shared-reader-button';
                if (source === '@inertiajs/react') return '\0shared-reader-inertia';
                if (source === '@iconify/react') return '\0shared-reader-icons';
                if (source === 'pdfjs-dist') return '\0shared-reader-pdfjs';
                if (source === 'pdfjs-dist/build/pdf.worker.min.mjs?url') return '\0shared-reader-pdfjs-worker';
                if (source.endsWith('.css')) return '\0shared-reader-css';
            },
            load(id) {
                if (id === '\0shared-reader-portal') return 'export const createPortal = node => node;';
                if (id === '\0shared-reader-protected-preview') return `
                    export function inlinePreviewType(mime) { return ['application/pdf', 'image/jpeg', 'image/png'].includes(String(mime || '').toLowerCase()) ? String(mime).toLowerCase() : null; }
                    export function startProtectedPreview(url, options) {
                        globalThis.__sharedReaderCalls.push(url);
                        options.onState(globalThis.__sharedReaderState);
                        return { dispose() { globalThis.__sharedReaderDisposals.push(url); } };
                    }
                `;
                if (id === '\0shared-reader-modal-motion') return `export function useModalMotion(open) { return { present: Boolean(open), phase: open ? 'open' : 'closed', onTransitionEnd() {} }; }`;
                if (id === '\0shared-reader-attachment-dropzone') return `import React from 'react'; export default function AttachmentDropzone(props) { return React.createElement('div', { 'data-dropzone-id': props.id }); }`;
                if (id === '\0shared-reader-download-progress') return `export async function downloadWithProgress() {}`;
                if (id === '\0shared-reader-button') return `import React from 'react'; export default function Button(props) { return React.createElement('button', props, props.children); }`;
                if (id === '\0shared-reader-inertia') return `import React from 'react'; export const usePage = () => ({ props: { auth: { user: { id: 42 } } } }); export const router = {}; export const useForm = value => ({ data: value, processing: false, errors: {} });`;
                if (id === '\0shared-reader-icons') return `import React from 'react'; export function Icon({ icon, ...props }) { return React.createElement('span', { ...props, 'data-icon': icon }); }`;
                if (id === '\0shared-reader-pdfjs') return 'export const GlobalWorkerOptions = {}; export const getDocument = () => { throw new Error("PDF rendering is unavailable in this fixture"); };';
                if (id === '\0shared-reader-pdfjs-worker') return 'export default "/pdfjs-worker-fixture.mjs";';
                if (id === '\0shared-reader-css') return '';
            },
        }],
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

function previewTree(component, props, hooks) {
    hooks.beginRender();
    const dialog = component(props);
    return dialog.type(dialog.props);
}

const imagePreview = (url, name, id) => ({
    id,
    name,
    mime_type: 'image/png',
    size: 2048,
    can_preview: true,
    preview_url: url,
    download_url: url.replace('?preview=1', '?download=1'),
});

test('standard and PAMO current files share one protected reader and keep selection, layout, and download bound to the descriptor', async () => {
    const hooks = stateHooks();
    const Preview = await loadComponent('DocumentPreviewDialog', hooks.runtime);
    const originalDocument = globalThis.document;
    globalThis.document = { body: {} };
    globalThis.__sharedReaderCalls = [];
    globalThis.__sharedReaderDisposals = [];
    globalThis.__sharedReaderState = { status: 'ready', type: 'image/png', blob: new Blob(['image bytes']), url: 'blob:working-first' };
    let closeCount = 0;

    const standardRow = {
        source: 'bms', source_id: 8, module: 'BMS Final Return',
        protected_area: 'Mount Hamiguitan Range Wildlife Sanctuary', reporting_period: '2026', document_type: 'MOV',
        current_document: imagePreview('/submission-tracking/bms/8/current?preview=1', 'BMS-8-working.png', 'working-8'),
        routing_timeline: [{ id: 801, label: 'Returned for Correction', occurred_at: '2026-10-16T09:00:00Z', actor: 'Records Officer', office: 'PENRO' }],
    };
    let firstTree = previewTree(Preview, { open: true, row: standardRow, accountKey: 42, onClose() { closeCount += 1; } }, hooks);
    const firstEffects = hooks.runEffects();
    assert.deepEqual(globalThis.__sharedReaderCalls, ['/submission-tracking/bms/8/current?preview=1']);
    firstTree = previewTree(Preview, { open: true, row: standardRow, accountKey: 42, onClose() { closeCount += 1; } }, hooks);
    assert.match(renderToStaticMarkup(firstTree), /blob:working-first/);
    assert.match(renderToStaticMarkup(firstTree), /href="\/submission-tracking\/bms\/8\/current\?download=1"/);
    assert.match(renderToStaticMarkup(firstTree), /id="tracking-reader-tab-details"/);
    assert.match(renderToStaticMarkup(firstTree), /id="tracking-reader-tab-history"/);
    assert.match(renderToStaticMarkup(firstTree), />BMS-8-working\.png<\/h2>/, 'the filename appears once as visible reader text');

    const detailsTab = findElement(firstTree, element => element.props.id === 'tracking-reader-tab-details');
    detailsTab.props.onClick();
    const detailsTree = previewTree(Preview, { open: true, row: standardRow, accountKey: 42, onClose() { closeCount += 1; } }, hooks);
    assert.equal(findElement(detailsTree, element => element.props.id === 'tracking-reader-tabpanel-document').props.hidden, true);
    assert.match(renderToStaticMarkup(detailsTree), /BMS Final Return/);
    const expandButton = findElement(detailsTree, element => element.props['aria-label'] === 'Expand reader');
    expandButton.props.onClick();
    const expandedTree = previewTree(Preview, { open: true, row: standardRow, accountKey: 42, onClose() { closeCount += 1; } }, hooks);
    assert.match(findElement(expandedTree, element => element.type === 'section' && element.props.role === 'dialog').props.className, /h-\[100dvh\]/);
    assert.equal(globalThis.__sharedReaderCalls.length, 1, 'tabs and expand do not fetch the selected file again');

    const documentOnlyTree = previewTree(Preview, {
        open: true, row: standardRow, accountKey: 42, presentation: 'document-only', onClose() { closeCount += 1; },
    }, hooks);
    assert.equal(findElement(documentOnlyTree, element => element.props?.role === 'tablist'), null, 'document-only preview omits the one-item tab strip');
    assert.equal(findElement(documentOnlyTree, element => element.props?.id === 'tracking-reader-tabpanel-document')?.props.hidden, false, 'stale Details state still resolves to the visible document panel');
    assert.equal(findElement(documentOnlyTree, element => element.props?.id === 'tracking-reader-tabpanel-details'), null, 'metadata panels are omitted from the ordinary preview');
    assert.equal(findElement(documentOnlyTree, element => element.props?.id === 'tracking-reader-tabpanel-history'), null, 'history panels are omitted from the ordinary preview');
    assert.equal(globalThis.__sharedReaderCalls.length, 1, 'changing presentation does not refetch the selected file');

    const pamoRow = {
        source: 'conservation', source_id: 40, module: 'PAMO Five-Year Restoration Plan',
        protected_area: 'Aliwagwag Protected Landscape',
        current_document: imagePreview('/submission-tracking/conservation/40/working?preview=1', 'pamo-current.png', 'pamo-40'),
    };
    firstEffects.forEach(cleanup => cleanup());
    globalThis.__sharedReaderState = { status: 'ready', type: 'image/png', blob: new Blob(['pamo image']), url: 'blob:pamo-current' };
    hooks.clearEffects();
    const staleTree = previewTree(Preview, { open: true, row: pamoRow, accountKey: 42, presentation: 'document-only', onClose() { closeCount += 1; } }, hooks);
    assert.equal(findElement(staleTree, element => element.type === 'img'), null, 'a previous blob is not displayed while a different source file loads');
    assert.match(renderToStaticMarkup(staleTree), /Loading document preview/);
    hooks.runEffects();
    const pamoTree = previewTree(Preview, { open: true, row: pamoRow, accountKey: 42, presentation: 'document-only', onClose() { closeCount += 1; } }, hooks);
    assert.deepEqual(globalThis.__sharedReaderCalls, [
        '/submission-tracking/bms/8/current?preview=1',
        '/submission-tracking/conservation/40/working?preview=1',
    ]);
    assert.match(renderToStaticMarkup(pamoTree), /blob:pamo-current/);
    assert.match(renderToStaticMarkup(pamoTree), /href="\/submission-tracking\/conservation\/40\/working\?download=1"/);
    assert.doesNotMatch(renderToStaticMarkup(pamoTree), /tracking-reader-tab-history|tracking-reader-tab-details/, 'ordinary file previews hide metadata and history even when the row has context');
    findElement(pamoTree, element => element.props['aria-label'] === 'Close reader').props.onClick();
    assert.equal(closeCount, 1, 'Close returns through the originating queue callback');

    const unsupported = { ...pamoRow, source_id: 41, current_document: { ...pamoRow.current_document, id: 'unsupported-41', name: 'pamo-annex.docx', mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', preview_url: '/submission-tracking/conservation/41/annex?preview=1', download_url: '/submission-tracking/conservation/41/annex?download=1' } };
    hooks.clearEffects();
    const unsupportedTree = previewTree(Preview, { open: true, row: unsupported, accountKey: 42, onClose() {} }, hooks);
    assert.match(renderToStaticMarkup(unsupportedTree), /This document type cannot be previewed in the browser\./);
    assert.match(renderToStaticMarkup(unsupportedTree), /href="\/submission-tracking\/conservation\/41\/annex\?download=1"/);
    assert.equal(globalThis.__sharedReaderCalls.length, 2, 'unsupported files retain download behavior without being sent through the PDF/image renderer');

    const denied = { ...pamoRow, current_document: { ...pamoRow.current_document, can_preview: false } };
    hooks.clearEffects();
    const deniedTree = previewTree(Preview, { open: true, row: denied, accountKey: 42, onClose() {} }, hooks);
    assert.match(renderToStaticMarkup(deniedTree), /Your current account is not authorized to access this document\./);
    assert.doesNotMatch(renderToStaticMarkup(deniedTree), /href="\/submission-tracking\/conservation\/40\/working\?download=1"/);
    assert.equal(globalThis.__sharedReaderCalls.length, 2, 'a descriptor without its existing preview permission does not start a read');

    if (originalDocument === undefined) delete globalThis.document;
    else globalThis.document = originalDocument;
    delete globalThis.__sharedReaderCalls;
    delete globalThis.__sharedReaderDisposals;
    delete globalThis.__sharedReaderState;
});

test('routing-history attachment preview adapter keeps its exact descriptor and existing independent download permission', async () => {
    const hooks = stateHooks();
    const RoutingAttachmentLink = await loadComponent('RoutingAttachmentLink', hooks.runtime);
    const attachment = {
        id: 991,
        source: 'Routing attachment',
        name: 'correction-reference.png',
        mime_type: 'image/png',
        size: 2048,
        preview_url: '/submission-tracking/bms/8/routing-attachments/991?preview=1',
        download_url: '/submission-tracking/bms/8/routing-attachments/991',
    };
    const row = { source: 'bms', source_id: 8, module: 'BMS Final Return' };
    const props = { attachment, row, contextLabel: 'Returned for Correction' };
    hooks.beginRender();
    let tree = RoutingAttachmentLink(props);
    const previewButton = findElement(tree, element => element.type === 'button' && element.props.children === 'Preview');
    assert.ok(previewButton);
    let stopped = false;
    previewButton.props.onClick({ stopPropagation() { stopped = true; } });
    assert.equal(stopped, true);

    hooks.beginRender();
    tree = RoutingAttachmentLink(props);
    const reader = findElement(tree, element => element.props.attachment === attachment);
    assert.ok(reader);
    assert.equal(reader.props.open, true);
    assert.equal(reader.props.row, row);
    assert.equal(reader.props.previewAllowed, true, 'the existing preview URL presence remains the source permission');
    assert.equal(reader.props.downloadAllowed, true, 'the existing download URL remains independently bound');
    assert.equal(reader.props.presentation, 'document-only', 'routing and correction attachment previews use document-only presentation');
    assert.equal(reader.props.contextLabel, 'BMS Final Return · Returned for Correction');

    const denied = { ...attachment, preview_url: undefined, download_url: undefined };
    hooks.beginRender();
    tree = RoutingAttachmentLink({ ...props, attachment: denied });
    assert.equal(findElement(tree, element => element.type === 'button' && element.props.children === 'Preview'), null);
});

test('newly selected routing and correction files use the shared reader while retaining their local File URL lifecycle', async () => {
    const hooks = stateHooks();
    const Field = await loadComponent('RoutingAttachmentField', hooks.runtime);
    const originalDocument = globalThis.document;
    const originalFile = globalThis.File;
    const originalCreateObjectURL = URL.createObjectURL;
    const originalRevokeObjectURL = URL.revokeObjectURL;
    globalThis.document = { body: {} };
    globalThis.File = class FixtureFile { constructor(name, type) { this.name = name; this.type = type; } };
    const createdUrls = [];
    const revokedUrls = [];
    URL.createObjectURL = file => {
        const url = `blob:selected-${createdUrls.length + 1}`;
        createdUrls.push({ file, url });
        return url;
    };
    URL.revokeObjectURL = url => revokedUrls.push(url);

    const firstFile = new globalThis.File('BMS-8-correction.pdf', 'application/pdf');
    const baseProps = { file: firstFile, onChange() {}, correctionAttachment: true };
    hooks.beginRender();
    Field(baseProps);
    const firstFileCleanups = hooks.runEffects();

    hooks.beginRender();
    let tree = Field(baseProps);
    hooks.clearEffects();
    const previewButton = findElement(tree, element => element.type?.name === 'Button' && element.props.children === 'Preview New Upload');
    assert.ok(previewButton, 'the existing local preview action remains available');
    previewButton.props.onClick();

    hooks.beginRender();
    tree = Field(baseProps);
    hooks.clearEffects();
    const reader = findElement(tree, element => element.type?.name === 'SubmissionTrackingReaderDialog');
    assert.ok(reader);
    assert.equal(reader.props.open, true);
    assert.equal(reader.props.title, 'BMS-8-correction.pdf');
    assert.equal(reader.props.context, 'New upload preview');
    assert.deepEqual(reader.props.tabs, [{ value: 'document', label: 'Document' }]);
    const pdf = findElement(reader.props.panels.document, element => element.type?.displayName === 'Lazy' || element.props?.blob === firstFile);
    assert.ok(pdf);
    assert.equal(pdf.props.blob, firstFile, 'the selected local File is passed directly to the approved PDF reader');
    assert.equal(pdf.props.archiveReader, true);
    assert.equal(pdf.props.initialFitMode, 'page');
    assert.equal(createdUrls.length, 1);

    reader.props.onClose();
    hooks.beginRender();
    tree = Field(baseProps);
    hooks.clearEffects();
    assert.equal(findElement(tree, element => element.type?.name === 'SubmissionTrackingReaderDialog').props.open, false);
    assert.equal(createdUrls.length, 1, 'closing does not recreate the selected upload URL');

    const reopenButton = findElement(tree, element => element.type?.name === 'Button' && element.props.children === 'Preview New Upload');
    reopenButton.props.onClick();
    hooks.beginRender();
    tree = Field(baseProps);
    hooks.clearEffects();
    assert.equal(findElement(tree, element => element.type?.name === 'SubmissionTrackingReaderDialog').props.open, true);

    const secondFile = new globalThis.File('BMS-8-correction-revised.png', 'image/png');
    firstFileCleanups.forEach(cleanup => cleanup());
    hooks.beginRender();
    tree = Field({ ...baseProps, file: secondFile });
    assert.equal(findElement(tree, element => element.type?.name === 'SubmissionTrackingReaderDialog').props.open, false, 'selecting a replacement closes the prior file immediately');
    assert.equal(findElement(tree, element => element.props?.blob === firstFile), null, 'the prior PDF is not retained during the close transition');
    const secondFileCleanups = hooks.runEffects();
    hooks.beginRender();
    tree = Field({ ...baseProps, file: secondFile });
    hooks.clearEffects();
    const secondReader = findElement(tree, element => element.type?.name === 'SubmissionTrackingReaderDialog');
    assert.equal(secondReader.props.open, false, 'changing the selected file closes the previous reader');
    assert.equal(createdUrls.length, 2);
    assert.equal(createdUrls[1].file, secondFile);
    assert.equal(revokedUrls[0], createdUrls[0].url, 'replacing the file revokes the previous object URL');
    assert.match(renderToStaticMarkup(tree), /Preview New Upload/);
    const imagePreviewButton = findElement(tree, element => element.type?.name === 'Button' && element.props.children === 'Preview New Upload');
    imagePreviewButton.props.onClick();
    hooks.beginRender();
    tree = Field({ ...baseProps, file: secondFile });
    assert.equal(findElement(tree, element => element.type?.name === 'SubmissionTrackingReaderDialog').props.open, true);
    assert.match(renderToStaticMarkup(tree), /src="blob:selected-2"/, 'supported local images render inside the same shared reader');
    secondFileCleanups.forEach(cleanup => cleanup());

    if (originalDocument === undefined) delete globalThis.document;
    else globalThis.document = originalDocument;
    if (originalFile === undefined) delete globalThis.File;
    else globalThis.File = originalFile;
    URL.createObjectURL = originalCreateObjectURL;
    URL.revokeObjectURL = originalRevokeObjectURL;
});
