import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

let server;
const load = async path => {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule('/resources/js/' + path);
};

test.after(async () => { await server?.close(); });

function fixtureEvents(count) {
    return Array.from({ length: count }, (_, index) => {
        const day = String(index % 28 + 1).padStart(2, '0');
        return {
            source_key: `fixture:${index}`,
            submission_date: `2026-08-${day}`,
            module: 'BMS',
            source_name: 'Fixture Office',
            title: `Fixture event ${index + 1}`,
            source_type: 'bms',
            program_area: 'protected_area_management_and_development',
        };
    });
}

function fixtureMetrics(markup, eventCount) {
    const dayCards = [...markup.matchAll(/class="group relative min-h-\[118px\] rounded-xl border p-2\.5 ([^"]+)"/g)];
    return {
        eventCount,
        dayCards: dayCards.length,
        dayCardBackdropFilters: dayCards.filter(([, classes]) => classes.includes('backdrop-blur-sm')).length,
        dayCardHoverTransforms: dayCards.filter(([, classes]) => classes.includes('hover:-translate-y-px')).length,
        allCalendarBackdropFilters: (markup.match(/\bbackdrop-blur-sm\b/g) || []).length,
        renderedMovChips: (markup.match(/title="BMS • Fixture Office — Fixture event /g) || []).length,
        moreButtons: (markup.match(/>\+\d+ more<\/button>/g) || []).length,
        markupBytes: Buffer.byteLength(markup),
    };
}

test('real Calendar month markup keeps sparse and populated event membership while limiting repeated day-cell paint effects', async () => {
    const { default: BusinessCalendarMonth } = await load('Components/BusinessCalendarMonth.jsx');
    const commonProps = {
        view: 'month', year: 2026, month: '2026-08', filters: {}, modules: [], protectedAreas: [],
        yearSummary: null, nonWorkingDays: [], canManage: false,
        onAdd() {}, onSelectMov() {}, onSelectHoliday() {},
    };
    const sparseMarkup = renderToStaticMarkup(React.createElement(BusinessCalendarMonth, { ...commonProps, movEvents: fixtureEvents(12) }));
    const populatedMarkup = renderToStaticMarkup(React.createElement(BusinessCalendarMonth, { ...commonProps, movEvents: fixtureEvents(240) }));
    const sparse = fixtureMetrics(sparseMarkup, 12);
    const populated = fixtureMetrics(populatedMarkup, 240);

    if (process.env.CDS_CALENDAR_SCROLL_METRICS === '1') {
        console.log('Calendar month rendered-surface metrics:', JSON.stringify({ sparse, populated }));
    }

    assert.equal(sparse.dayCards, 42, 'August 2026 renders its six-week month grid');
    assert.equal(populated.dayCards, 42, 'a larger authorized event set does not change month grid geometry');
    assert.equal(sparse.dayCardHoverTransforms, 0, 'month cards do not move when hover changes under a scrolling pointer');
    assert.equal(populated.dayCardHoverTransforms, 0, 'populated month cards do not move when hover changes under a scrolling pointer');
    assert.equal(sparse.renderedMovChips, 12);
    assert.equal(sparse.moreButtons, 0);
    assert.equal(populated.renderedMovChips, 84, 'each of 28 populated dates shows at most three event chips');
    assert.equal(populated.moreButtons, 28, 'remaining events stay reachable through the existing per-date details control');
    assert.equal(sparse.dayCardBackdropFilters, 0, 'day cards should not each create a backdrop-filter surface');
    assert.equal(populated.dayCardBackdropFilters, 0, 'populated months should not add repeated day-card backdrop filters');
    assert.equal(sparse.allCalendarBackdropFilters, 4, 'the existing shared calendar surfaces and legend retain their backdrop treatment');
    assert.equal(populated.allCalendarBackdropFilters, 4, 'event volume does not add backdrop filters to the shared calendar surfaces');
});

test('real Calendar year markup keeps twelve stable month surfaces and restrained selectors', async () => {
    const { default: BusinessCalendarMonth } = await load('Components/BusinessCalendarMonth.jsx');
    const markup = renderToStaticMarkup(React.createElement(BusinessCalendarMonth, {
        view: 'year', year: 2026, month: '2026-08', filters: {}, modules: [], protectedAreas: [], movEvents: [],
        yearSummary: { months: {}, overview: {} }, nonWorkingDays: [], canManage: false,
        onAdd() {}, onSelectMov() {}, onSelectHoliday() {},
    }));
    const yearCards = [...markup.matchAll(/<article class="([^"]*)">/g)];
    const metrics = {
        yearCards: yearCards.length,
        yearCardBackdropFilters: yearCards.filter(([, classes]) => classes.includes('backdrop-blur-sm')).length,
        allCalendarBackdropFilters: (markup.match(/\bbackdrop-blur-sm\b/g) || []).length,
        accessibleMonthSelectors: (markup.match(/aria-label="Open [^"]+ 2026"/g) || []).length,
        sharedActionStyledMonthSelectors: (markup.match(/data-cds-action="true"|cds-button-interaction/g) || []).length,
        selectedMonthMarkers: (markup.match(/aria-current="date"/g) || []).length,
        markupBytes: Buffer.byteLength(markup),
    };
    if (process.env.CDS_CALENDAR_SCROLL_METRICS === '1') console.log('Calendar year rendered-surface metrics:', JSON.stringify(metrics));
    assert.equal(metrics.yearCards, 12);
    assert.equal(metrics.yearCardBackdropFilters, 0);
    assert.equal(metrics.allCalendarBackdropFilters, 3);
    assert.equal(metrics.accessibleMonthSelectors, 12);
    assert.equal(metrics.sharedActionStyledMonthSelectors, 0);
    assert.equal(metrics.selectedMonthMarkers, 1);
});
