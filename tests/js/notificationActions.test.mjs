import test from 'node:test';
import assert from 'node:assert/strict';
import { markNotificationRead, shouldFollowNotificationDestination } from '../../resources/js/Utils/notificationActions.mjs';

test('View marks one notification through a same-origin Inertia patch and waits for success', () => {
    const visits = [];
    const callbacks = [];
    const fakeRouter = { patch: (...args) => visits.push(args) };

    assert.equal(markNotificationRead(fakeRouter, 'notice-1', {
        onSuccess: page => callbacks.push(['success', page]),
        onFailure: error => callbacks.push(['failure', error]),
        onFinish: () => callbacks.push(['finish']),
    }), true);
    assert.equal(markNotificationRead(fakeRouter, 'notice-1'), false);

    assert.equal(visits.length, 1);
    assert.equal(visits[0][0], '/notifications/notice-1/read');
    assert.deepEqual(visits[0][1], {});
    assert.equal(visits[0][2].preserveScroll, true);
    assert.equal(visits[0][2].preserveState, true);
    assert.equal(Object.hasOwn(visits[0][2], 'headers'), false);

    visits[0][2].onSuccess({ component: 'SubmissionTracking/Index' });
    visits[0][2].onFinish();
    assert.deepEqual(callbacks, [['success', { component: 'SubmissionTracking/Index' }], ['finish']]);
});

test('stale Incoming responses keep the server-selected queue destination', () => {
    assert.equal(shouldFollowNotificationDestination({ props: { trackingContext: { notification_action: 'stale_incoming' } } }), false);
    assert.equal(shouldFollowNotificationDestination({ props: { flash: { status: 'This report no longer needs your action.' } } }), false);
    assert.equal(shouldFollowNotificationDestination({ props: { trackingContext: { notification_action: null } } }), true);
    assert.equal(shouldFollowNotificationDestination({ component: 'Notifications/Index' }), true);
});

test('a 419 leaves success untouched, suppresses the default dialog, and allows one retry after finish', () => {
    const visits = [];
    const failures = [];
    let successes = 0;
    const fakeRouter = { patch: (...args) => visits.push(args) };

    markNotificationRead(fakeRouter, 'notice-419', {
        onSuccess: () => { successes += 1; },
        onFailure: failure => failures.push(failure),
    });
    assert.equal(visits[0][2].onHttpException({ status: 419 }), false);
    assert.equal(successes, 0);
    assert.deepEqual(failures, [{ kind: 'expired', status: 419 }]);

    visits[0][2].onFinish();
    assert.equal(markNotificationRead(fakeRouter, 'notice-419'), true);
    assert.equal(visits.length, 2);
    visits[1][2].onFinish();
});
