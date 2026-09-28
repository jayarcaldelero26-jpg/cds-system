import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    availableIncomingActionTabs,
    filterIncomingRowsByAction,
    reconcileIncomingActionTab,
} from '../../resources/js/Utils/submissionTrackingQueues.js';

const actionOrder = ['receive', 'forward', 'release', 'decision', 'correction'];

test('a new Incoming view shows all responsible-user action categories by default', () => {
    // Mirrors the current Baganga-scoped server projection: five ordinary
    // forwards and three PAMB submissions awaiting focal-person review.
    const authorizedIncomingRows = [
        ...Array.from({ length: 5 }, (_, index) => ({
            source: index === 0 ? 'bms' : 'conservation',
            source_id: index + 1,
            incoming_action_category: 'forward',
        })),
        ...['regular_pamb', 'special_pamb', 'twc_meetings'].map((workflow, index) => ({
            source: 'conservation',
            source_id: index + 10,
            workflow,
            incoming_action_category: 'decision',
        })),
    ];

    const available = availableIncomingActionTabs(authorizedIncomingRows, actionOrder);
    const initialSelection = reconcileIncomingActionTab(null, available);
    const visibleRows = filterIncomingRowsByAction(authorizedIncomingRows, initialSelection);

    assert.deepEqual(available, ['forward', 'decision']);
    assert.equal(initialSelection, null);
    assert.equal(visibleRows.length, 8);
    assert.equal(visibleRows.filter((row) => row.incoming_action_category === 'decision').length, 3);
});

test('an explicitly selected action filter narrows rows, while a stale filter clears to all actions', () => {
    const rows = [
        { source: 'bms', source_id: 1, incoming_action_category: 'forward' },
        { source: 'conservation', source_id: 2, incoming_action_category: 'decision' },
    ];

    assert.equal(filterIncomingRowsByAction(rows, 'forward').length, 1);
    assert.equal(reconcileIncomingActionTab('receive', ['forward', 'decision']), null);
    assert.equal(filterIncomingRowsByAction(rows, reconcileIncomingActionTab('receive', ['forward', 'decision'])).length, 2);
});
