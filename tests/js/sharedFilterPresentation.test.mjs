import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
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
