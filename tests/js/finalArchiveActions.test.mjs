import assert from 'node:assert/strict';
import test from 'node:test';
import { interceptors } from '@inertiajs/core';
import {
    postFinalArchive,
    postFinalArchiveRetry,
    postFinalReceipt,
    registerFinalArchivePageGuard,
} from '../../resources/js/Utils/finalArchiveActions.mjs';

function fixture(data = {}, processing = false) {
    const calls = [];
    const form = {
        data,
        processing,
        post: (...args) => { calls.push(args); return 'posted'; },
    };
    const routeCalls = [];
    const routeFor = (name, parameters) => {
        routeCalls.push([name, parameters]);
        return `/route/${name}`;
    };
    return { form, calls, routeFor, routeCalls };
}

test('receipt callback posts the selected source record and preserves date/time form state', () => {
    const { form, calls, routeFor, routeCalls } = fixture({ physically_received_at: '2026-10-15T14:30' });
    let prevented = false;
    const onSuccess = () => {};
    const result = postFinalReceipt({ preventDefault: () => { prevented = true; } }, form, { source: 'bms', source_id: 42 }, routeFor, onSuccess);

    assert.equal(result, 'posted');
    assert.equal(prevented, true);
    assert.deepEqual(routeCalls, [['final-archives.receipts.store', ['bms', 42]]]);
    assert.equal(calls[0][0], '/route/final-archives.receipts.store');
    assert.equal(calls[0][1].onSuccess, onSuccess);
    assert.deepEqual(form.data, { physically_received_at: '2026-10-15T14:30' });
});

test('archive callback posts the full selected file set as FormData with confirmation state', () => {
    const files = [{ name: 'signed-part-1.pdf' }, { name: 'signed-part-2.pdf' }];
    const data = { intent_key: 'intent-uuid', final_files: files, signature_confirmation: true };
    const { form, calls, routeFor, routeCalls } = fixture(data);
    let prevented = false;
    const onSuccess = () => {};
    const result = postFinalArchive({ preventDefault: () => { prevented = true; } }, form, { source: 'imea', source_id: 9 }, routeFor, onSuccess);

    assert.equal(result, 'posted');
    assert.equal(prevented, true);
    assert.deepEqual(routeCalls, [['final-archives.store', ['imea', 9]]]);
    assert.equal(calls[0][0], '/route/final-archives.store');
    assert.equal(calls[0][1].forceFormData, true);
    assert.equal(calls[0][1].onSuccess, onSuccess);
    assert.deepEqual(form.data, data);
});

test('archive request transport errors are handled without clearing the receipt or selected files', () => {
    const files = [{ name: 'signed-part-1.pdf' }, { name: 'signed-part-2.pdf' }];
    const data = { physically_received_at: '2026-10-16T18:29', intent_key: 'intent-uuid', final_files: files, signature_confirmation: true };
    const { form, calls, routeFor } = fixture(data);
    const failures = [];
    let successCount = 0;

    postFinalArchive(
        { preventDefault() {} },
        form,
        { source: 'bms', source_id: 8 },
        routeFor,
        () => { successCount++; },
        failure => failures.push(failure),
    );

    const options = calls[0][1];
    assert.equal(options.forceFormData, true);
    assert.equal(options.onHttpException({ status: 500 }), false);
    assert.equal(options.onNetworkError(new Error('malformed Inertia page')), false);
    assert.equal(options.onError, undefined);
    assert.equal(options.onFinish, undefined);

    assert.deepEqual(failures, [
        { kind: 'http', status: 500 },
        { kind: 'network' },
    ]);
    assert.equal(successCount, 0);
    assert.equal(form.processing, false);
    assert.deepEqual(form.data, data);
});

test('an incomplete selected Archives Inertia page is rejected before it can be applied', async () => {
    const stopGuard = registerFinalArchivePageGuard('/submission-tracking/archives');
    const visit = { url: new URL('https://cds-system.test/submission-tracking/archives?record=8&source=bms') };
    const validResponse = {
        status: 200,
        data: { component: 'SubmissionTracking/Archives', props: { selected: { source: 'bms', source_id: 8 } }, url: '/submission-tracking/archives?record=8&source=bms', version: 'test' },
    };
    const invalidResponse = {
        status: 200,
        data: { component: 'Errors/500', props: {}, version: 'test' },
    };

    try {
        assert.equal(await interceptors.processResponse(visit, validResponse), validResponse);
        await assert.rejects(
            interceptors.processResponse(visit, invalidResponse),
            error => error?.name === 'InvalidFinalArchivePageResponseError',
        );
        assert.equal(invalidResponse.data.url, undefined);
    } finally {
        stopGuard();
    }
});

test('archive form blocks a duplicate click while its request is already active', () => {
    const { form, calls } = fixture({ intent_key: 'intent-uuid', final_files: [{ name: 'signed.pdf' }], signature_confirmation: true }, true);
    let prevented = false;
    assert.equal(postFinalArchive({ preventDefault: () => { prevented = true; } }, form, { source: 'bms', source_id: 8 }, () => '/unused', () => {}), false);
    assert.equal(prevented, true);
    assert.equal(calls.length, 0);
});

test('retry callback targets the current manifest and skips invalid or in-flight retries', () => {
    const onSuccess = () => {};
    const ready = fixture({}, false);
    assert.equal(postFinalArchiveRetry(ready.form, { latest_manifest: { id: 15, status: 'FAILED', retry_available: true } }, ready.routeFor, onSuccess), 'posted');
    assert.deepEqual(ready.routeCalls, [['final-archives.retry', 15]]);
    assert.equal(ready.calls[0][1].onSuccess, onSuccess);

    const inFlight = fixture({}, true);
    assert.equal(postFinalArchiveRetry(inFlight.form, { latest_manifest: { id: 15, status: 'FAILED', retry_available: true } }, inFlight.routeFor, onSuccess), false);
    assert.equal(inFlight.calls.length, 0);

    const activeClaim = fixture({}, false);
    assert.equal(postFinalArchiveRetry(activeClaim.form, { latest_manifest: { id: 15, status: 'PROCESSING', processing_claim_active: true, retry_available: false } }, activeClaim.routeFor, onSuccess), false);
    assert.equal(activeClaim.calls.length, 0);

    const expiredClaim = fixture({}, false);
    assert.equal(postFinalArchiveRetry(expiredClaim.form, { latest_manifest: { id: 15, status: 'PROCESSING', processing_claim_active: false, retry_available: true } }, expiredClaim.routeFor, onSuccess), 'posted');

    const missingManifest = fixture();
    assert.equal(postFinalArchiveRetry(missingManifest.form, { latest_manifest: null }, missingManifest.routeFor, onSuccess), false);
    assert.equal(missingManifest.calls.length, 0);
});

test('receipt and archive callbacks do not post without a valid selected record identity', () => {
    for (const submit of [postFinalReceipt, postFinalArchive]) {
        const { form, calls } = fixture();
        let prevented = false;
        assert.equal(submit({ preventDefault: () => { prevented = true; } }, form, { source: 'unsupported', source_id: 0 }, () => '/unused', () => {}), false);
        assert.equal(prevented, true);
        assert.equal(calls.length, 0);
    }
});
