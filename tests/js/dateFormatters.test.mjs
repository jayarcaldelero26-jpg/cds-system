import assert from 'node:assert/strict';
import { dateOnlyTimestamp, formatReportDate, formatReportTime, formatReportValue, parseDateOnly } from '../../resources/js/Utils/dateFormatters.js';
import { localDateInputValue } from '../../resources/js/Utils/dateInput.js';

const parsed = parseDateOnly('2026-08-29');
assert.ok(parsed);
assert.equal(parsed.getFullYear(), 2026);
assert.equal(parsed.getMonth(), 7);
assert.equal(parsed.getDate(), 29);
assert.equal(formatReportDate('2026-08-29'), 'August 29, 2026');
assert.equal(dateOnlyTimestamp('2026-08-29') < dateOnlyTimestamp('2026-08-30'), true);
assert.equal(parseDateOnly('2026-02-30'), null);
assert.equal(formatReportDate(null), 'Not recorded');
assert.equal(formatReportValue('August 3, 2026'), 'August 3, 2026');
assert.equal(formatReportValue(null), 'Not provided');
assert.equal(formatReportValue(0), 0);
assert.equal(formatReportTime(null), 'Not recorded');
assert.equal(formatReportTime('00:00'), '12:00 AM');
assert.equal(formatReportTime('13:45:00'), '01:45 PM');

const localDate = new Date(2026, 7, 29, 23, 59, 59);
assert.equal(localDateInputValue(localDate), '2026-08-29');

console.log('date-only formatter tests passed');
