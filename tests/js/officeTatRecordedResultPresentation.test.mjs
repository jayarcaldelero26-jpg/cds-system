import assert from 'node:assert/strict';
import test from 'node:test';
import { recordedResultPresentation } from '../../resources/js/Pages/Admin/OfficeTat/recordedResultPresentation.mjs';

test('labels fully received future-due records and shows the earliest deadline', () => {
    const result = recordedResultPresentation({
        score_status: 'No due reports',
        activity_count: 2,
        received_activity_count: 2,
        not_yet_due_count: 2,
        due_count: 0,
        incomplete_date_count: 0,
        incomplete_evidence_count: 0,
        obligation_details: [
            { deadline: '2026-10-15' },
            { deadline: '2026-10-20' },
        ],
    }, '2026-10-11');

    assert.deepEqual(result, { label: 'Received — not yet due', nextDeadline: '2026-10-15' });
});

test('keeps no due reports when no activity was received', () => {
    const result = recordedResultPresentation({
        score_status: 'No due reports',
        activity_count: 1,
        received_activity_count: 0,
        not_yet_due_count: 1,
        due_count: 0,
    }, '2026-10-11');

    assert.deepEqual(result, { label: 'No due reports', nextDeadline: null });
});

test('does not replace no due reports when dates or evidence are incomplete', () => {
    for (const patch of [
        { incomplete_date_count: 1 },
        { incomplete_evidence_count: 1 },
    ]) {
        const result = recordedResultPresentation({
            score_status: 'No due reports',
            activity_count: 1,
            received_activity_count: 1,
            not_yet_due_count: 1,
            due_count: 0,
            ...patch,
        }, '2026-10-11');

        assert.equal(result.label, 'No due reports');
        assert.equal(result.nextDeadline, null);
    }
});

test('keeps scored rows unchanged and allows a missing future deadline', () => {
    assert.deepEqual(recordedResultPresentation({
        score_status: 'Scored',
        activity_count: 1,
        received_activity_count: 1,
        not_yet_due_count: 0,
        due_count: 1,
    }, '2026-10-11'), { label: 'Scored', nextDeadline: null });

    assert.deepEqual(recordedResultPresentation({
        score_status: 'No due reports',
        activity_count: 1,
        received_activity_count: 1,
        not_yet_due_count: 1,
        due_count: 0,
    }, '2026-10-11'), { label: 'Received — not yet due', nextDeadline: null });
});
