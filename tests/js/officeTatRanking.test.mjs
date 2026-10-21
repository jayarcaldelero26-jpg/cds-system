import assert from 'node:assert/strict';
import test from 'node:test';
import { formatDuration, rankTatRows, weightedAverageSeconds } from '../../resources/js/Pages/Admin/OfficeTat/ranking.mjs';

const day = 86400;

test('average TAT ranking sorts precise durations, shares exact ranks, and leaves empty offices unranked', () => {
    const rows = rankTatRows([
        { office: 'Zulu', tat_average_elapsed_seconds: day + 100, event_derived_tat_completed_documents: 1 },
        { office: 'Bravo', tat_average_elapsed_seconds: day + 50, event_derived_tat_completed_documents: 1 },
        { office: 'Alpha', tat_average_elapsed_seconds: day + 50, event_derived_tat_completed_documents: 2 },
        { office: 'No records', tat_average_elapsed_seconds: null, event_derived_tat_completed_documents: 0 },
    ]);

    assert.deepEqual(rows.map(row => [row.office, row.rank]), [
        ['Alpha', 1], ['Bravo', 1], ['Zulu', 3], ['No records', null],
    ]);
    assert.equal(rows[0].averageSeconds, day + 50);

    const nearTies = rankTatRows([
        { office: 'Slightly faster', tat_average_elapsed_seconds: 100, event_derived_tat_completed_documents: 1 },
        { office: 'Slightly slower', tat_average_elapsed_seconds: 100.0000005, event_derived_tat_completed_documents: 1 },
    ]);
    assert.deepEqual(nearTies.map(row => row.rank), [1, 2]);
});

test('overall average weights office durations by completed-document count', () => {
    const rows = rankTatRows([
        { office: 'One day office', tat_elapsed_seconds_total: day, tat_average_elapsed_seconds: day, event_derived_tat_completed_documents: 1 },
        { office: 'Three day office', tat_elapsed_seconds_total: 9 * day, tat_average_elapsed_seconds: 3 * day, event_derived_tat_completed_documents: 3 },
    ]);

    assert.equal(weightedAverageSeconds(rows), 2.5 * day);
    assert.equal(weightedAverageSeconds(rankTatRows([{ office: 'Empty', tat_average_elapsed_seconds: null, event_derived_tat_completed_documents: 0 }])), null);
});

test('duration formatting preserves seconds and accepts a valid zero-duration measurement', () => {
    assert.equal(formatDuration(0), '0 seconds');
    assert.equal(formatDuration(1.5), '1.5 seconds');
    assert.equal(formatDuration(6 * day + 10 * 3600 + 54 * 60 + 46), '6 days 10 hours 54 minutes 46 seconds');
    assert.equal(formatDuration(null), 'N/A');
});

test('historical source fields do not create a live interval or rank', () => {
    const [row] = rankTatRows([{
        office: 'APL-PAMO', tat_average_elapsed_seconds: null, event_derived_tat_completed_documents: 0,
        historical_report_count: 1,
        manual_historical_handoff_average_elapsed_calendar_days: null,
        manual_historical_handoff_completed_documents: 0,
    }]);

    assert.equal(row.completedDocuments, 0);
    assert.equal(row.averageSeconds, null);
    assert.equal(row.rank, null);
    assert.equal('historicalReports' in row, false);
    assert.equal(weightedAverageSeconds([row]), null);
});
