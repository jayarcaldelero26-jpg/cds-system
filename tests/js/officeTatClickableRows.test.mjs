import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { shouldActivateRow } from '../../resources/js/Components/Crud/rowActivation.mjs';

test('nested buttons and links do not activate a participant row', () => {
    const row = {};
    const nestedButton = { closest: selector => selector.includes('button') ? nestedButton : null };
    const nestedLink = { closest: selector => selector.includes('a[href]') ? nestedLink : null };

    assert.equal(shouldActivateRow({ type: 'click', target: nestedButton }, row), false);
    assert.equal(shouldActivateRow({ type: 'click', target: nestedLink }, row), false);
});

test('participant rows remain keyboard-activatable and click focus is opt-in', async () => {
    const row = { closest: () => null };
    const table = await readFile(new URL('../../resources/js/Components/Crud/CrudTable.jsx', import.meta.url), 'utf8');

    assert.equal(shouldActivateRow({ type: 'keydown', key: 'Enter', target: row }, row), true);
    assert.equal(shouldActivateRow({ type: 'keydown', key: ' ', target: row }, row), true);
    assert.equal(shouldActivateRow({ type: 'keydown', key: 'ArrowDown', target: row }, row), false);
    assert.match(table, /focusRowOnClick = false/);
    assert.match(table, /event\.type === 'click' && focusRowOnClick/);
});
