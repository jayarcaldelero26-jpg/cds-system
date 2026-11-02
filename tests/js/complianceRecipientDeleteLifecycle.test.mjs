import test from 'node:test';
import assert from 'node:assert/strict';
import { cancelRecipientDelete, executeRecipientDelete } from '../../resources/js/Pages/ComplianceAlerts/recipientDeleteLifecycle.mjs';

const action = { type: 'delete-recipient', recipient: { id: 17, recipient_name: 'Fixture mapping' } };

function makeFixture({ selected = action.recipient, confirmed = action, editingRecipientId = null, sending = false } = {}) {
    const state = { selected, confirmed, actionError: 'stale error', sending };
    const actionInFlight = { current: false };
    const requests = [];
    let resetCount = 0;
    const set = key => update => { state[key] = typeof update === 'function' ? update(state[key]) : update; };
    const dependencies = {
        action,
        actionInFlight,
        route: (name, id) => `/${name}/${id}`,
        router: { delete: (url, options) => requests.push({ url, options }) },
        setActionError: set('actionError'),
        setSending: set('sending'),
        setSelectedRecipient: set('selected'),
        setConfirmAction: set('confirmed'),
        editingRecipientId,
        resetRecipient: () => { resetCount += 1; },
    };
    return { state, actionInFlight, requests, dependencies, resetCount: () => resetCount };
}

test('successful delete closes only the matching dialogs and clears matching editor state', () => {
    const fixture = makeFixture({ editingRecipientId: action.recipient.id });

    assert.equal(executeRecipientDelete(fixture.dependencies), true);
    assert.equal(fixture.requests.length, 1);
    assert.equal(fixture.requests[0].url, '/compliance-alerts.recipients.destroy/17');
    assert.equal(fixture.requests[0].options.preserveScroll, true);

    fixture.requests[0].options.onSuccess();
    assert.equal(fixture.state.selected, null);
    assert.equal(fixture.state.confirmed, null);
    assert.equal(fixture.state.actionError, '');
    assert.equal(fixture.resetCount(), 1);

    fixture.requests[0].options.onFinish();
    assert.equal(fixture.state.sending, false);
    assert.equal(fixture.actionInFlight.current, false);
});

test('a used-mapping rejection keeps the selected mapping and confirmation open with the server error', () => {
    const fixture = makeFixture();

    executeRecipientDelete(fixture.dependencies);
    fixture.requests[0].options.onError({ recipient: 'This mapping is referenced by notification history.' });

    assert.equal(fixture.state.selected, action.recipient);
    assert.equal(fixture.state.confirmed, action);
    assert.equal(fixture.state.actionError, 'This mapping is referenced by notification history.');
    assert.equal(fixture.resetCount(), 0);
    fixture.requests[0].options.onFinish();
    assert.equal(fixture.actionInFlight.current, false);
});

test('HTTP and network failures preserve the details context and show actionable errors', () => {
    for (const [callback, expected] of [
        ['onHttpException', 'The server could not complete this action. Your current record remains open; please review it and try again.'],
        ['onNetworkError', 'The request did not reach the server. Your current record remains open; check the connection and try again.'],
    ]) {
        const fixture = makeFixture();
        executeRecipientDelete(fixture.dependencies);
        fixture.requests[0].options[callback]();
        assert.equal(fixture.state.selected, action.recipient);
        assert.equal(fixture.state.confirmed, action);
        assert.equal(fixture.state.actionError, expected);
        fixture.requests[0].options.onFinish();
    }
});

test('cancel closes without a request and stays disabled while the request is processing', () => {
    const fixture = makeFixture();
    const cancel = sending => cancelRecipientDelete({
        sending,
        setActionError: fixture.dependencies.setActionError,
        setConfirmAction: fixture.dependencies.setConfirmAction,
    });

    assert.equal(cancel(true), false);
    assert.equal(fixture.state.confirmed, action);
    assert.equal(cancel(false), true);
    assert.equal(fixture.state.confirmed, null);
    assert.equal(fixture.state.actionError, '');
    assert.equal(fixture.requests.length, 0);
});

test('pending delete rejects rapid duplicate confirms and unlocks after finish', () => {
    const fixture = makeFixture();

    assert.equal(executeRecipientDelete(fixture.dependencies), true);
    assert.equal(executeRecipientDelete(fixture.dependencies), false);
    assert.equal(fixture.requests.length, 1);
    fixture.requests[0].options.onFinish();
    assert.equal(executeRecipientDelete(fixture.dependencies), true);
    assert.equal(fixture.requests.length, 2);
});
