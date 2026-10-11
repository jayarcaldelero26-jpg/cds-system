import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const month = readFileSync(new URL('../../resources/js/Components/BusinessCalendarMonth.jsx', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/js/Pages/Calendar/Index.jsx', import.meta.url), 'utf8');

test('existing month and year calendar filter historical entries separately and label event identity', () => {
    assert.match(month, /showHistorical, setShowHistorical/);
    assert.match(month, /checked=\{showHistorical\} onChange=\{event => setShowHistorical\(event\.target\.checked\)\}/);
    assert.match(month, /event\.is_historical \? showHistorical : showMovs/);
    assert.match(month, /event\.is_historical \? 'Historical · ' : ''/);
    assert.match(month, /Historical reports use a recorded activity date or original milestone/);
});

test('historical calendar detail stays read-only and routes through protected Historical Detail', () => {
    const historicalBranch = page.split('if (event.is_historical) {')[1]?.split('aria-label="Submitted report details"')[0];
    assert.ok(historicalBranch, 'historical calendar detail branch exists before the live MOV modal');
    assert.match(historicalBranch, /Read-only historical evidence/);
    assert.match(historicalBranch, /Source record/);
    assert.match(historicalBranch, /Date basis/);
    assert.match(historicalBranch, /Open protected Historical Detail/);
    assert.doesNotMatch(historicalBranch, /event\.attachment\.url|View MOV|Receive|Forward|Submit/);
});
