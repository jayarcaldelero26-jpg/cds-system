import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import {
    routingCorrectionDates,
    routingCorrectionPayload,
} from '../../resources/js/Utils/routingCorrectionForm.js';

test('ENGP form state exposes only its supported parent receipt and existing component events', () => {
    const row = {
        source: 'engp',
        date_report_released_cenro: '2026-10-03',
        date_received_penro: '2026-10-04',
        date_endorsed_regional: '2026-10-05',
        release_events: [
            { id: 41, date_report_released_cenro: '2026-10-02' },
        ],
    };

    assert.deepEqual(routingCorrectionDates(row), {
        date_received_penro: '2026-10-04',
    });

    const payload = routingCorrectionPayload({
        dates: {
            date_report_released_cenro: '2026-10-03',
            date_received_penro: '2026-10-04',
            date_endorsed_regional: '2026-10-05',
        },
        release_events: { 41: '2026-10-02', 999: '2026-10-09' },
        internal_events: { 'not-an-engp-event': '2026-10-01T09:00' },
        reason: 'Correct the receipt date.',
        password: 'current-password',
    }, row);

    assert.deepEqual(payload.dates, { date_received_penro: '2026-10-04' });
    assert.deepEqual(payload.release_events, { 41: '2026-10-02' });
    assert.deepEqual(payload.internal_events, {});
    assert.equal(payload.reason, 'Correct the receipt date.');
    assert.equal(payload.password, 'current-password');
});

test('blank ENGP display projections never enter the outgoing parent dates and empty components stay empty', () => {
    const row = {
        source: 'engp',
        date_report_released_cenro: null,
        date_received_penro: null,
        date_endorsed_regional: null,
        release_events: [],
    };
    const payload = routingCorrectionPayload({
        dates: {
            date_report_released_cenro: '',
            date_received_penro: '',
            date_endorsed_regional: '',
        },
        release_events: { 1: '2026-10-02' },
        internal_events: {},
    }, row);

    assert.deepEqual(routingCorrectionDates(row), { date_received_penro: '' });
    assert.deepEqual(payload.dates, { date_received_penro: '' });
    assert.deepEqual(payload.release_events, {});
});

test('switching between ENGP and another source resets date fields and preserves other-source payloads', () => {
    const engp = {
        source: 'engp',
        date_report_released_cenro: '2026-10-03',
        date_received_penro: '2026-10-04',
        date_endorsed_regional: '2026-10-05',
        release_events: [],
    };
    const conservation = {
        source: 'conservation',
        date_report_released_cenro: '2026-10-01',
        date_received_penro: '2026-10-02',
        date_endorsed_regional: '2026-10-03',
    };

    assert.deepEqual(routingCorrectionDates(engp), {
        date_received_penro: '2026-10-04',
    });
    assert.deepEqual(routingCorrectionDates(conservation), {
        date_report_released_cenro: '2026-10-01',
        date_received_penro: '2026-10-02',
        date_endorsed_regional: '2026-10-03',
    });

    const otherSourcePayload = { dates: routingCorrectionDates(conservation), internal_events: {} };
    assert.equal(routingCorrectionPayload(otherSourcePayload, conservation), otherSourcePayload);
});

test('the real correction submit callback transforms outgoing data and keeps ENGP projections read-only', () => {
    const source = readFileSync(new URL('../../resources/js/Pages/SubmissionTracking/Index.jsx', import.meta.url), 'utf8');

    assert.match(source, /correctionForm\.transform\(\(data\)\s*=>\s*routingCorrectionPayload\(data, correction\)/);
    assert.match(source, /correctionForm\.patch\(/);
    assert.match(source, /const dates = routingCorrectionDates\(row\)/);
    assert.match(source, /CENRO Release \(components\)/);
    assert.match(source, /Regional Endorsement \(routing event\)/);
});
