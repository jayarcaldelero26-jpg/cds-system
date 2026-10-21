import assert from 'node:assert/strict';
import test from 'node:test';
import { rankTatRows, weightedAverageSeconds } from '../../resources/js/Pages/Admin/OfficeTat/ranking.mjs';

test('historical date-gap fields do not create an Average Office Processing Time rank', () => {
    const [row] = rankTatRows([{
        office: 'PAMO A',
        historical_report_count: 1,
        manual_historical_processing_average_elapsed_calendar_days: 7,
        manual_historical_processing_elapsed_calendar_days_total: 7,
        manual_historical_processing_completed_documents: 1,
    }]);

    assert.equal(row.completedDocuments, 0);
    assert.equal(row.averageSeconds, null);
    assert.equal(row.rank, null);
    assert.equal(weightedAverageSeconds([row]), null);
});
