import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { navigationPendingReducer } from '../../resources/js/Layouts/navigationPending.mjs';

const authenticatedLayout = readFileSync(new URL('../../resources/js/Layouts/AuthenticatedLayout.jsx', import.meta.url), 'utf8');
const dashboard = readFileSync(new URL('../../resources/js/Pages/Dashboard.jsx', import.meta.url), 'utf8');

test('the matching visit completion clears its pending destination', () => {
    const intent = { id: 1, href: '/reports', label: 'Reports' };
    const pending = navigationPendingReducer(null, { type: 'start', intent });

    assert.equal(pending, intent);
    assert.equal(navigationPendingReducer(pending, { type: 'finish', id: intent.id }), null);
});

test('an older rapid-click completion cannot clear the newest destination', () => {
    const first = { id: 1, href: '/reports', label: 'Reports' };
    const second = { id: 2, href: '/admin/business-calendar', label: 'Calendar' };
    const pendingFirst = navigationPendingReducer(null, { type: 'start', intent: first });
    const pendingSecond = navigationPendingReducer(pendingFirst, { type: 'start', intent: second });

    assert.equal(navigationPendingReducer(pendingSecond, { type: 'finish', id: first.id }), pendingSecond);
    assert.equal(navigationPendingReducer(pendingSecond, { type: 'finish', id: second.id }), null);
});

test('latest ordinary GET visits drive the pending skeleton and sidebar destination', () => {
    assert.match(authenticatedLayout, /role="status"\s+aria-live="polite"/);
    assert.match(authenticatedLayout, /data-cds-navigation-pending/);
    assert.match(authenticatedLayout, /router\.on\('start'/);
    assert.match(authenticatedLayout, /router\.on\('finish'/);
    assert.match(authenticatedLayout, /navigationSkeletonIntent\(visit, currentPageUrl\.current/);
    assert.match(authenticatedLayout, /NavigationHighlight[^\n]*pendingUrl=\{pendingUrl\}/);
    assert.match(authenticatedLayout, /pendingUrl=\{pendingNavigation\?\.href \?\? null\}/);
    assert.match(authenticatedLayout, /latestPendingId\.current === id/);
    assert.doesNotMatch(authenticatedLayout, /prefetch=/);
});

test('dashboard report cards preserve their server URLs through Inertia links', () => {
    assert.match(dashboard, /import \{ Link, router \} from '@inertiajs\/react'/);
    assert.match(dashboard, /<Link key=\{item\.id\} href=\{item\.source_url\}/);
    assert.equal((dashboard.match(/<Link className="font-medium text-emerald-800 hover:underline dark:text-emerald-300" href=\{row\.source_url\}>\{row\.report\}<\/Link>/g) || []).length, 2);
    assert.doesNotMatch(dashboard, /<a[^>]+href=\{(?:item|row)\.source_url\}/);
});
