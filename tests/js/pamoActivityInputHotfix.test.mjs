import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

const reactRequire = createRequire(resolve('package.json'));
const reactPassthroughExports = Object.keys(reactRequire('react'))
    .filter(name => name !== 'default' && !['useState', 'useRef', 'useEffect', 'useImperativeHandle'].includes(name) && /^[A-Za-z_$][\w$]*$/.test(name));

function workspaceTestShims() {
    const workspacePath = '/Components/PamoPambDraftWorkspace.jsx';
    const crudModalPath = '/Components/Crud/CrudFormModal.jsx';
    return {
        name: 'pamo-activity-workspace-test-shims',
        enforce: 'pre',
        resolveId(source, importer = '') {
            const normalizedImporter = importer.replaceAll('\\', '/');
            const isWorkspace = normalizedImporter.includes(workspacePath);
            const isCrudModal = normalizedImporter.includes(crudModalPath);
            // Vite's SSR runner may externalize or canonicalize React importers
            // before this plugin sees their source path. Keep React hooks in
            // this test-owned module graph on one isolated harness instance.
            if (source === 'react') return '\0pamo-activity-test-hooks';
            if (source === '@inertiajs/react') return '\0pamo-activity-test-inertia';
            if (!isWorkspace && !isCrudModal) return null;
            if (isCrudModal && source.includes('Attachments/useModalFileDrop')) return '\0pamo-activity-test-file-drop';
            if (isCrudModal && source.includes('ModalMotion/useModalMotion')) return '\0pamo-activity-test-modal-motion';
            return null;
        },
        load(id) {
            if (id === '\0pamo-activity-test-hooks') return `
                import { createRequire } from 'node:module';
                const require = createRequire(${JSON.stringify(resolve('package.json'))});
                const React = require('react');
                export default React;
                ${reactPassthroughExports.map(name => `export const ${name} = React[${JSON.stringify(name)}];`).join('\n')}
                export const useState = (...args) => globalThis.__pamoActivityHarness.useState(...args);
                export const useRef = (...args) => globalThis.__pamoActivityHarness.useRef(...args);
                export const useEffect = (...args) => globalThis.__pamoActivityHarness.useEffect(...args);
                export const useImperativeHandle = (...args) => globalThis.__pamoActivityHarness.useImperativeHandle(...args);
            `;
            if (id === '\0pamo-activity-test-inertia') return `
                export const router = { post(...args) { globalThis.__pamoActivityHarness.routerCalls.push(args); } };
                export function useForm(...args) { return globalThis.__pamoActivityHarness.useForm(...args); }
            `;
            if (id === '\0pamo-activity-test-file-drop') return `export default function useModalFileDrop() { return null; }`;
            if (id === '\0pamo-activity-test-modal-motion') return `export function useModalMotion(open) { return { present: open, phase: open ? 'entered' : 'exited', onTransitionEnd() {} }; }`;
            return null;
        },
    };
}

function makeHarness() {
    const stateSlots = [];
    const refSlots = [];
    const forms = [];
    const requests = [];
    const routerCalls = [];
    let stateCursor = 0;
    let refCursor = 0;
    let formCursor = 0;
    let imperativeInitializers = [];
    let effectCallbacks = [];

    const harness = {
        forms,
        requests,
        routerCalls,
        get effectCallbacks() { return effectCallbacks; },
        beginRender() {
            stateCursor = 0;
            refCursor = 0;
            formCursor = 0;
            imperativeInitializers = [];
            effectCallbacks = [];
        },
        finishRender() {
            imperativeInitializers.forEach(initialize => initialize());
        },
        useState(initial) {
            const index = stateCursor++;
            if (!(index in stateSlots)) stateSlots[index] = typeof initial === 'function' ? initial() : initial;
            return [stateSlots[index], next => {
                stateSlots[index] = typeof next === 'function' ? next(stateSlots[index]) : next;
            }];
        },
        useRef(initial) {
            const index = refCursor++;
            if (!(index in refSlots)) refSlots[index] = { current: initial };
            return refSlots[index];
        },
        useEffect(callback) { effectCallbacks.push(callback); },
        useImperativeHandle(ref, create) {
            if (ref) imperativeInitializers.push(() => { ref.current = create(); });
        },
        useForm(initial) {
            const index = formCursor++;
            if (forms[index]) return forms[index];
            const form = {
                data: { ...initial },
                errors: {},
                processing: false,
                progress: null,
                transformedData: null,
                setData(key, value) {
                    if (arguments.length === 1) Object.assign(this.data, key);
                    else this.data[key] = value;
                },
                clearErrors(...fields) {
                    if (!fields.length) this.errors = {};
                    else fields.forEach(field => { delete this.errors[field]; });
                },
                reset() { this.data = { ...initial }; this.errors = {}; },
                transform(callback) { this.transformedData = callback(this.data); return this; },
                setError(name, message) { this.errors[name] = message; },
                post(url, options = {}) {
                    this.processing = true;
                    const request = { url, payload: this.transformedData, options, form: this };
                    requests.push(request);
                },
            };
            forms[index] = form;
            return form;
        },
    };
    return harness;
}

function returnError(request, errors) {
    request.form.processing = false;
    request.form.errors = { ...errors };
    request.options.onError?.(errors);
    request.options.onFinish?.();
}

function returnSuccess(request, page = { props: { flash: { success: 'Draft saved.' } } }) {
    request.form.processing = false;
    request.form.errors = {};
    request.options.onSuccess?.(page);
    request.options.onFinish?.();
}

function findElement(root, predicate) {
    if (Array.isArray(root)) {
        for (const child of root) {
            const found = findElement(child, predicate);
            if (found) return found;
        }
        return null;
    }
    if (!React.isValidElement(root)) return null;
    if (predicate(root)) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
    return null;
}

function elementTypes(root, types = []) {
    if (Array.isArray(root)) {
        root.forEach(child => elementTypes(child, types));
        return types;
    }
    if (!React.isValidElement(root)) return types;
    types.push(root.type?.name || (typeof root.type === 'string' ? root.type : String(root.type)));
    for (const child of React.Children.toArray(root.props.children)) elementTypes(child, types);
    return types;
}

function renderWorkspace(Workspace, props, ref, harness) {
    harness.beginRender();
    let tree = null;
    function WorkspaceProbe() {
        tree = Workspace.render(props, ref);
        return null;
    }
    renderToStaticMarkup(React.createElement(WorkspaceProbe));
    harness.finishRender();
    return tree;
}

test('the actual New draft modal keeps free-text activity scalar through edits, resets, hydration, and draft save', async () => {
    const harness = makeHarness();
    const priorHarness = globalThis.__pamoActivityHarness;
    const priorRoute = globalThis.route;
    globalThis.__pamoActivityHarness = harness;
    globalThis.route = (...parts) => parts.flat().map(part => String(part)).join('/');

    const server = await createServer({
        configFile: false,
        plugins: [workspaceTestShims(), viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true, hmr: false, port: 0, strictPort: true },
        ssr: { noExternal: ['react', '@inertiajs/react'] },
        appType: 'custom',
        logLevel: 'error',
    });

    try {
        const [{ default: Workspace }, { default: GenericFields }, { default: CrudFormModal }] = await Promise.all([
            server.ssrLoadModule('/resources/js/Components/PamoPambDraftWorkspace.jsx'),
            server.ssrLoadModule('/resources/js/Components/PamoGenericSubmissionFields.jsx'),
            server.ssrLoadModule('/resources/js/Components/Crud/CrudFormModal.jsx'),
        ]);
        const workflow = {
            key: 'homestay', label: 'Homestay', source: 'conservation',
            activities: ['Training on Homestay Program'],
            documents: ['Progress Report', 'Final Report'],
            periods: ['Quarter 1', 'Quarter 2', 'Quarter 3'],
            default_activity: 'Training on Homestay Program',
            period_field: 'reporting_period', period_label: 'Reporting Period',
        };
        const ref = { current: null };
        const props = {
            workflow, workflows: [workflow], drafts: [],
            assignedProtectedArea: { id: 7, name: 'Baganga Mangrove Swamp Forest Reserve' },
            pamoContext: { first_recipient: 'CENRO Baganga CDS Focal Person' },
        };
        const renderWorkspaceTree = () => renderWorkspace(Workspace, props, ref, harness);
        const modalFrom = tree => findElement(tree, element => element.props.title === 'New draft report' && typeof element.props.onSubmit === 'function');
        const activityInputFrom = tree => {
            const modal = modalFrom(tree);
            assert.ok(modal, 'the workspace renders its actual New draft report modal');
            const genericFields = findElement(modal.props.children, element => element.type?.name === 'PamoGenericSubmissionFields');
            assert.ok(genericFields, `the modal includes the actual generic PAMO field adapter (${elementTypes(modal.props.children).join(', ')})`);
            const fieldTree = GenericFields(genericFields.props);
            return findElement(fieldTree, element => element.props.id === 'pamo-draft-activity');
        };

        let tree = renderWorkspaceTree();
        ref.current.startNew();
        tree = renderWorkspaceTree();
        let activityInput = activityInputFrom(tree);
        assert.ok(activityInput);
        assert.equal(activityInput.props.value, '', 'a fresh draft starts with a blank editable activity');
        assert.match(renderToStaticMarkup(modalFrom(tree)), /New draft report/);

        const form = harness.forms[0];
        const selectedFile = { name: 'field-notes.pdf', type: 'application/pdf' };
        form.setData({ document_type: 'Progress Report', reporting_period: 'Quarter 3', date_accomplished: '2026-10-18', remarks: 'Keep this note', mov: selectedFile });
        activityInput.props.onChange({ target: { value: 'Baganga Mangrove Swamp Forest Reserve patrol' } });
        assert.equal(form.data.activity_name, 'Baganga Mangrove Swamp Forest Reserve patrol');

        tree = renderWorkspaceTree();
        activityInput = activityInputFrom(tree);
        assert.equal(activityInput.props.value, 'Baganga Mangrove Swamp Forest Reserve patrol');
        activityInput.props.onChange({ target: { value: '' } });
        assert.equal(form.data.activity_name, '', 'clearing the text stores an empty string');
        tree = renderWorkspaceTree();
        assert.equal(activityInputFrom(tree).props.value, '', 'rerendering does not restore the cleared value');

        activityInputFrom(tree).props.onChange({ target: { value: 'Replaced mangrove site monitoring' } });
        tree = renderWorkspaceTree();
        const saveModal = modalFrom(tree);
        saveModal.props.onSubmit({ preventDefault() {} });
        const textSave = harness.requests.at(-1);
        assert.equal(textSave.url, 'conservation-reports.pamo-drafts.store/homestay');
        assert.equal(textSave.options.forceFormData, true);
        assert.equal(textSave.options.preserveScroll, true);
        assert.equal(textSave.payload.activity_name, 'Replaced mangrove site monitoring');
        assert.equal(textSave.payload.document_type, 'Progress Report');
        assert.equal(textSave.payload.reporting_period, 'Quarter 3');
        assert.equal(textSave.payload.remarks, 'Keep this note');
        assert.equal(textSave.payload.mov, selectedFile);
        assert.equal(typeof textSave.payload.activity_name, 'string');
        assert.equal(typeof textSave.payload.start_idempotency_key, 'string');
        assert.equal(form.processing, true, 'the submit remains pending until an Inertia response is delivered');
        modalFrom(renderWorkspaceTree()).props.onSubmit({ preventDefault() {} });
        assert.equal(harness.requests.length, 1, 'a second submit is blocked while the first request is pending');

        returnError(textSave, { date_accomplished: 'Date Accomplished cannot be in the future.' });
        tree = renderWorkspaceTree();
        const failedModal = modalFrom(tree);
        assert.equal(failedModal.props.open, true, 'a validation response leaves the draft modal open');
        assert.equal(failedModal.props.processing, false);
        assert.deepEqual(form.data, {
            activity_name: 'Replaced mangrove site monitoring', document_type: 'Progress Report',
            reporting_period: 'Quarter 3', semester: '', quarter: '', reporting_month: '', reporting_year: '',
            total_collected: '', deadline_submission: '', monitoring_period_start: '', monitoring_period_end: '',
            date_conducted: '', date_conducted_ranges: [], date_accomplished: '2026-10-18', remarks: 'Keep this note',
            mov: selectedFile, lock_version: 1,
        }, 'server errors preserve the entered activity, selected document/period, remarks, and file');
        assert.equal(failedModal.props.errors.date_accomplished, 'Date Accomplished cannot be in the future.');

        const priorWindow = globalThis.window;
        const animationFrames = [];
        const focusEvents = [];
        globalThis.window = {
            requestAnimationFrame(callback) { animationFrames.push(callback); return animationFrames.length; },
            cancelAnimationFrame() {},
        };
        try {
            harness.beginRender();
            const actualModalTree = CrudFormModal(failedModal.props);
            const errorSummary = findElement(actualModalTree, element => element.props.role === 'alert' && element.props.tabIndex === -1);
            assert.ok(errorSummary, 'the actual modal renders its accessible error summary');
            const summaryRef = errorSummary.props.ref || errorSummary.ref;
            assert.ok(summaryRef);
            summaryRef.current = {
                scrollIntoView(options) { focusEvents.push(['scroll', options]); },
                focus(options) { focusEvents.push(['focus', options]); },
            };
            harness.effectCallbacks.at(-1)();
            animationFrames.forEach(callback => callback());
            assert.deepEqual(focusEvents, [
                ['scroll', { block: 'nearest' }],
                ['focus', { preventScroll: true }],
            ], 'a failed save scrolls the modal error summary into view and focuses it');
        } finally {
            if (priorWindow === undefined) delete globalThis.window;
            else globalThis.window = priorWindow;
        }

        form.setData('date_accomplished', '2026-10-14');
        modalFrom(tree).props.onSubmit({ preventDefault() {} });
        const retry = harness.requests.at(-1);
        assert.equal(harness.requests.length, 2, 'the user can retry after the server response');
        assert.equal(retry.payload.activity_name, form.data.activity_name);
        assert.equal(retry.payload.document_type, form.data.document_type);
        assert.equal(retry.payload.reporting_period, form.data.reporting_period);
        assert.equal(retry.payload.date_accomplished, '2026-10-14');
        assert.equal(retry.payload.remarks, form.data.remarks);
        assert.equal(retry.payload.mov, selectedFile);
        returnSuccess(retry);
        tree = renderWorkspaceTree();
        assert.equal(modalFrom(tree).props.open, false, 'a successful retry closes the modal');

        ref.current.startNew();
        tree = renderWorkspaceTree();
        assert.equal(activityInputFrom(tree).props.value, '', 'opening another fresh draft resets its activity to empty');

        ref.current.openDraft({
            id: 'draft-existing', workflow_key: 'homestay', status: 'draft', lock_version: 3,
            started_at: '2026-10-15T08:00:00.000Z',
            report_payload: {
                activity_name: 'Preserved saved Homestay activity', document_type: 'Final Report',
                reporting_period: 'Quarter 2', remarks: 'Existing note',
            },
            mov_url: '/protected/saved-copy.pdf', mov_file_name: 'saved-copy.pdf',
        });
        tree = renderWorkspaceTree();
        assert.equal(activityInputFrom(tree).props.value, 'Preserved saved Homestay activity');
        activityInputFrom(tree).props.onChange({ target: { value: '' } });
        tree = renderWorkspaceTree();
        modalFrom(tree).props.onSubmit({ preventDefault() {} });
        const emptySave = harness.requests.at(-1);
        assert.equal(emptySave.payload.activity_name, '', 'a partially prepared existing draft can save a cleared activity');
        assert.equal(emptySave.payload.document_type, 'Final Report');
        assert.equal(emptySave.payload.reporting_period, 'Quarter 2');
        assert.equal(emptySave.payload.remarks, 'Existing note');
        assert.equal(typeof emptySave.payload.activity_name, 'string');
        assert.doesNotMatch(renderToStaticMarkup(modalFrom(tree)), /pamo-draft-activity[^>]*required/);
    } finally {
        await server.close();
        if (priorHarness === undefined) delete globalThis.__pamoActivityHarness;
        else globalThis.__pamoActivityHarness = priorHarness;
        if (priorRoute === undefined) delete globalThis.route;
        else globalThis.route = priorRoute;
    }
});

test('the actual Submit report modal keeps a free-text restoration activity through edits, errors, and promotion payloads', async () => {
    const harness = makeHarness();
    const priorHarness = globalThis.__pamoActivityHarness;
    const priorRoute = globalThis.route;
    globalThis.__pamoActivityHarness = harness;
        globalThis.route = (...parts) => parts.flat().map(part => String(part)).join('/');

    const server = await createServer({
        configFile: false,
        plugins: [workspaceTestShims(), viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true, hmr: false, port: 0, strictPort: true },
        ssr: { noExternal: ['react', '@inertiajs/react'] },
        appType: 'custom',
        logLevel: 'error',
    });

    try {
        const [{ default: Workspace }, { default: GenericFields }, { default: CrudFormModal }] = await Promise.all([
            server.ssrLoadModule('/resources/js/Components/PamoPambDraftWorkspace.jsx'),
            server.ssrLoadModule('/resources/js/Components/PamoGenericSubmissionFields.jsx'),
            server.ssrLoadModule('/resources/js/Components/Crud/CrudFormModal.jsx'),
        ]);
        const workflow = {
            key: 'restoration_plan_5_year', label: '5 Year Restoration Plan Preparation', source: 'conservation',
            activities: ['Preparation of 5-Year Restoration Plan'],
            documents: ['Progress Report', 'Final Report'],
            activity_documents: { 'Preparation of 5-Year Restoration Plan': ['Progress Report', 'Final Report'] },
            periods: ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
            default_activity: 'Preparation of 5-Year Restoration Plan', period_field: 'reporting_period',
            date_conducted_required: true, date_accomplished_required: true,
        };
        const savedDraft = {
            id: 'draft-restoration', workflow_key: workflow.key, status: 'draft', lock_version: 4,
            started_at: '2026-10-16T00:00:00.000Z',
            report_payload: {
                activity_name: 'Restoration Plan Workshop', document_type: 'Progress Report',
                reporting_period: 'Quarter 3', date_conducted: '2026-09-14', date_accomplished: '2026-09-14',
                remarks: 'Keep this saved note.',
            },
            mov_url: '/protected/restoration-draft.pdf', mov_file_name: 'restoration-draft.pdf',
        };
        const ref = { current: null };
        const props = {
            workflow, workflows: [workflow], drafts: [savedDraft],
            assignedProtectedArea: { id: 7, name: 'Baganga Mangrove Swamp Forest Reserve' },
            pamoContext: { first_recipient: 'CENRO Baganga CDS Focal Person' },
        };
        const renderWorkspaceTree = () => renderWorkspace(Workspace, props, ref, harness);
        const modalFrom = tree => findElement(tree, element => element.props.title === 'Submit report' && typeof element.props.onSubmit === 'function');
        const activityFieldFrom = tree => {
            const modal = modalFrom(tree);
            assert.ok(modal, 'the workspace renders the actual Submit report modal');
            const genericFields = findElement(modal.props.children, element => element.type?.name === 'PamoGenericSubmissionFields');
            assert.ok(genericFields, 'the modal includes the actual generic PAMO field adapter');
            return findElement(GenericFields(genericFields.props), element => element.props.id === 'pamo-draft-activity');
        };

        let tree = renderWorkspaceTree();
        ref.current.submitDraft(savedDraft);
        tree = renderWorkspaceTree();
        const staleActivityField = activityFieldFrom(tree);
        assert.equal(staleActivityField.type.name, 'FloatingInput', 'the activity title stays an editable text input for submission');
        assert.equal(staleActivityField.props.value, 'Restoration Plan Workshop', 'opening submit preserves the saved value in the form');
        assert.equal(staleActivityField.props.label, 'Name of Activity');
        assert.equal(staleActivityField.props.required, true);
        const modalBeforeFailure = modalFrom(tree);
        const savedAttachment = findElement(modalBeforeFailure.props.children, element => element.type?.name === 'FileAttachmentPanel');
        assert.equal(savedAttachment.props.existingFiles[0].name, 'restoration-draft.pdf');
        assert.equal(harness.forms[0].data.activity_name, 'Restoration Plan Workshop', 'opening submit does not silently replace saved draft text');

        staleActivityField.props.onChange({ target: { value: '' } });
        assert.equal(harness.forms[0].data.activity_name, '', 'the user can erase the title');
        assert.equal(harness.forms[0].data.document_type, 'Progress Report', 'erasing the title keeps the document selection');
        assert.equal(harness.forms[0].data.reporting_period, 'Quarter 3');
        assert.equal(harness.forms[0].data.date_conducted, '2026-09-14');
        assert.equal(harness.forms[0].data.date_accomplished, '2026-09-14');
        assert.equal(harness.forms[0].data.remarks, 'Keep this saved note.');
        tree = renderWorkspaceTree();
        assert.equal(activityFieldFrom(tree).props.value, '', 'clearing the text does not render an object value');
        const freeTitle = 'Restoration Plan Workshop – Davao Oriental';
        activityFieldFrom(tree).props.onChange({ target: { value: freeTitle } });
        assert.equal(harness.forms[0].data.activity_name, freeTitle, 'typing writes the exact user string, including Unicode');
        assert.equal(harness.forms[0].data.document_type, 'Progress Report', 'editing a title never clears the document selection');

        tree = renderWorkspaceTree();
        const submitModal = modalFrom(tree);
        submitModal.props.onSubmit({ preventDefault() {} });
        const rejected = harness.requests.at(-1);
        assert.equal(rejected.url, 'conservation-reports.pamo-drafts.forward/restoration_plan_5_year/draft-restoration');
        assert.equal(rejected.payload.activity_name, freeTitle, 'the mocked submit carries the exact scalar title');
        assert.equal(typeof rejected.payload.activity_name, 'string');
        assert.equal(rejected.payload.document_type, 'Progress Report');
        assert.equal(rejected.payload.reporting_period, 'Quarter 3');
        assert.equal(rejected.payload.date_conducted, '2026-09-14');
        assert.equal(rejected.payload.date_accomplished, '2026-09-14');
        assert.equal(rejected.payload.remarks, 'Keep this saved note.');
        assert.equal(rejected.payload.mov, null, 'the saved attachment is not replaced by an empty upload');
        returnError(rejected, { activity_name: 'The activity title could not be submitted.' });
        tree = renderWorkspaceTree();
        let failedModal = modalFrom(tree);
        assert.equal(failedModal.props.open, true, 'a server validation error leaves the modal open');
        assert.equal(harness.forms[0].data.activity_name, freeTitle, 'the server error preserves the title');
        assert.equal(harness.forms[0].data.document_type, 'Progress Report');
        assert.equal(findElement(failedModal.props.children, element => element.type?.name === 'FileAttachmentPanel').props.existingFiles[0].name, 'restoration-draft.pdf');
        const failedHtml = renderToStaticMarkup(React.createElement(CrudFormModal, failedModal.props));
        assert.match(failedHtml, /The activity title could not be submitted\./);
        assert.match(failedHtml, /role="alert"/);
        assert.match(failedHtml, /tabindex="-1"/);
        assert.match(failedHtml, /Please correct the following:/);
        assert.match(failedHtml, /Restoration Plan Workshop – Davao Oriental/);

        activityFieldFrom(tree).props.onChange({ target: { value: 'Restoration Plan Workshop' } });
        assert.equal(harness.forms[0].data.document_type, 'Progress Report', 'replacing the title still preserves the selected document');
        tree = renderWorkspaceTree();
        failedModal = modalFrom(tree);
        failedModal.props.onSubmit({ preventDefault() {} });
        const retry = harness.requests.at(-1);
        assert.equal(retry.payload.activity_name, 'Restoration Plan Workshop');
        assert.equal(retry.payload.document_type, 'Progress Report');
        assert.equal(retry.payload.reporting_period, 'Quarter 3');
        assert.equal(retry.payload.date_conducted, '2026-09-14');
        assert.equal(retry.payload.date_accomplished, '2026-09-14');
        assert.equal(retry.payload.remarks, 'Keep this saved note.');
        returnSuccess(retry, { props: { flash: { success: 'Report forwarded successfully.' } } });
        tree = renderWorkspaceTree();
        assert.equal(modalFrom(tree), null, 'a successful mocked promotion closes the modal');
    } finally {
        await server.close();
        if (priorHarness === undefined) delete globalThis.__pamoActivityHarness;
        else globalThis.__pamoActivityHarness = priorHarness;
        if (priorRoute === undefined) delete globalThis.route;
        else globalThis.route = priorRoute;
    }
});

test('the actual native PAMO report modal keeps its Name of Activity field scalar and editable', async () => {
    const harness = makeHarness();
    const priorHarness = globalThis.__pamoActivityHarness;
    const priorRoute = globalThis.route;
    globalThis.__pamoActivityHarness = harness;
    globalThis.route = (...parts) => parts.flat().map(part => String(part)).join('/');

    const server = await createServer({
        configFile: false,
        plugins: [workspaceTestShims(), viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true, hmr: false, port: 0, strictPort: true },
        ssr: { noExternal: ['react', '@inertiajs/react'] },
        appType: 'custom',
        logLevel: 'error',
    });

    try {
        const [{ default: Workspace }, { default: CrudFormModal }] = await Promise.all([
            server.ssrLoadModule('/resources/js/Components/PamoPambDraftWorkspace.jsx'),
            server.ssrLoadModule('/resources/js/Components/Crud/CrudFormModal.jsx'),
        ]);
        const workflow = {
            key: 'bms', label: 'BMS', source: 'bms', form: 'standard_bms',
            documents: ['Progress Report', 'Final Report'], periods: ['1st Semester', '2nd Semester'],
            period_field: 'semester', date_conducted_required: true,
        };
        const savedDraft = {
            id: 'draft-bms-native', workflow_key: workflow.key, status: 'draft', lock_version: 2,
            report_payload: { activity_name: 'Existing field activity', document_type: 'Progress Report', semester: '1st Semester' },
        };
        const ref = { current: null };
        const props = { workflow, workflows: [workflow], drafts: [savedDraft], assignedProtectedArea: { id: 7, name: 'Baganga Mangrove Swamp Forest Reserve' } };
        const renderWorkspaceTree = () => renderWorkspace(Workspace, props, ref, harness);
        const modalFrom = tree => findElement(tree, element => element.type?.name === 'CrudFormModal');
        const activityFieldFrom = tree => findElement(modalFrom(tree).props.children, element => element.props.id === 'pamo-native-activity');

        renderWorkspaceTree();
        ref.current.submitDraft(savedDraft);
        let tree = renderWorkspaceTree();
        const activityField = activityFieldFrom(tree);
        assert.equal(activityField.type.name, 'FloatingInput');
        assert.equal(activityField.props.label, 'Name of Activity');
        assert.equal(activityField.props.value, 'Existing field activity');
        assert.equal(activityField.props.required, true);
        activityField.props.onChange({ target: { value: 'PAMB Information Workshop' } });
        assert.equal(harness.forms[0].data.activity_name, 'PAMB Information Workshop');
        assert.equal(typeof harness.forms[0].data.activity_name, 'string');
        tree = renderWorkspaceTree();
        const modal = modalFrom(tree);
        modal.props.onSubmit({ preventDefault() {} });
        assert.equal(harness.requests.at(-1).payload.activity_name, 'PAMB Information Workshop');
        assert.equal(harness.requests.at(-1).payload.document_type, 'Progress Report');
        assert.equal(harness.requests.at(-1).payload.semester, '1st Semester');
        assert.doesNotMatch(renderToStaticMarkup(React.createElement(CrudFormModal, modal.props)), /\[object Object\]/);
    } finally {
        await server.close();
        if (priorHarness === undefined) delete globalThis.__pamoActivityHarness;
        else globalThis.__pamoActivityHarness = priorHarness;
        if (priorRoute === undefined) delete globalThis.route;
        else globalThis.route = priorRoute;
    }
});
