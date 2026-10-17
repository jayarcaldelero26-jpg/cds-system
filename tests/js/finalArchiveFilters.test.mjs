import assert from 'node:assert/strict';
import test from 'node:test';
import { applyFinalArchiveFilters, finalArchiveFilterParams, finalArchiveFiltersMatch } from '../../resources/js/Utils/finalArchiveFilters.mjs';

test('Final Archives filters retain existing query keys and selected-record context', () => {
    const params = finalArchiveFilterParams({
        search: '  signed report  ',
        module: 'BMS',
        protectedArea: 'pa-7',
        office: 'PENRO Davao Oriental',
        year: '2026',
        selected: { source: 'bms', source_id: 42 },
    });

    assert.deepEqual(params, {
        search: 'signed report',
        module: 'BMS',
        protected_area: 'pa-7',
        office: 'PENRO Davao Oriental',
        reporting_year: '2026',
        source: 'bms',
        record: 42,
    });
});

test('applying changed filters keeps the existing index route options and selected record', () => {
    const calls = [];
    const routeCalls = [];
    const router = { get: (...args) => calls.push(args) };
    const routeFor = name => { routeCalls.push(name); return '/submission-tracking/archives'; };
    const didApply = applyFinalArchiveFilters(router, routeFor, {
        search: '  MOV  ', module: '', protectedArea: '', office: '', year: '',
        selected: { source: 'imea', source_id: 19 },
    }, new URLSearchParams('search=old&source=imea&record=19'));

    assert.equal(didApply, true);
    assert.deepEqual(calls, [[
        '/submission-tracking/archives',
        { search: 'MOV', source: 'imea', record: 19 },
        { preserveState: true, preserveScroll: true, replace: true },
    ]]);
    assert.deepEqual(routeCalls, ['final-archives.index']);
});

test('unchanged filter values do not trigger another index request', () => {
    const calls = [];
    const routeCalls = [];
    const router = { get: (...args) => calls.push(args) };
    const filters = { search: '', module: 'BMS', protectedArea: '', office: '', year: '2026', selected: null };
    const current = new URLSearchParams('module=BMS&reporting_year=2026');

    assert.equal(finalArchiveFiltersMatch(current, finalArchiveFilterParams(filters)), true);
    assert.equal(applyFinalArchiveFilters(router, name => { routeCalls.push(name); return '/unused'; }, filters, current), false);
    assert.deepEqual(calls, []);
    assert.deepEqual(routeCalls, []);
});

test('clearing filters keeps the selected record query while omitting empty filters', () => {
    const calls = [];
    const router = { get: (...args) => calls.push(args) };
    const didApply = applyFinalArchiveFilters(router, () => '/submission-tracking/archives', {
        search: '', module: '', protectedArea: '', office: '', year: '',
        selected: { source: 'bams', source_id: 5 },
    }, new URLSearchParams('search=old&module=BAMS&source=bams&record=5'));

    assert.equal(didApply, true);
    assert.deepEqual(calls[0][1], { source: 'bams', record: 5 });
});
