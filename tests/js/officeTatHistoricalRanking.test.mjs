import test from 'node:test';
import assert from 'node:assert/strict';
import { rankTatRows, weightedAverageSeconds } from '../../resources/js/Pages/Admin/OfficeTat/ranking.mjs';

const historicalRow = (office, count, totalDays) => ({
    office,
    office_category: 'PAMO',
    historical_report_count: count,
    manual_historical_processing_completed_documents: count,
    manual_historical_processing_elapsed_calendar_days_total: totalDays,
    manual_historical_processing_average_elapsed_calendar_days: count ? totalDays / count : null,
    manual_historical_processing_missing_evidence: 0,
    manual_historical_processing_invalid_evidence: 0,
    manual_historical_processing_unsupported: 0,
    manual_historical_processing_not_applicable: 0,
});

test('historical date-gap ranks keep zero days, exact ties, unrounded order, and document-weighted means', () => {
    const rows = rankTatRows([
        historicalRow('Office C', 1, 2),
        historicalRow('Office B', 2, 0),
        historicalRow('Office A', 1, 0),
    ], 'historical', 'processing');

    assert.deepEqual(rows.map(row => [row.office, row.rank]), [
        ['Office A', 1],
        ['Office B', 1],
        ['Office C', 3],
    ]);
    assert.equal(rows[0].averageSeconds, 0);
    assert.equal(weightedAverageSeconds(rows), 0.5 * 86400);

    const precise = rankTatRows([
        { ...historicalRow('Slower', 1, 1.00000002) },
        { ...historicalRow('Faster', 1, 1.00000001) },
    ], 'historical', 'processing');
    assert.deepEqual(precise.map(row => row.rank), [1, 2]);
});

test('adding historical basis fields leaves the live event ranking contract unchanged', () => {
    const live = rankTatRows([{
        office: 'CENRO Baganga',
        tat_average_elapsed_seconds: 172800,
        tat_elapsed_seconds_total: 172800,
        event_derived_tat_completed_documents: 1,
        pending: 0,
        tat_missing_evidence: 0,
        manual_historical_processing_average_elapsed_calendar_days: 7,
        manual_historical_processing_completed_documents: 1,
    }], 'live');

    assert.equal(live[0].averageSeconds, 172800);
    assert.equal(live[0].completedDocuments, 1);
    assert.equal(weightedAverageSeconds(live), 172800);
});
