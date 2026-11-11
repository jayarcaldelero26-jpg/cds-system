import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import React from 'react';
import { rolldown } from 'rolldown';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

const nativeRequire = createRequire(import.meta.url);
const paths = {
    portal: 'resources/js/Components/ModalPortal.jsx',
    modal: 'resources/js/Components/Modal.jsx',
    confirmation: 'resources/js/Components/ConfirmDialog.jsx',
    form: 'resources/js/Components/Crud/CrudFormModal.jsx',
    details: 'resources/js/Components/Crud/CrudDetailsModal.jsx',
};
const compiled = new Map();
for (const [key, path] of Object.entries(paths)) {
    const bundle = await rolldown({
        input: resolve(path),
        external: () => true,
        transform: { jsx: { runtime: 'automatic' } },
    });
    try {
        const { output } = await bundle.generate({ format: 'cjs', exports: 'named' });
        compiled.set(key, output.find(item => item.type === 'chunk').code);
    } finally {
        await bundle.close();
    }
}

function PortalMarker() { return null; }
function ComponentMarker() { return null; }
function ModalMarker() { return null; }

/** Evaluate actual JSX components with inert hooks, without a browser or writes. */
function fixture(name, { documentObject, createPortal = () => assert.fail('Unexpected portal call') } = {}) {
    const runtime = Object.create(React);
    runtime.useRef = current => ({ current });
    runtime.useId = () => ':portal-fixture:';
    runtime.useState = initial => [typeof initial === 'function' ? initial() : initial, () => {}];
    runtime.useEffect = () => {};
    runtime.useLayoutEffect = () => {};
    const hooks = [];
    const module = { exports: {} };
    new Function('require', 'module', 'exports', 'document', compiled.get(name))(id => {
        if (id === 'react') return runtime;
        if (id.startsWith('react/')) return nativeRequire(id);
        if (id === 'react-dom') return { createPortal };
        if (id.endsWith('/ModalPortal')) return PortalMarker;
        if (id.endsWith('/Modal')) return ModalMarker;
        if (id.endsWith('/ModalMotion/presets.mjs')) return {
            MODAL_MOTION_PRESETS: {
                alert: 'super-scaled',
                blocking: 'super-scaled',
                content: 'side-fall',
                details: 'side-fall',
                form: 'side-fall',
                reader: 'side-fall',
            },
        };
        if (id.endsWith('/useModalMotion')) return {
            useModalMotion: (open, options) => {
                hooks.push(options);
                return { present: open, phase: 'open', onTransitionEnd() {} };
            },
        };
        if (id.endsWith('/initialFocus.mjs')) return { resolveCrudFormInitialFocusSelector: value => value };
        if (id.endsWith('/focusReturn.mjs')) return { releaseModalFocusTarget: () => null };
        if (id.endsWith('/useModalFileDrop')) return () => null;
        if (id.endsWith('/CrudModalCardContext')) return { Provider: ComponentMarker };
        if (id.endsWith('/ReportDetailsContext')) return { ReportDetailsContext: { Provider: ComponentMarker } };
        if (id.endsWith('/fixedBottomOverlayInset.mjs')) return { fixedBottomOverlayInset: () => 0 };
        if (id.startsWith('@/Components/') || id.startsWith('.')) return ComponentMarker;
        throw new Error('Unexpected modal fixture dependency: ' + id);
    }, module, module.exports, documentObject);
    return { render: module.exports.default, hooks };
}

function descendants(element) {
    if (!element || typeof element !== 'object') return [];
    return [element, ...React.Children.toArray(element.props?.children).flatMap(descendants)];
}

test('the actual portal places the original content directly under document.body', () => {
    const body = { name: 'viewport-body' };
    const child = React.createElement('section', { role: 'dialog', 'aria-label': 'Report' });
    const calls = [];
    const sentinel = { portal: true };
    const instance = fixture('portal', {
        documentObject: { body },
        createPortal: (content, target) => {
            calls.push({ content, target });
            return sentinel;
        },
    });
    assert.equal(instance.render({ children: child }), sentinel);
    assert.deepEqual(calls, [{ content: child, target: body }]);
});

test('SSR and a missing body do not create an inline or detached dialog', () => {
    for (const documentObject of [undefined, {}, { body: null }]) {
        const instance = fixture('portal', { documentObject });
        assert.equal(instance.render({ children: React.createElement('div') }), null);
    }
});

test('the markup fixture preserves real portal resolution in the browser environment', () => {
    const plugin = viteReactInterop();
    const browser = { environment: { config: { consumer: 'client' } } };
    for (const source of ['@/Components/ModalPortal', resolve(paths.portal)]) {
        assert.equal(plugin.resolveId.call(browser, source, undefined, {}), undefined);
    }
});

test('SSR markup fixtures render dialog content without changing the production portal', () => {
    const plugin = viteReactInterop();
    const server = { environment: { config: { consumer: 'server' } } };
    for (const source of ['@/Components/ModalPortal', resolve(paths.portal)]) {
        const id = plugin.resolveId.call(server, source, undefined, {});
        assert.equal(id, '\0test-modal-portal-inline');
        assert.match(plugin.load(id), /return children;/);
    }
});

test('the shared Modal mounts its actual layer through the portal and retains panel/layer refs', () => {
    {
        const name = 'modal';
        const instance = fixture(name);
        const content = React.createElement('input', { defaultValue: 'Keep unsaved input' });
        let result = instance.render({ open: true, title: 'Report fixture', children: content });
        while (result?.type !== PortalMarker && typeof result?.type === 'function') result = result.type(result.props);
        assert.equal(result.type, PortalMarker);
        const layer = result.props.children;
        assert.equal(layer.props['data-cds-modal-layer'], true);
        assert.equal(layer.props.ref, instance.hooks[0].layerRef);
        const panels = descendants(layer).filter(element => ['dialog', 'alertdialog'].includes(element.props?.role));
        assert.equal(panels.length, 1);
        assert.equal(panels[0].props.ref, instance.hooks[0].panelRef);
        assert.equal(panels[0].props['aria-modal'], 'true');
        assert.ok(descendants(layer).some(element => element.props?.defaultValue === 'Keep unsaved input'));
    }
});

for (const name of ['confirmation', 'form', 'details']) {
    test(name + ' delegates portal, panel, and lifecycle to the shared Modal', () => {
        const instance = fixture(name);
        const content = React.createElement('input', { defaultValue: 'Keep unsaved input' });
        const result = instance.render({ open: true, title: 'Report fixture', children: content });
        assert.equal(result.type, ModalMarker);
        assert.equal(result.props.open, true);
        assert.ok(result.props.panelRef);
        assert.ok(result.props.children);
        if (name !== 'confirmation') assert.ok(result.props.children);
    });

    test(name + ' passes the closed state to the shared Modal', () => {
        const result = fixture(name).render({ open: false, title: 'Closed fixture' });
        assert.equal(result.type, ModalMarker);
        assert.equal(result.props.open, false);
    });
}

test('portaling the report form keeps the original submit callback and form element', () => {
    const calls = [];
    const instance = fixture('form');
    const result = instance.render({ open: true, title: 'Report fixture', confirmation: false, onSubmit: event => calls.push(event) });
    assert.equal(result.type, ModalMarker);
    assert.equal(result.props.as, 'form');
    const event = { preventDefault() { assert.fail('The portal must not change submission handling'); } };
    result.props.panelProps.onSubmit(event);
    assert.deepEqual(calls, [event]);
});

test('the shared form bounds its panel to the dynamic viewport with a separate scroll body and footer', () => {
    const modal = fixture('form').render({ open: true, title: 'New draft report', saveLabel: 'Save Draft' });
    assert.match(modal.props.wrapperClassName, /\bh-dvh\b/);
    assert.match(modal.props.panelClassName, /max-h-\[min\(92dvh,100%\)\]/);
    assert.ok(modal.props.children, 'form, scroll body, and footer remain caller-owned panel content');
    assert.equal(modal.props.as, 'form');
    assert.match(modal.props.panelClassName, /overflow-hidden/);
});

test('the sending dialog owns the keyboard layer while the background form stays mounted and inert', () => {
    const instance = fixture('form');
    const modal = instance.render({
        open: true,
        title: 'Submit report',
        busyOverlay: React.createElement('p', { role: 'status' }, 'Sending document'),
        busyOverlayPresentation: 'success-sized',
        closeOnOverlayClick: true,
        onClose: () => assert.fail('The pending request cannot dismiss the form'),
    });
    const form = modal.props.panelProps;
    const dialog = descendants(modal.props.layerContent).find(element => element.type === ModalMarker && element.props.title === 'Request in progress');
    assert.equal(form.inert, true);
    assert.equal(form['aria-hidden'], true);
    assert.ok(dialog);
    assert.equal(dialog.props.open, true);
    assert.ok(dialog.props.panelRef);
    assert.equal(dialog.props.closeOnEscape, false);
    assert.equal(dialog.props.closeOnBackdrop, false);
    assert.equal(dialog.props.zIndex, 'z-[80]');
    assert.ok(descendants(dialog).some(element => element.props?.children === 'Sending document'));
    assert.equal(modal.props.closeOnEscape, false);
    assert.equal(modal.props.closeOnBackdrop, false);
    modal.props.onClose();
});

test('success-sized detail processing uses a shared modal above an inert details panel', () => {
    const modal = fixture('details').render({
        open: true,
        title: 'Archive report',
        busyOverlay: React.createElement('p', { role: 'status' }, 'Verifying archive'),
        busyOverlayPresentation: 'success-sized',
    });
    const dialog = descendants(modal.props.layerContent).find(element => element.type === ModalMarker && element.props.title === 'Request in progress');
    assert.equal(modal.props.panelProps.inert, true);
    assert.equal(modal.props.panelProps['aria-hidden'], true);
    assert.ok(dialog);
    assert.equal(dialog.props.open, true);
    assert.equal(dialog.props.closeOnEscape, false);
    assert.equal(dialog.props.closeOnBackdrop, false);
    assert.equal(dialog.props.zIndex, 'z-[80]');
    assert.ok(descendants(dialog).some(element => element.props?.children === 'Verifying archive'));
});
