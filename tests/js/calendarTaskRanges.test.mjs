import assert from 'node:assert/strict';
import test from 'node:test';
import { groupTasksByDate, groupTasksByMonth, taskDateKeys } from '../../resources/js/Components/Calendar/calendarTaskRanges.mjs';
import { currentDraft, serializeTimeValue } from '../../resources/js/Utils/timePicker.js';

test('all-day and date-only task ranges include both calendar dates and clip to the visible grid', () => {
    const task = { id: 7, scheduled_date: '2026-10-30', scheduled_end_date: '2026-11-02', is_all_day: true };
    assert.deepEqual(taskDateKeys(task), ['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02']);
    assert.deepEqual(taskDateKeys(task, '2026-11-01', '2026-11-30'), ['2026-11-01', '2026-11-02']);
    assert.deepEqual(groupTasksByDate([task], '2026-11-01', '2026-11-30')['2026-11-02'], [task]);
});

test('a timed range ending at midnight does not occupy the next date', () => {
    const task = { id: 8, scheduled_date: '2026-11-02', scheduled_end_date: '2026-11-04', is_all_day: false, start_time: '21:00', end_time: '00:00' };
    assert.deepEqual(taskDateKeys(task), ['2026-11-02', '2026-11-03']);
    assert.equal(groupTasksByDate([task], '2026-11-04', '2026-11-30')['2026-11-04'], undefined);
});

test('single-date tasks and timed tasks without a complete clock pair stay date based', () => {
    assert.deepEqual(taskDateKeys({ id: 9, scheduled_date: '2026-11-12', is_all_day: false, start_time: '09:00' }), ['2026-11-12']);
    assert.deepEqual(taskDateKeys({ id: 10, scheduled_date: '2026-11-12', scheduled_end_date: '2026-11-13', is_all_day: true }), ['2026-11-12', '2026-11-13']);
});

test('year month groups include each task once in every occupied month', () => {
    const crossing = { id: 11, scheduled_date: '2026-01-31', scheduled_end_date: '2026-03-02', is_all_day: true };
    const midnight = { id: 12, scheduled_date: '2026-03-30', scheduled_end_date: '2026-04-01', is_all_day: false, start_time: '22:00', end_time: '00:00' };
    const grouped = groupTasksByMonth([crossing, crossing, midnight], 2026);
    assert.deepEqual(Object.keys(grouped), ['01', '02', '03']);
    assert.deepEqual(grouped['01'], [crossing]);
    assert.deepEqual(grouped['02'], [crossing]);
    assert.deepEqual(grouped['03'], [crossing, midnight]);
});

test('Now time drafts preserve the selected task date, including an empty time field', () => {
    const fixedNow = new Date('2026-11-01T04:27:00.000Z');
    const selected = currentDraft('2026-11-03T09:15', fixedNow);
    assert.equal(selected.date, '2026-11-03');
    assert.equal(selected.hasDate, true);
    assert.match(serializeTimeValue(selected), /^2026-11-03T/);

    const empty = currentDraft('', fixedNow, '2026-11-04');
    assert.equal(empty.date, '2026-11-04');
    assert.equal(empty.hasDate, true);
    assert.match(serializeTimeValue(empty), /^2026-11-04T/);
});
