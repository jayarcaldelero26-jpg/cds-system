import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createCoalescedPartialReload,
    createLiveRefreshRuntime,
    LIVE_REFRESH_FALLBACK_INTERVAL_MS,
} from '../../resources/js/Utils/liveRefreshRuntime.mjs';
import {
    loadNotificationToastSeenIds,
    notificationToastStorageKey,
    persistNotificationToastSeenIds,
    takeNewReportNotifications,
} from '../../resources/js/Utils/notificationToastDedup.mjs';

function fakeIntervals() {
    let nextId = 1;
    const callbacks = new Map();
    return {
        setIntervalImpl(callback, delay) {
            const id = nextId++;
            callbacks.set(id, { callback, delay });
            return id;
        },
        clearIntervalImpl(id) {
            callbacks.delete(id);
        },
        tick() {
            [...callbacks.values()].forEach(timer => timer.callback());
        },
        get size() { return callbacks.size; },
        get delays() { return [...callbacks.values()].map(timer => timer.delay); },
    };
}

function fakeTimeouts() {
    let nextId = 1;
    const callbacks = new Map();
    return {
        schedule(callback) {
            const id = nextId++;
            callbacks.set(id, callback);
            return id;
        },
        cancel(id) { callbacks.delete(id); },
        flush() {
            const batch = [...callbacks.values()];
            callbacks.clear();
            batch.forEach(callback => callback());
        },
        get size() { return callbacks.size; },
    };
}

test('live refresh stops fallback when healthy and reconciles after reconnect', () => {
    const timers = fakeIntervals();
    const scopes = [];
    const runtime = createLiveRefreshRuntime({
        onRefresh: scope => scopes.push(scope),
        intervalMs: LIVE_REFRESH_FALLBACK_INTERVAL_MS,
        setIntervalImpl: timers.setIntervalImpl,
        clearIntervalImpl: timers.clearIntervalImpl,
    });

    runtime.start();
    assert.equal(timers.size, 1);
    assert.deepEqual(timers.delays, [10_000]);
    timers.tick();
    assert.deepEqual(scopes, ['all']);

    runtime.setConnectionState('connected');
    assert.equal(runtime.polling, false);
    assert.deepEqual(scopes, ['all', 'all']);
    runtime.setConnectionState('unavailable');
    assert.equal(runtime.polling, true);
    timers.tick();
    runtime.setConnectionState('connected');
    assert.deepEqual(scopes, ['all', 'all', 'all', 'all']);
    assert.equal(timers.size, 0);

    runtime.dispose();
});

test('live refresh pauses in hidden tabs and catches up immediately when visible or online', () => {
    const timers = fakeIntervals();
    const scopes = [];
    const runtime = createLiveRefreshRuntime({
        onRefresh: scope => scopes.push(scope),
        isVisible: false,
        setIntervalImpl: timers.setIntervalImpl,
        clearIntervalImpl: timers.clearIntervalImpl,
    });

    runtime.start();
    assert.equal(timers.size, 0);
    runtime.setVisible(true);
    assert.equal(timers.size, 1);
    assert.deepEqual(scopes, ['all']);
    runtime.setVisible(false);
    assert.equal(timers.size, 0);
    runtime.online();
    assert.deepEqual(scopes, ['all', 'all']);
    runtime.dispose();
});

test('partial Inertia reloads coalesce and preserve the current page state and scroll', () => {
    const timers = fakeTimeouts();
    const visits = [];
    const reload = createCoalescedPartialReload({ reload: options => visits.push(options) }, ['workspaceQueues', 'trackingContext'], {
        schedule: timers.schedule,
        cancel: timers.cancel,
    });

    reload.request();
    reload.request();
    assert.equal(timers.size, 1);
    timers.flush();
    assert.equal(visits.length, 1);
    assert.deepEqual(visits[0].only, ['workspaceQueues', 'trackingContext']);
    assert.equal(visits[0].preserveState, true);
    assert.equal(visits[0].preserveScroll, true);

    reload.request();
    visits[0].onFinish();
    assert.equal(timers.size, 1);
    timers.flush();
    assert.equal(visits.length, 2);
    reload.dispose();
});

test('toast deduplication skips the initial backlog and returns each new report alert once', () => {
    const stored = new Map();
    const storage = {
        getItem: key => stored.get(key) ?? null,
        setItem: (key, value) => stored.set(key, value),
    };
    const userId = 29;
    const initial = [{ id: 'already-open', category: 'submission_updates' }];
    const seen = loadNotificationToastSeenIds(storage, userId, initial);
    const key = notificationToastStorageKey(userId);
    const watermark = '2026-11-10T10:00:00.000000+08:00';
    const payload = {
        notifications: [
            { id: 'already-open', category: 'submission_updates', created_at: '2026-11-10T10:00:01.000000+08:00' },
            { id: 'old-backlog', category: 'submission_updates', created_at: '2026-11-10T09:59:59.000000+08:00' },
            { id: 'new-report', category: 'submission_updates', created_at: '2026-11-10T10:00:01.000000+08:00' },
            { id: 'new-reminder', category: 'overdue', created_at: '2026-11-10T10:00:02.000000+08:00' },
        ],
    };

    assert.deepEqual(takeNewReportNotifications(payload, seen, watermark).map(item => item.id), ['new-report']);
    persistNotificationToastSeenIds(storage, key, seen);
    assert.deepEqual(takeNewReportNotifications(payload, seen, watermark), []);
    assert.equal(JSON.parse(stored.get(key)).includes('new-report'), true);
});
