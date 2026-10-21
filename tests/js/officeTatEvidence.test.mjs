import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const officeTatService = readFileSync(new URL('../../app/Services/OfficeTatAnalyticsService.php', import.meta.url), 'utf8');
const officeTatPage = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/Index.jsx', import.meta.url), 'utf8');
const reportPerformance = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ComplianceFirstAssessments.jsx', import.meta.url), 'utf8');
const ranking = readFileSync(new URL('../../resources/js/Pages/Admin/OfficeTat/ranking.mjs', import.meta.url), 'utf8');

test('normal Office TAT analytics and ranking include eligible live routing evidence only', () => {
    assert.match(officeTatService, /addHistoricalEvidence\(\$user, \$category, \$periodStart, \$cohortEndExclusive, \$filters, \$rows\)/);
    assert.match(officeTatService, /if \(! in_array\(\(\$filters\['evidence_type'\] \?\? 'all'\), \['historical', 'all'\], true\)\) return;/);
    assert.match(officeTatService, /event_derived_tat_completed_documents/);
    assert.match(officeTatService, /tat_average_elapsed_seconds/);
    assert.match(officeTatPage, /rankTatRows\(visibleRows, 'live'\)/);
    assert.doesNotMatch(officeTatPage, /historicalCompleted|Historical date gap|Original CENRO Release|Eligible gap samples/);
    assert.doesNotMatch(ranking, /HISTORICAL_GAPS|manual_historical_|historicalReports/);
});

test('Average Office Processing Time exposes source references, accepted endpoints, timestamps and duration units', () => {
    assert.match(officeTatService, /private function tatSourceReference\(string \$source, int \$sourceId\)/);
    assert.match(officeTatService, /accepted_receipt_at/);
    assert.match(officeTatService, /accepted_handoff_at/);
    assert.match(officeTatPage, /Elapsed calendar time from an office/);
    assert.match(officeTatPage, /Accepted receipt timestamp/);
    assert.match(officeTatPage, /Next accepted handoff timestamp/);
    assert.match(officeTatPage, /Elapsed calendar time/);
    assert.match(ranking, /days|hours|minutes|seconds/);
});

test('excluded live report details include a source reference and the reason for exclusion', () => {
    assert.match(reportPerformance, /Source reference/);
    assert.match(reportPerformance, /item\.issue/);
    assert.match(reportPerformance, /Source deadline/);
    assert.match(reportPerformance, /after selected date/);
});
