import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse } from '@babel/parser';
import { filterSelectionSummary, normalizeFilterValues, toggleFilterValue } from '../../resources/js/Components/Form/filterSelection.mjs';

test('single-select picker keeps one selected value and renders no checkbox control', async () => {
    const source = await readFile(new URL('../../resources/js/Components/Form/ScopedOptionSelect.jsx', import.meta.url), 'utf8');
    assert.match(source, /role="combobox"/);
    assert.match(source, /role="option" aria-selected=/);
    assert.doesNotMatch(source, /type="checkbox"/);
    assert.match(source, /onChange\?\.\(String\(option\.id\)\)/);
});

test('multi-select filter renders checkbox options and shows retained labels or selected count', async () => {
    const source = await readFile(new URL('../../resources/js/Components/Form/MultiSelectFilter.jsx', import.meta.url), 'utf8');
    const options = [{ value: 'cenro', label: 'CENRO' }, { value: 'penro', label: 'PENRO' }, { value: 'pamo', label: 'PAMO' }];
    assert.deepEqual(normalizeFilterValues(['cenro', 7]), ['cenro', '7']);
    assert.equal(filterSelectionSummary(options, ['cenro', 'penro']), 'CENRO, PENRO');
    assert.equal(filterSelectionSummary(options, ['cenro', 'penro', 'pamo']), '3 selected');
    assert.match(source, /type="checkbox" checked=\{checked\}/);
    assert.match(source, /aria-multiselectable="true"/);
    assert.match(source, /onChange\?\.\(toggleFilterValue\(selected, option\.value\)\)/);
});

test('multi-select toggling and clear preserve array-valued filter semantics', () => {
    assert.deepEqual(toggleFilterValue(['cenro'], 'penro'), ['cenro', 'penro']);
    assert.deepEqual(toggleFilterValue(['cenro', 'penro'], 'cenro'), ['penro']);
});

test('shared filter dropdowns and content cards use the system surfaces', async () => {
    const customSelect = await readFile(new URL('../../resources/js/Components/Form/ScopedOptionSelect.jsx', import.meta.url), 'utf8');
    const cards = await readFile(new URL('../../resources/js/Components/Card.jsx', import.meta.url), 'utf8');
    const table = await readFile(new URL('../../resources/js/Components/Crud/CrudTable.jsx', import.meta.url), 'utf8');
    const styles = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(customSelect, /cds-filter-dropdown/);
    assert.match(customSelect, /cds-filter-option/);
    assert.match(cards, /cds-card-surface/);
    assert.match(table, /cds-card-surface/);
    assert.match(styles, /\.cds-card-surface\s*\{\s*box-shadow:/);
});

test('filter toolbar yields Escape to an open child selector before closing', async () => {
    const toolbar = await readFile(new URL('../../resources/js/Components/Form/FilterToolbar.jsx', import.meta.url), 'utf8');
    const nodes = [];
    const visit = value => {
        if (!value || typeof value !== 'object') return;
        if (typeof value.type === 'string') nodes.push(value);
        for (const child of Object.values(value)) {
            if (Array.isArray(child)) child.forEach(visit);
            else if (child && typeof child === 'object') visit(child);
        }
    };
    visit(parse(toolbar, { sourceType: 'module', plugins: ['jsx'] }));
    const listeners = nodes.filter(node => node.type === 'CallExpression'
        && node.callee.type === 'MemberExpression'
        && node.callee.object.name === 'document'
        && node.callee.property.name === 'addEventListener'
        && node.arguments[0]?.value === 'keydown');
    assert.equal(listeners.length, 1, 'Use the real document keydown handler');

    let callback = listeners[0].arguments[1];
    if (callback.type === 'Identifier') {
        const definitions = nodes.filter(node =>
            (node.type === 'VariableDeclarator' && node.id.name === callback.name)
            || (node.type === 'FunctionDeclaration' && node.id?.name === callback.name));
        assert.equal(definitions.length, 1, 'Resolve the actual registered callback');
        callback = definitions[0].type === 'VariableDeclarator' ? definitions[0].init : definitions[0];
    }
    assert.ok(['ArrowFunctionExpression', 'FunctionExpression', 'FunctionDeclaration'].includes(callback.type));
    const calls = [];
    const handler = new Function('setOpen', 'requestAnimationFrame', 'triggerRef', 'panelRef',
        'return (' + toolbar.slice(callback.start, callback.end) + ');')(
        value => calls.push(['open', value]),
        () => calls.push(['frame']),
        { current: { focus: () => calls.push(['focus']) } },
        { current: { contains: () => false } },
    );
    for (const event of [
        { key: 'Escape', defaultPrevented: true },
        { key: 'Enter', defaultPrevented: false },
        { key: 'Tab', defaultPrevented: false },
    ]) {
        handler({ ...event, preventDefault: () => calls.push(['preventDefault']) });
        assert.deepEqual(calls, [], 'Child-handled Escape and unrelated keys must not close or refocus Filters');
    }
});
