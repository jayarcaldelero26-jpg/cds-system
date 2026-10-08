import assert from 'node:assert/strict';
import { File as NodeFile } from 'node:buffer';
import test from 'node:test';
import { objectToFormData } from '@inertiajs/core';
import { pambMovReviewPayload } from '../../resources/js/Utils/pambMovReviewRequest.js';

globalThis.File ??= NodeFile;

test('Ready for Release serializes no empty attachment field in the multipart request', () => {
    const payload = pambMovReviewPayload({
        decision: 'ready_for_release',
        remarks: '',
        attachment: null,
    });
    const requestBody = objectToFormData(payload);

    assert.deepEqual([...requestBody.entries()], [
        ['decision', 'ready_for_release'],
        ['remarks', ''],
    ]);
    assert.equal(requestBody.has('attachment'), false);
});

test('a selected correction reference remains an actual file in the multipart request', () => {
    const selectedFile = new File(['Synthetic correction reference'], 'reference.pdf', { type: 'application/pdf' });
    const payload = pambMovReviewPayload({
        decision: 'needs_correction',
        remarks: 'Please check the signed page.',
        attachment: selectedFile,
    });
    const requestBody = objectToFormData(payload);
    const receivedFile = requestBody.get('attachment');

    assert.equal(receivedFile.name, 'reference.pdf');
    assert.equal(receivedFile.type, 'application/pdf');
    assert.equal(receivedFile.size, selectedFile.size);
    assert.equal(requestBody.get('decision'), 'needs_correction');
    assert.equal(requestBody.get('remarks'), 'Please check the signed page.');
});
