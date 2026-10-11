import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/js/Pages/Engp/Index.jsx', import.meta.url),
    'utf8',
);

test('ENGP historical details show original evidence without presenting it as live release events', () => {
    const detailsStart = source.indexOf('function Details({ record })');
    const detailsEnd = source.indexOf('\nfunction Detail(', detailsStart);
    assert.ok(detailsStart >= 0 && detailsEnd > detailsStart);

    const details = source.slice(detailsStart, detailsEnd);
    assert.match(details, /const historical = Boolean\(record\.is_historical\)/);
    assert.match(details, /record\.historical_evidence\?\.milestones/);
    assert.match(details, /historical \? 'Original Routing Evidence' : 'CENRO Release Events'/);
    assert.match(details, /Original CENRO Release Date/);
    assert.match(details, /Original PENRO Receipt Date/);
    assert.match(details, /Original Regional Endorsement Date/);
    assert.match(details, /'Missing historical evidence'/);
    assert.match(details, /: \(record\.release_events \|\| \[\]\)\.map/);
    assert.match(details, /historical \? originalDate\(milestones\.date_received_penro\) : date\(record\.date_received_penro\)/);
    assert.match(details, /show\(record\.days_complied\)/);
});
