import test from 'node:test';
import assert from 'node:assert/strict';
import { navigationPendingReducer, navigationSkeletonIntent, navigationVisitKey } from '../../resources/js/Layouts/navigationPending.mjs';

globalThis.location = { origin: 'http://cds-smart-web.test' };
const visit = (url, options = {}) => ({ url: new URL(url, 'http://cds-smart-web.test'), method: 'get', ...options });

test('destination family follows the pending destination and meaningful view', () => {
    assert.equal(navigationSkeletonIntent(visit('/reports'), '/dashboard', 1).family, 'reports');
    assert.equal(navigationSkeletonIntent(visit('/bms?tracker=1'), '/bms', 2).family, 'bms-tracker');
    assert.equal(navigationSkeletonIntent(visit('/bms'), '/bms?tracker=1', 3).family, 'bms-list');
    assert.equal(navigationSkeletonIntent(visit('/calendar'), '/bms', 4).family, 'calendar');
    assert.equal(navigationSkeletonIntent(visit('/profile'), '/bms', 5).family, 'form');
    assert.equal(navigationSkeletonIntent(visit('/ordinary'), '/bms', 6).family, 'generic');
});

test('only full internal page GET visits start a skeleton', () => {
    assert.equal(navigationSkeletonIntent(visit('/reports'), '/reports', 1), null);
    assert.equal(navigationSkeletonIntent(visit('/reports#table'), '/reports', 1), null);
    assert.equal(navigationSkeletonIntent(visit('/reports', { method: 'post' }), '/dashboard', 1), null);
    assert.equal(navigationSkeletonIntent(visit('/reports', { only: ['summary'] }), '/dashboard', 1), null);
    assert.equal(navigationSkeletonIntent(visit('/files/export'), '/dashboard', 1), null);
    assert.equal(navigationSkeletonIntent(visit('https://example.org/reports'), '/dashboard', 1), null);
});

test('stale finish cannot clear the latest visit and matching finish reveals content', () => {
    const first = navigationSkeletonIntent(visit('/reports'), '/dashboard', 1);
    const second = navigationSkeletonIntent(visit('/calendar'), '/reports', 2);
    let state = navigationPendingReducer(null, { type: 'start', intent: first });
    state = navigationPendingReducer(state, { type: 'start', intent: second });
    assert.equal(navigationPendingReducer(state, { type: 'finish', id: first.id }), second);
    assert.equal(navigationPendingReducer(state, { type: 'finish', id: second.id }), null);
});

test('separate Inertia event payload objects still correlate to the ordered destination', () => {
    const started = visit('/bms?tracker=1');
    const finished = visit('/bms?tracker=1');
    assert.notEqual(started, finished);
    assert.equal(navigationVisitKey(started), navigationVisitKey(finished));
    assert.equal(navigationVisitKey(visit('/bms?tracker=1#row')), '/bms?tracker=1');
});
