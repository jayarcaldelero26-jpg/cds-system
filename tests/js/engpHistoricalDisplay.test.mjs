import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { cenroReleaseFallbackForRow } from '../../resources/js/Utils/submissionTrackingPresentation.js';

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
    assert.match(details, /: <>\s*<Detail label="Released by CENRO">\{date\(record\.date_released_cenro, cenroReleaseFallbackForRow\(record\)\)\}<\/Detail>\s*\{\(record\.release_events \|\| \[\]\)\.map/);
    assert.match(details, /historical \? originalDate\(milestones\.date_received_penro\) : date\(record\.date_received_penro\)/);
    assert.match(details, /show\(record\.days_complied, 'Not recorded'\)/);
});

test('live ENGP details show the canonical CENRO route date and keep component release events separate', () => {
    assert.match(source, /<Detail label="Released by CENRO">\{date\(record\.date_released_cenro, cenroReleaseFallbackForRow\(record\)\)\}<\/Detail>/);
    assert.match(source, /\(record\.release_events \|\| \[\]\)\.map\(event => <Detail key=\{event\.period_component\} label=\{event\.component_label\}>\{date\(event\.date_report_released_cenro\)\}/);
});

test('missing live ENGP CENRO release dates use a clear fallback in both summary and module tables', () => {
    assert.match(source, /const date = \(value, fallback\) => formatReportDate\(value, fallback\)/);
    assert.equal((source.match(/date\(row\.date_released_cenro, cenroReleaseFallbackForRow\(row\)\)/g) || []).length, 2);
    assert.equal(cenroReleaseFallbackForRow({ cenro_release_state: 'awaiting_cenro_release' }), 'Awaiting CENRO release');
    assert.equal(cenroReleaseFallbackForRow({ cenro_release_state: 'not_recorded' }), 'Not recorded');
    assert.equal(cenroReleaseFallbackForRow({ cenro_release_applicable: false }), 'Not applicable');
    assert.equal(cenroReleaseFallbackForRow({ date_released_cenro: '2026-10-20' }), 'Not recorded');
});
