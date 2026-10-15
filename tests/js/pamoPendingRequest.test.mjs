import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { invalidatePamoPendingRequest, startPamoPendingRequest } from '../../resources/js/Utils/pamoPendingRequest.mjs';

function harness() {
    const state = { sequence: 0, activeId: null, intent: null };
    const intents = [];
    const sent = [];
    const start = (intent, handlers = {}) => startPamoPendingRequest(state, {
        intent,
        setIntent: value => intents.push(value),
        send: callbacks => sent.push(callbacks),
        ...handlers,
    });
    return { state, intents, sent, start };
}

test('PAMO pending presentation follows one existing Inertia mutation through confirmed success', () => {
    const h = harness();
    const successes = [];
    assert.equal(h.start('save', { onSuccess: page => successes.push(page.url) }), true);
    assert.equal(h.state.intent, 'save');
    assert.equal(h.sent.length, 1);
    assert.equal(h.start('submit'), false);
    assert.equal(h.sent.length, 1);

    const callbacks = h.sent[0];
    callbacks.onStart();
    callbacks.onSuccess({ url: '/pamo/reports' });
    callbacks.onSuccess({ url: '/duplicate' });
    assert.deepEqual(successes, ['/pamo/reports']);
    assert.equal(h.state.intent, null);
    assert.deepEqual(h.intents, ['save', 'save', null]);

    callbacks.onFinish();
    callbacks.onFinish();
    assert.equal(h.state.activeId, null);
    assert.deepEqual(h.intents.slice(-2), [null, null]);
});

test('validation, conflict, cancellation, HTTP and network failures clear presentation without success', () => {
    for (const event of ['onError', 'onCancel', 'onHttpException', 'onNetworkError']) {
        const h = harness();
        const outcomes = [];
        const handlers = {
            onError: value => outcomes.push(['validation-or-conflict', value]),
            onCancel: () => outcomes.push(['cancel', null]),
            onHttpException: value => outcomes.push(['http', value.status]),
            onNetworkError: value => outcomes.push(['network', value.message]),
            onSuccess: () => outcomes.push(['success', null]),
        };
        h.start('submit', handlers);
        const callbacks = h.sent[0];
        if (event === 'onError') callbacks[event]({ lock_version: 'The draft changed.' });
        else if (event === 'onHttpException') callbacks[event]({ status: 403 });
        else if (event === 'onNetworkError') callbacks[event](new Error('offline'));
        else callbacks[event]();
        callbacks.onFinish();

        assert.equal(h.state.intent, null, event);
        assert.equal(h.state.activeId, null, event);
        assert.equal(outcomes.length, 1, event);
        assert.notEqual(outcomes[0][0], 'success', event);
    }
});

test('invalidating a changed PA or unmounted workspace suppresses stale request callbacks', () => {
    const h = harness();
    let successCount = 0;
    h.start('save', { onSuccess: () => { successCount += 1; } });
    const oldCallbacks = h.sent[0];
    invalidatePamoPendingRequest(h.state);
    oldCallbacks.onSuccess({});
    oldCallbacks.onFinish();

    assert.equal(successCount, 0);
    assert.equal(h.state.intent, null);
    assert.equal(h.state.activeId, null);
    assert.deepEqual(h.intents, ['save']);
});

test('pending sheet animation is scoped, labelled and static for reduced motion', () => {
    const css = readFileSync(resolve('resources/js/Components/PamoReportPendingAnimation.css'), 'utf8');
    const component = readFileSync(resolve('resources/js/Components/PamoReportPendingAnimation.jsx'), 'utf8');
    const workspace = readFileSync(resolve('resources/js/Components/PamoPambDraftWorkspace.jsx'), 'utf8');

    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
    assert.match(css, /2\.5s/);
    assert.match(css, /\.cds-pamo-pending/);
    assert.doesNotMatch(css, /(^|\s)(html|body|:root)\s*\{/);
    assert.match(component, /role="status"/);
    assert.match(component, /aria-live="polite"/);
    assert.match(component, /Saving draft/);
    assert.match(component, /Submitting report/);
    assert.match(workspace, /serializeCurrentDraft/);
    assert.match(workspace, /lock_version: selected\.lock_version/);
    assert.match(workspace, /busyOverlay=\{pendingIntent \? <PamoReportPendingAnimation/);
});
