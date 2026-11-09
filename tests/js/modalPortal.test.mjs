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

for (const name of ['modal', 'confirmation', 'form', 'details']) {
    test(name + ' mounts its actual layer through the shared portal and retains panel/layer refs', () => {
        const instance = fixture(name);
        const content = React.createElement('input', { defaultValue: 'Keep unsaved input' });
        const result = instance.render({ open: true, title: 'Report fixture', children: content });
        assert.equal(result.type, PortalMarker);
        const layer = result.props.children;
        assert.equal(layer.props['data-cds-modal-layer'], true);
        assert.equal(layer.props.ref, instance.hooks[0].layerRef);
        const panels = descendants(layer).filter(element => ['dialog', 'alertdialog'].includes(element.props?.role));
        assert.equal(panels.length, 1);
        assert.equal(panels[0].props.ref, instance.hooks[0].panelRef);
        assert.equal(panels[0].props['aria-modal'], 'true');
        if (name !== 'confirmation') assert.ok(descendants(layer).some(element => element.props?.defaultValue === 'Keep unsaved input'));
    });

    test(name + ' does not render a portal when the modal is absent', () => {
        assert.equal(fixture(name).render({ open: false, title: 'Closed fixture' }), null);
    });
}

test('portaling the report form keeps the original submit callback and form element', () => {
    const calls = [];
    const instance = fixture('form');
    const result = instance.render({ open: true, title: 'Report fixture', confirmation: false, onSubmit: event => calls.push(event) });
    const form = descendants(result).find(element => element.type === 'form');
    assert.ok(form);
    assert.equal(form.props.ref, instance.hooks[0].panelRef);
    const event = { preventDefault() { assert.fail('The portal must not change submission handling'); } };
    form.props.onSubmit(event);
    assert.deepEqual(calls, [event]);
});
