import assert from 'node:assert/strict';
import test from 'node:test';
import {
    custodyContext,
    movActionAvailability,
    movPrerequisiteFor,
    nextSubmissionAction,
    refreshSubmissionSelection,
    reportContextFields,
} from '../../resources/js/Utils/submissionDetailContext.js';

const sourceKeys = ['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans'];
for (const source of sourceKeys) {
    test(`${source}: report type stays separate from activity and module`, () => {
        const fields = new Map(reportContextFields({ source, source_id: 1, module: 'Workflow', activity_name: 'Field visit', document_type: 'Progress Report' }));
        assert.equal(fields.get('Activity'), 'Field visit');
        assert.equal(fields.get('Report / Document Type'), 'Progress Report');
        assert.equal(fields.get('Workflow / Module'), 'Workflow');
        const missing = new Map(reportContextFields({ module: 'Workflow', activity_name: 'Field visit', document_type: '' }));
        assert.equal(missing.has('Report / Document Type'), false);
        assert.equal(missing.get('Activity'), 'Field visit');
    });
}

test('same originating/current office is represented once, while a different current office is retained', () => {
    const row = { target_office: 'CENRO Baganga', routing: { responsible_office: 'CENRO Baganga', current_location: 'CENRO Baganga', responsible_user_category: 'CENRO_CDS_FOCAL' } };
    const custody = custodyContext(row);
    assert.equal(custody.holder, 'CENRO CDS Focal Person');
    assert.equal(custody.officeMatchesOrigin, true);
    assert.equal(reportContextFields(row).filter(([, value]) => value === 'CENRO Baganga').length, 1);
    assert.equal(new Map(reportContextFields(row)).get('Originating / Current Office'), 'CENRO Baganga');
    const moved = { ...row, routing: { ...row.routing, responsible_office: 'PENRO Davao Oriental' } };
    assert.equal(custodyContext(moved).officeMatchesOrigin, false);
    assert.equal(custodyContext(moved).office, 'PENRO Davao Oriental');
    assert.equal(new Map(reportContextFields(moved)).get('Originating Office'), 'CENRO Baganga');
});

test('duplicate activity is omitted and ENGP absent PA/receipt are not invented', () => {
    const fields = new Map(reportContextFields({ module: 'Regular PAMB', activity_name: 'Regular PAMB', document_type: 'Minutes' }, true));
    assert.equal(fields.has('Activity'), false);
    assert.equal(fields.get('Report / Document Type'), 'Minutes');
    assert.equal(fields.has('Protected Area'), false);
    assert.equal(fields.has('PENRO Receipt'), false);
});

test('a fresh selected record wins over an older linked row and refreshes actions/document metadata', () => {
    const old = { source: 'conservation', source_id: 30, current_document: { name: 'old.pdf' }, routing: { actions: [{ key: 'old' }] } };
    const fresh = { ...old, current_document: { name: 'current.pdf' }, routing: { actions: [{ key: 'forward' }] } };
    assert.equal(refreshSubmissionSelection(old, [fresh], old), fresh);
    assert.equal(refreshSubmissionSelection(old, [fresh], old, false).current_document.name, 'current.pdf');
    const clicked = { source: 'bms', source_id: 2 };
    assert.equal(refreshSubmissionSelection(clicked, [fresh, clicked], old), clicked);
});

test('selection never crosses source types with colliding IDs, and revoked selections close', () => {
    const old = { source: 'conservation', source_id: 30 };
    const sibling = { source: 'bms', source_id: 30 };
    assert.equal(refreshSubmissionSelection(old, [sibling], null, false), null);
    assert.equal(refreshSubmissionSelection(old, [], null, false), null);
    const sameSource = { source: 'conservation', source_id: '30' };
    assert.equal(refreshSubmissionSelection(old, [sameSource], null, false), sameSource);
});

test('an explicit new linked-record navigation selects that source while an unchanged link does not override a row click', () => {
    const clicked = { source: 'conservation', source_id: 30 };
    const linked = { source: 'bms', source_id: 30 };
    assert.equal(refreshSubmissionSelection(clicked, [clicked, linked], linked, true, false), clicked);
    assert.equal(refreshSubmissionSelection(clicked, [clicked, linked], linked, true, true), linked);
});

for (const workflow_key of ['regular_pamb', 'special_pamb', 'twc_meetings']) {
    test(`${workflow_key}: pending MOV prerequisite is honest and actor flags remain authoritative`, () => {
        const row = { workflow_key, canonical_custody_applicable: true, mov_url: '/protected/mov', mov_processing: { applicable: true, status_key: 'activity_conducted' }, pamb_action_flags: { can_submit: true, can_review: false, can_release: false }, routing: { current_stage: 'cenro_preparation', next_expected_action: 'Forward to CENRO CDS Chief', actions: [] } };
        assert.equal(nextSubmissionAction(row), 'Submit for Review');
        assert.deepEqual(movActionAvailability(row), { submit: true, review: false, release: false });
        assert.deepEqual(row.routing.actions, []);
        const submitted = { ...row, mov_processing: { ...row.mov_processing, status_key: 'submitted_for_review' } };
        assert.equal(nextSubmissionAction(submitted), 'Forward to CENRO CDS Chief');
        assert.equal(movActionAvailability(submitted).submit, false);
        const noFile = { ...row, mov_url: null };
        assert.equal(movPrerequisiteFor(noFile), 'Upload MOV / report before review');
        assert.equal(movActionAvailability(noFile).submit, false);
        const correction = { ...row, mov_processing: { ...row.mov_processing, status_key: 'needs_correction' } };
        assert.equal(nextSubmissionAction(correction), 'Resubmit for Review');
    });
}

test('an explicit denied flag cannot be overwritten by page-level permissions or global monitoring', () => {
    const row = { mov_url: '/protected/mov', mov_processing: { applicable: true, status_key: 'submitted_for_review' }, pamb_action_flags: { can_submit: false, can_review: false, can_release: false } };
    assert.deepEqual(movActionAvailability(row, { can_submit_mov: true, can_review_mov: true, can_release_mov: true }), { submit: false, review: false, release: false });
});

test('Chief review and canonical release controls preserve existing gate/flag behavior', () => {
    const row = { canonical_custody_applicable: true, routing: { current_stage: 'cenro_chief', next_expected_action: 'Forward' }, mov_processing: { applicable: true, status_key: 'submitted_for_review' }, pamb_action_flags: { can_review: true } };
    assert.equal(nextSubmissionAction(row), 'Review MOV / report');
    assert.deepEqual(movActionAvailability(row), { submit: false, review: true, release: false });
    const release = { ...row, mov_processing: { ...row.mov_processing, status_key: 'ready_for_release' }, pamb_action_flags: { can_release: true } };
    assert.equal(movActionAvailability(release, {}, false).release, true);
    assert.equal(movActionAvailability(release, {}, true).release, false);
    assert.equal(movActionAvailability({ ...release, cenro_release_applicable: false }).release, false);
});

test('direct-to-PENRO and non-meeting report actions are not replaced by a MOV prerequisite', () => {
    const direct = { canonical_custody_applicable: true, mov_processing: { applicable: true, status_key: 'activity_conducted' }, routing: { current_stage: 'transit_to_penro_records', next_expected_action: 'Receive' } };
    assert.equal(nextSubmissionAction(direct), 'Receive');
    const bms = { mov_processing: { applicable: false }, routing: { current_stage: 'cenro_preparation', next_expected_action: 'Forward' } };
    assert.equal(nextSubmissionAction(bms), 'Forward');
});
