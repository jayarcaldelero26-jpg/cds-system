import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const officeTatService = readFileSync(
    new URL('../../app/Services/OfficeTatAnalyticsService.php', import.meta.url),
    'utf8',
);
const officeTatPage = readFileSync(
    new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url),
    'utf8',
);

test('historical date-gap bases stay separate from live event-derived office TAT', () => {
    assert.match(officeTatService, /event_derived_tat_completed_documents/);
    assert.match(officeTatService, /tat_average_elapsed_seconds/);
    assert.match(officeTatService, /manual_historical_preparation_average_elapsed_calendar_days/);
    assert.match(officeTatService, /manual_historical_processing_average_elapsed_calendar_days/);
    assert.match(officeTatService, /manual_historical_handoff_average_elapsed_calendar_days/);
    assert.match(officeTatPage, /Turnaround Time and Historical Date Gap Ranking/);
    assert.match(officeTatPage, /Live routing event average/);
    assert.match(officeTatPage, /Historical date gap/);
    assert.match(officeTatPage, /Original CENRO Release → Original PENRO Receipt/);
    assert.match(officeTatPage, /Eligible gap samples/);
    assert.match(officeTatPage, /Asia\/Manila calendar days/);
    assert.match(officeTatPage, /liveCompleted/);
    assert.match(officeTatPage, /historicalCompleted/);
});
