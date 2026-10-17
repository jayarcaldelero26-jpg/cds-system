import assert from 'node:assert/strict';
import test from 'node:test';
import { defaultManilaReceiptInput, earliestManilaReceiptInput, manilaDateTimeInput } from '../../resources/js/Utils/finalArchiveReceiptTime.mjs';

test('receipt input uses Asia/Manila wall time and rounds release seconds upward', () => {
    const release = '2026-10-16T08:14:45+08:00';
    assert.equal(earliestManilaReceiptInput(release), '2026-10-16T08:15');
    assert.equal(defaultManilaReceiptInput(release, null, new Date('2026-10-16T00:00:00Z')), '2026-10-16T08:15');
    assert.equal(manilaDateTimeInput(new Date('2026-10-16T00:00:00Z')), '2026-10-16T08:00');
});

test('existing receipt date is preserved and later current times remain the default', () => {
    const release = '2026-10-16T08:14:00+08:00';
    assert.equal(defaultManilaReceiptInput(release, '2026-10-16T09:22:00+08:00', new Date('2026-10-17T00:00:00Z')), '2026-10-16T09:22');
    assert.equal(defaultManilaReceiptInput(release, null, new Date('2026-10-17T00:00:40Z')), '2026-10-17T08:00');
});
