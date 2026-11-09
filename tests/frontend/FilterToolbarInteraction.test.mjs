import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { rolldown } from 'rolldown';
import React from 'react';

const nativeRequire = createRequire(import.meta.url);
const source = await rolldown({
    input: resolve('resources/js/Components/Form/FilterToolbar.jsx'),
    external: id => id === 'react' || id.startsWith('react/'),
    plugins: [{
        name: 'filter-toolbar-interaction-shims',
        resolveId(id) {
            if (id === 'react-dom') return '\0filter-toolbar-react-dom';
            if (id === '@iconify/react') return '\0filter-toolbar-iconify';
        },
        load(id) {
            if (id === '\0filter-toolbar-react-dom') return 'export const createPortal = children => children;';
            if (id === '\0filter-toolbar-iconify') return 'export const Icon = () => null;';
        },
    }],
    resolve: { alias: { '@': resolve('resources/js') } },
    transform: { jsx: { runtime: 'automatic' } },
});
const { output } = await source.generate({ format: 'cjs' });
await source.close();

let slots = [];
let initializedSlots = new Set();
let cursor = 0;
const hookRuntime = Object.create(React);
hookRuntime.useRef = initial => {
    const index = cursor++;
    if (!initializedSlots.has(index)) {
        slots[index] = { current: initial };
        initializedSlots.add(index);
    }
    return slots[index];
};
hookRuntime.useState = initial => {
    const index = cursor++;
    if (!initializedSlots.has(index)) {
        slots[index] = typeof initial === 'function' ? initial() : initial;
        initializedSlots.add(index);
    }
    return [slots[index], value => { slots[index] = typeof value === 'function' ? value(slots[index]) : value; }];
};
const layoutEffects = [];
const passiveEffects = [];
const effectSlots = new Map();

function queueEffect(queue, effect, dependencies) {
    const index = cursor++;
    const previous = effectSlots.get(index);
    if (dependencies && previous?.dependencies?.length === dependencies.length
        && dependencies.every((value, offset) => Object.is(value, previous.dependencies[offset]))) return;

    queue.push(() => {
        previous?.cleanup?.();
        effectSlots.set(index, { dependencies, cleanup: effect() });
    });
}

hookRuntime.useLayoutEffect = (effect, dependencies) => queueEffect(layoutEffects, effect, dependencies);
hookRuntime.useEffect = (effect, dependencies) => queueEffect(passiveEffects, effect, dependencies);

const toolbarModule = { exports: {} };
new Function('require', 'module', 'exports', output[0].code)(id => {
    if (id === 'react') return hookRuntime;
    return nativeRequire(id);
}, toolbarModule, toolbarModule.exports);
const FilterToolbar = toolbarModule.exports;

function findElement(root, predicate) {
    if (!React.isValidElement(root)) return null;
    if (predicate(root)) return root;
    for (const child of React.Children.toArray(root.props.children)) {
        const found = findElement(child, predicate);
        if (found) return found;
    }
    return null;
}

function createFixture(t, panelNode = { contains: () => false }) {
    const previousGlobals = {
        document: globalThis.document,
        window: globalThis.window,
        requestAnimationFrame: globalThis.requestAnimationFrame,
    };
    const document = Object.assign(new EventTarget(), { body: {} });
    const window = Object.assign(new EventTarget(), { innerWidth: 1024, innerHeight: 768 });
    let triggerFocusCount = 0;
    let mountedPanelRef = null;
    const triggerNode = {
        getBoundingClientRect: () => ({ left: 40, top: 40, right: 160, bottom: 80, width: 120, height: 40 }),
        focus: () => { triggerFocusCount += 1; },
    };

    t.after(() => {
        try {
            for (const { cleanup } of effectSlots.values()) cleanup?.();
        } finally {
            for (const [name, value] of Object.entries(previousGlobals)) {
                if (value === undefined) delete globalThis[name];
                else globalThis[name] = value;
            }
            effectSlots.clear();
            layoutEffects.length = 0;
            passiveEffects.length = 0;
            slots = [];
            initializedSlots = new Set();
            cursor = 0;
        }
    });

    globalThis.document = document;
    globalThis.window = window;
    globalThis.requestAnimationFrame = callback => callback();

    function renderOnce() {
        cursor = 0;
        const tree = FilterToolbar({ label: 'Filters', children: React.createElement('div', null, 'filter controls') });
        const trigger = findElement(tree, element => element.type === 'button' && element.props['aria-controls'] === 'shared-filter-panel');
        const panel = findElement(tree, element => element.type === 'section' && element.props.id === 'shared-filter-panel');
        assert.ok(trigger);
        trigger.props.ref.current = triggerNode;
        if (panel) mountedPanelRef = panel.props.ref;
        if (mountedPanelRef) mountedPanelRef.current = panel ? panelNode : null;

        for (const effect of layoutEffects.splice(0)) effect();
        for (const effect of passiveEffects.splice(0)) effect();
        return { trigger, panel };
    }

    return {
        document,
        get triggerFocusCount() { return triggerFocusCount; },
        render() {
            renderOnce();
            return renderOnce();
        },
    };
}

test('shared filter popover opens from its trigger and closes with focus returned to the trigger', t => {
    const fixture = createFixture(t);
    const { trigger } = fixture.render();
    assert.equal(trigger.props['aria-expanded'], false);

    trigger.props.onClick();
    const opened = fixture.render();
    assert.equal(opened.trigger.props['aria-expanded'], true);
    assert.equal(opened.panel.props['aria-label'], 'Filters options');

    const close = findElement(opened.panel, element => element.type === 'button' && element.props['aria-label'] === 'Close filters');
    close.props.onClick();
    const closed = fixture.render();
    assert.equal(closed.trigger.props['aria-expanded'], false);
    assert.equal(closed.panel, null);
    assert.equal(fixture.triggerFocusCount, 1);
});

test('Escape yields to an expanded picker trigger inside Filters before closing Filters', t => {
    let pickerExpanded = true;
    const pickerTrigger = {
        closest(selector) {
            return pickerExpanded && selector === 'button[aria-haspopup="dialog"][aria-expanded="true"]' ? this : null;
        },
    };
    const fixture = createFixture(t, { contains: element => element === pickerTrigger });
    fixture.render().trigger.props.onClick();
    assert.ok(fixture.render().panel);

    const pressEscape = () => {
        const event = new Event('keydown', { cancelable: true });
        Object.defineProperties(event, {
            key: { value: 'Escape' },
            target: { value: pickerTrigger },
        });
        fixture.document.dispatchEvent(event);
        return event.defaultPrevented;
    };

    assert.equal(pressEscape(), false);
    assert.equal(fixture.render().trigger.props['aria-expanded'], true);
    assert.equal(fixture.triggerFocusCount, 0);

    // The picker handles its own Escape and clears its expanded state.
    pickerExpanded = false;
    assert.equal(pressEscape(), true);
    const closed = fixture.render();
    assert.equal(closed.trigger.props['aria-expanded'], false);
    assert.equal(closed.panel, null);
    assert.equal(fixture.triggerFocusCount, 1);
});
