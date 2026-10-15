import assert from 'node:assert/strict';
import test from 'node:test';
import { formatDateKey, formatDisplayDate, parseDateOnly } from '../../resources/js/Components/Calendar/calendarUtils.js';
import { formatReportDate } from '../../resources/js/Utils/dateFormatters.js';

test('PAMO compact date labels say Sept while preserving ISO values and full month formats', () => {
    const stored = '2026-09-08';
    assert.equal(formatDisplayDate(stored), 'Sep 08, 2026');
    assert.equal(formatDisplayDate(stored, { compactSeptember: true }), 'Sept 08, 2026');
    assert.equal(formatDateKey(parseDateOnly(stored)), stored);
    assert.equal(formatReportDate('2026-09-24'), 'September 24, 2026');
});
