import assert from 'node:assert/strict';
import { currentDraft, draftFromValue, formatTimeDisplay, initialDraft, localDateTimeInputValue, serializeTimeValue } from '../../resources/js/Utils/timePicker.js';

assert.equal(formatTimeDisplay('08:06'), '08:06 AM');
assert.equal(formatTimeDisplay('2026-08-03T20:06'), '08:06 PM');
assert.equal(formatTimeDisplay({ hour: 8, minute: 6, period: 'PM' }), '08:06 PM');
assert.equal(serializeTimeValue({ date: '2026-08-03', hasDate: true, hour: 8, minute: 6, period: 'AM' }), '2026-08-03T08:06');
assert.equal(serializeTimeValue({ date: '2026-08-03', hasDate: true, hour: 8, minute: 6, period: 'PM' }), '2026-08-03T20:06');
assert.equal(serializeTimeValue({ hour: 8, minute: 6, period: 'AM' }), '08:06');
assert.equal(serializeTimeValue({ hour: 12, minute: 0, period: 'AM' }), '00:00');
assert.equal(serializeTimeValue({ hour: 12, minute: 0, period: 'PM' }), '12:00');
assert.deepEqual(draftFromValue('2026-08-03 14:35:00'), { date: '2026-08-03', hasDate: true, hour: 2, minute: 35, period: 'PM' });
assert.deepEqual(currentDraft('2026-08-03T14:35', new Date('2026-08-29T13:07:00Z')), { date: '2026-08-03', hasDate: true, hour: 9, minute: 7, period: 'PM' });
assert.deepEqual(currentDraft('', new Date('2026-09-03T00:23:00Z')), { date: '', hasDate: false, hour: 8, minute: 23, period: 'AM' });
assert.deepEqual(initialDraft('', new Date('2026-09-04T00:23:00Z')), { date: '', hasDate: false, hour: 8, minute: 23, period: 'AM' });
assert.deepEqual(initialDraft('', new Date('2026-09-04T12:23:00Z')), { date: '', hasDate: false, hour: 8, minute: 23, period: 'PM' });
assert.deepEqual(initialDraft('2026-09-03T14:35', new Date('2026-09-04T00:23:00Z')), { date: '2026-09-03', hasDate: true, hour: 2, minute: 35, period: 'PM' });
assert.equal(localDateTimeInputValue('2026-09-03T00:35:00+08:00'), '2026-09-03T00:35');

console.log('premium time picker utility tests passed');
