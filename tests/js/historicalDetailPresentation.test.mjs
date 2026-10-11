import test from 'node:test';
import assert from 'node:assert/strict';
import { historicalGapCard, historicalSourceModuleHref } from '../../resources/js/Pages/Admin/Settings/historicalDetailPresentation.mjs';

test('Open Source requires the protected source-plus-record identity and uses its resolved module URL', () => {
    assert.equal(historicalSourceModuleHref({ source: 'conservation', source_id: 38, module_url: '/conservation-reports/regular_pamb' }), '/conservation-reports/regular_pamb');
    assert.equal(historicalSourceModuleHref({ source: 'conservation', source_id: 0, module_url: '/conservation-reports/regular_pamb' }), null);
    assert.equal(historicalSourceModuleHref({ source: 'conservation', source_id: 38 }), null);
    assert.equal(historicalSourceModuleHref({ source: '', source_id: 38, module_url: '/conservation-reports/regular_pamb' }), null);
});

test('historical date-gap detail labels show calendar units and preserve zero, missing, unsupported, and invalid evidence', () => {
    assert.deepEqual(historicalGapCard({
        start_label: 'Date Accomplished', end_label: 'Original CENRO Release',
        start_date: '2026-03-18', end_date: '2026-03-25', status: 'complete', elapsed_calendar_days: 7,
    }), {
        title: 'Date Accomplished → Original CENRO Release',
        value: '7 calendar days',
        startDate: '2026-03-18',
        endDate: '2026-03-25',
        reason: '',
    });
    assert.equal(historicalGapCard({ status: 'complete', elapsed_calendar_days: 0 }).value, '0 calendar days');
    assert.equal(historicalGapCard({ status: 'missing', reason: 'No original receipt date.' }).value, 'Missing evidence');
    assert.equal(historicalGapCard({ status: 'unsupported' }).value, 'Not supported for this source');
    assert.equal(historicalGapCard({ status: 'invalid' }).value, 'Invalid evidence');
});
