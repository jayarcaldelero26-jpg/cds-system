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
hookRuntime.useEffect = () => {};

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

test('shared filter popover opens from its trigger and closes with focus returned to the trigger', () => {
    const previousDocument = globalThis.document;
    const previousAnimationFrame = globalThis.requestAnimationFrame;
    let triggerFocusCount = 0;
    globalThis.document = { body: {} };
    globalThis.requestAnimationFrame = callback => callback();

    const render = () => {
        cursor = 0;
        return FilterToolbar({ label: 'Filters', children: React.createElement('div', null, 'filter controls') });
    };

    try {
        let tree = render();
        const trigger = findElement(tree, element => element.type === 'button' && element.props['aria-controls'] === 'shared-filter-panel');
        assert.ok(trigger);
        assert.equal(trigger.props['aria-expanded'], false);
        slots[0].current = { focus: () => { triggerFocusCount += 1; } };

        trigger.props.onClick();
        tree = render();
        const openedTrigger = findElement(tree, element => element.type === 'button' && element.props['aria-controls'] === 'shared-filter-panel');
        const panel = findElement(tree, element => element.type === 'section' && element.props.id === 'shared-filter-panel');
        assert.equal(openedTrigger.props['aria-expanded'], true);
        assert.equal(panel.props['aria-label'], 'Filters options');

        const close = findElement(panel, element => element.type === 'button' && element.props['aria-label'] === 'Close filters');
        close.props.onClick();
        tree = render();
        const closedTrigger = findElement(tree, element => element.type === 'button' && element.props['aria-controls'] === 'shared-filter-panel');
        assert.equal(closedTrigger.props['aria-expanded'], false);
        assert.equal(findElement(tree, element => element.type === 'section' && element.props.id === 'shared-filter-panel'), null);
        assert.equal(triggerFocusCount, 1);
    } finally {
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
        if (previousAnimationFrame === undefined) delete globalThis.requestAnimationFrame;
        else globalThis.requestAnimationFrame = previousAnimationFrame;
        slots = [];
        initializedSlots = new Set();
        cursor = 0;
    }
});
