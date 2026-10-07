import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { readFile } from 'node:fs/promises';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';
import { timelinePresentation } from '../../resources/js/Utils/submissionTrackingPresentation.js';

let server;
const load = async (path) => {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule(`/resources/js/${path}`);
};

test.after(async () => { await server?.close(); });

test('Report Routing panel renders the saved context, all four profile previews, and invokes authorized callbacks', async () => {
    const { default: Panel } = await load('Components/Admin/RoutingPositionControlsPanel.jsx');
    const settings = { available: true, version: 7, office_penro_enabled: true, penro_tsd_chief_enabled: true, saved_at: null, saved_by: null, reason: null };
    const base = {
        settings,
        canUpdate: true,
        data: { office_penro_enabled: true, penro_tsd_chief_enabled: true, reason: '' },
        onToggle() {}, onReason() {}, onSave() {},
    };
    const markup = (office, tsd) => renderToStaticMarkup(React.createElement(Panel, {
        ...base,
        data: { ...base.data, office_penro_enabled: office, penro_tsd_chief_enabled: tsd },
    }));
    assert.match(markup(true, true), /PENRO Records → Office of the PENRO → TSD Chief → CDS Focal/);
    assert.match(markup(false, true), /PENRO Records → TSD Chief → CDS Focal/);
    assert.match(markup(true, false), /PENRO Records → Office of the PENRO → CDS Focal/);
    assert.match(markup(false, false), /PENRO Records → CDS Focal/);
    assert.match(markup(false, false), /CENRO-origin profile/);
    assert.match(markup(false, false), /Direct-to-PENRO profile/);
    assert.match(markup(false, false), /PENRO CDS Focal \u2192 PENRO Records \u2192 CDS Focal/);
    assert.match(markup(false, false), /This recommendation is not an Office approval/);
    assert.match(markup(true, true), /Settings revision 7/);
    assert.match(markup(true, true), /Reports with a captured route keep that route/);
    assert.match(markup(true, true), /Include Office of the PENRO in routing/);
    assert.match(markup(true, true), /Include PENRO TSD Chief in routing/);
    assert.match(markup(true, true), /Route for new reports/);

    const callbacks = [];
    const tree = Panel({
        ...base,
        onToggle: (...args) => callbacks.push(['toggle', ...args]),
        onSave: () => callbacks.push(['save']),
    });
    const visit = node => {
        if (!React.isValidElement(node)) return;
        if (typeof node.type === 'function') return visit(node.type(node.props));
        if (node.type === 'input' && node.props.id === 'office-penro-enabled') node.props.onChange({ target: { checked: false } });
        if (node.type === 'button') node.props.onClick();
        React.Children.forEach(node.props.children, visit);
    };
    visit(tree);
    assert.deepEqual(callbacks, [['toggle', 'office_penro_enabled', false], ['save']]);
});

test('disabled and submitting settings controls render disabled, while view-only users get no save callback', async () => {
    const { default: Panel } = await load('Components/Admin/RoutingPositionControlsPanel.jsx');
    const settings = { available: true, version: 2, office_penro_enabled: false, penro_tsd_chief_enabled: true };
    const data = { office_penro_enabled: false, penro_tsd_chief_enabled: true, reason: '' };
    const submitting = renderToStaticMarkup(React.createElement(Panel, { settings, data, canUpdate: true, processing: true, onToggle() {}, onReason() {}, onSave() {} }));
    assert.match(submitting, /disabled=""[^>]*>Saving…/);
    const viewOnly = renderToStaticMarkup(React.createElement(Panel, { settings, data, canUpdate: false }));
    assert.match(viewOnly, /You have view access/);
    assert.doesNotMatch(viewOnly, /Save routing settings/);
});

test('RoutingWorkflow page keeps the acknowledged version across consecutive successful saves and preserves edits on validation errors', async () => {
    const { default: RoutingWorkflow } = await load('Pages/Admin/Settings/RoutingWorkflow.jsx');
    const inertia = await import('@inertiajs/react');
    const originalPut = inertia.router.put;
    const internals = React.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;
    const originalDispatcher = internals.H;
    const originalWindow = globalThis.window;
    const slots = [];
    const visits = [];
    let hookIndex = 0;
    let pendingEffects = [];

    const nextSlot = (initialize) => {
        const index = hookIndex++;
        if (!(index in slots)) slots[index] = initialize();
        return [index, slots[index]];
    };
    const dispatcher = {
        useState(initial) {
            const [index, value] = nextSlot(() => typeof initial === 'function' ? initial() : initial);
            return [value, update => {
                slots[index] = typeof update === 'function' ? update(slots[index]) : update;
            }];
        },
        useReducer(reducer, initial, initialize) {
            const [index, value] = nextSlot(() => initialize ? initialize(initial) : initial);
            return [value, update => { slots[index] = reducer(slots[index], update); }];
        },
        useRef(initial) {
            const [, value] = nextSlot(() => ({ current: initial }));
            return value;
        },
        useCallback(callback) { nextSlot(() => callback); return callback; },
        useMemo(factory) { nextSlot(() => null); return factory(); },
        useEffect(callback) { nextSlot(() => null); pendingEffects.push(callback); },
        useLayoutEffect(callback) { nextSlot(() => null); pendingEffects.push(callback); },
        useInsertionEffect(callback) { nextSlot(() => null); pendingEffects.push(callback); },
        useContext(context) { nextSlot(() => null); return context?._currentValue ?? context?._defaultValue; },
        useDebugValue() { nextSlot(() => null); },
    };

    const render = (settings) => {
        hookIndex = 0;
        pendingEffects = [];
        internals.H = dispatcher;
        const page = RoutingWorkflow({ settings, canUpdate: true });
        for (const effect of pendingEffects) effect?.();
        internals.H = originalDispatcher;
        return page.props.children;
    };
    const submit = async (panel, response) => {
        panel.props.onSave();
        const visit = visits.at(-1);
        assert.equal(visit.url, '/settings/routing-workflow');
        visit.options.onBefore?.({});
        visit.options.onStart?.({});
        if (response) await visit.options.onSuccess({ props: { settings: response } });
        else visit.options.onError({ office_penro_enabled: 'Choose a value.' });
        visit.options.onFinish?.({});
        return visit;
    };

    inertia.router.put = (url, data, options) => {
        visits.push({ url, data: structuredClone(data), options });
    };
    globalThis.window = { setTimeout: () => 1, clearTimeout() {} };

    try {
        let panel = render({ available: true, version: 1, office_penro_enabled: true, penro_tsd_chief_enabled: true });
        panel.props.onToggle('office_penro_enabled', false);
        panel = render({ available: true, version: 1, office_penro_enabled: true, penro_tsd_chief_enabled: true });
        await submit(panel, { available: true, version: 2, office_penro_enabled: false, penro_tsd_chief_enabled: true });

        panel = render({ available: true, version: 2, office_penro_enabled: false, penro_tsd_chief_enabled: true });
        panel.props.onToggle('office_penro_enabled', true);
        panel = render({ available: true, version: 2, office_penro_enabled: false, penro_tsd_chief_enabled: true });
        await submit(panel, { available: true, version: 3, office_penro_enabled: true, penro_tsd_chief_enabled: true });

        panel = render({ available: true, version: 3, office_penro_enabled: true, penro_tsd_chief_enabled: true });
        panel.props.onToggle('penro_tsd_chief_enabled', false);
        panel.props.onReason('Validation recovery remains available');
        panel = render({ available: true, version: 3, office_penro_enabled: true, penro_tsd_chief_enabled: true });
        await submit(panel, null);
        panel = render({ available: true, version: 3, office_penro_enabled: true, penro_tsd_chief_enabled: true });

        assert.deepEqual(visits.map(visit => visit.data.expected_version), [1, 2, 3]);
        assert.deepEqual(panel.props.data, {
            expected_version: 3,
            office_penro_enabled: true,
            penro_tsd_chief_enabled: false,
            reason: 'Validation recovery remains available',
        });
    } finally {
        internals.H = originalDispatcher;
        inertia.router.put = originalPut;
        if (originalWindow === undefined) delete globalThis.window;
        else globalThis.window = originalWindow;
    }
});

test('skipped routing rows stay visible without receiving pending, current, or completed credit', async () => {
    const { default: Timeline } = await load('Components/SubmissionTracking/DocumentRoutingTimeline.jsx');
    const skip = { key: 'office_initial_skipped', label: 'Office of the PENRO (initial routing)', status: 'skipped', display_status: 'skipped', display_status_label: 'Skipped by Routing Workflow Settings', occurred_at: null, recorded_by: null };
    const timeline = timelinePresentation([skip, { key: 'next', status: 'pending' }]);
    assert.deepEqual(timeline.visibleSteps.map(item => item.key), ['office_initial_skipped', 'next']);
    assert.deepEqual(timeline.pendingSteps.map(item => item.key), ['next']);

    const html = renderToStaticMarkup(React.createElement(Timeline, { row: {
        can_transition: false,
        routing: { profile_label: 'Canonical routing', actions: [], timeline: [skip] },
    } }));
    assert.match(html, /Skipped by Routing Workflow Settings/);
    assert.match(html, /No routing event was created for this position/);
    assert.doesNotMatch(html, /Current\/<\/span>/);

    const page = await readFile(new URL('../../resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');
    assert.match(page, /This report uses routing version/);
});
