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

test('Calendar renders both plus utility controls with labels, focus, hover, and their date callbacks', async () => {
    const { default: BusinessCalendarMonth, CalendarAddButton, FilterRail } = await load('Components/BusinessCalendarMonth.jsx');
    const receivedDates = [];
    const props = {
        view: 'month', year: 2026, month: '2026-08', filters: {}, modules: [], protectedAreas: [], movEvents: [],
        yearSummary: null, nonWorkingDays: [], canManage: true,
        onAdd: date => receivedDates.push(date), onSelectMov() {}, onSelectHoliday() {},
    };
    const markup = renderToStaticMarkup(React.createElement(BusinessCalendarMonth, props));
    const addControls = [...markup.matchAll(/<button(?=[^>]*aria-label="(?:Add Non-Working Day|Add non-working day on)[^"]*")[^>]*>/g)].map(match => match[0]);
    assert.equal(addControls.length, 32, 'one rail control and one control for each of August’s 31 dates');
    const nonWorkingAdd = addControls.find(button => /aria-label="Add Non-Working Day"/.test(button));
    const calendarAdds = addControls.filter(button => /aria-label="Add non-working day on /.test(button));
    assert.ok(nonWorkingAdd);
    assert.equal(calendarAdds.length, 31);
    assert.match(nonWorkingAdd, /data-cds-action="true" data-cds-action-variant="primary"/);
    assert.match(nonWorkingAdd, /h-11 w-11/);
    assert.match(nonWorkingAdd, /bg-gradient-to-br from-emerald-600 to-green-700/);
    for (const button of calendarAdds) {
        assert.doesNotMatch(button, /data-cds-action/);
        assert.match(button, /focus-visible:ring-2/);
        assert.match(button, /hover:/);
    }
    assert.match(calendarAdds[0], /h-5 w-8 min-h-5 min-w-8/);

    const railButton = CalendarAddButton({ onAdd: props.onAdd, label: 'Add Non-Working Day' });
    railButton.type(railButton.props).props.onClick();
    const dateButton = CalendarAddButton({ onAdd: props.onAdd, date: '2026-08-14', label: 'Add on date', compact: true });
    dateButton.type(dateButton.props).props.onClick();
    assert.deepEqual(receivedDates, ['', '2026-08-14']);

    const disabled = renderToStaticMarkup(React.createElement(CalendarAddButton, { onAdd() {}, label: 'Add disabled date', disabled: true }));
    assert.match(disabled, /disabled=""/);

    const rail = FilterRail({
        modules: [], filters: {}, protectedAreas: [], showMovs: true, setShowMovs() {},
        chooseModule() {}, chooseProtectedArea() {}, clearFilters() {}, canManage: true,
        onAdd: date => receivedDates.push(date),
    });
    const restoredAdd = rail.props.children[2].props.children[0];
    restoredAdd.props.onClick();
    assert.equal(receivedDates.at(-1), '', 'the restored rail control still opens the form without a preselected date');
    const unauthorizedRail = renderToStaticMarkup(React.createElement(FilterRail, {
        modules: [], filters: {}, protectedAreas: [], showMovs: true, setShowMovs() {},
        chooseModule() {}, chooseProtectedArea() {}, clearFilters() {}, canManage: false, onAdd() {},
    }));
    assert.doesNotMatch(unauthorizedRail, /aria-label="Add Non-Working Day"/);
});

test('Create Non-Working Day modal close is a neutral 32px utility control and retains processing behavior', async () => {
    const { CalendarEventFormModal, CalendarFormCloseButton } = await load('Pages/Calendar/Index.jsx');
    const closeCalls = [];
    const closeHandler = () => closeCalls.push('closed');
    const form = {
        processing: false,
        errors: {},
        data: { date: '', name: '', type: 'NATIONAL_HOLIDAY', scope: 'NATIONAL', location: '', reference: '', remarks: '', is_active: true },
        setData() {},
    };
    const modal = renderToStaticMarkup(React.createElement(CalendarEventFormModal, {
        open: true, editing: null, form, onClose: closeHandler, onSubmit(event) { event.preventDefault(); },
    }));
    assert.match(modal, /role="dialog" aria-modal="true" aria-label="Add Non-Working Day"/);
    assert.match(modal, /aria-label="Close form"/);
    const closeButtonMarkup = modal.match(/<button(?=[^>]*aria-label="Close form")[^>]*>/)?.[0];
    assert.ok(closeButtonMarkup);
    assert.doesNotMatch(closeButtonMarkup, /data-cds-action/);
    assert.match(closeButtonMarkup, /h-8 w-8 min-h-8 min-w-8/);
    assert.match(closeButtonMarkup, /focus-visible:ring-2/);
    assert.match(closeButtonMarkup, /hover:bg-gray-50/);
    assert.match(modal, /data-cds-action-variant="cancel"[^>]*>Cancel/);
    assert.match(modal, /data-cds-action-variant="primary"[^>]*>Save Non-Working Day/);

    const close = CalendarFormCloseButton({ onClose: closeHandler });
    close.type(close.props).props.onClick();
    assert.deepEqual(closeCalls, ['closed']);
    const disabledMarkup = renderToStaticMarkup(React.createElement(CalendarFormCloseButton, { onClose: closeHandler, disabled: true }));
    assert.match(disabledMarkup, /disabled=""/);
});

test('calendar legend groups real presentation categories compactly and keeps existing event colors', async () => {
    const { CalendarLegend } = await load('Components/BusinessCalendarMonth.jsx');
    const markup = renderToStaticMarkup(React.createElement(CalendarLegend));
    assert.match(markup, /role="group" aria-label="Calendar legend"/);
    assert.match(markup, /aria-label="Calendar event categories"/);
    for (const label of [
        'National holiday', 'Other non-working day', 'ENGP report', 'Protected area management',
        'Wildlife conservation', 'Community-based forest management', 'Watershed management',
        'Conservation report', 'Development report',
    ]) assert.ok(markup.includes(label), 'legend label "' + label + '" should be rendered');
    assert.match(markup, /Non-working-day group: Local holidays, special non-working days, office-declared non-working days, and other configured non-working days use this color\./);
    for (const color of [
        'from-rose-500 to-red-700', 'from-amber-400 to-orange-600', 'from-emerald-500 to-green-700',
        'from-teal-500 to-emerald-700', 'from-blue-500 to-blue-700', 'from-lime-500 to-green-600',
        'from-cyan-500 to-sky-700', 'from-violet-500 to-purple-700', 'from-indigo-500 to-indigo-700',
    ]) assert.ok(markup.includes(color), 'legend marker retains ' + color);
    assert.match(markup, /flex-wrap/);
    assert.match(markup, /dark:text-gray-200/);
});

test('year view keeps all twelve month headings accessible, selected, and free of shared action styling', async () => {
    const { default: BusinessCalendarMonth, YearMiniCalendar } = await load('Components/BusinessCalendarMonth.jsx');
    const props = {
        view: 'year', year: 2026, month: '2026-08', filters: {}, modules: [], protectedAreas: [], movEvents: [],
        yearSummary: {
            months: { '08': { days: { '20': [{ source_type: 'bms', program_area: 'protected_area_management_and_development' }] } } },
            overview: {},
        },
        nonWorkingDays: [{ id: 1, date: '2026-11-11', type: 'NATIONAL_HOLIDAY', name: 'Fixture Holiday', is_active: true }],
        canManage: false,
        onAdd() {}, onSelectMov() {}, onSelectHoliday() {},
    };
    const markup = renderToStaticMarkup(React.createElement(BusinessCalendarMonth, props));
    const headings = [...markup.matchAll(/<button(?=[^>]*aria-label="Open ([^"]+) 2026")[^>]*>([A-Z]+)<\/button>/g)];
    assert.equal(headings.length, 12);
    assert.deepEqual(headings.map(([, accessibleName, label]) => [accessibleName, label]), [
        ['January', 'JANUARY'], ['February', 'FEBRUARY'], ['March', 'MARCH'], ['April', 'APRIL'],
        ['May', 'MAY'], ['June', 'JUNE'], ['July', 'JULY'], ['August', 'AUGUST'],
        ['September', 'SEPTEMBER'], ['October', 'OCTOBER'], ['November', 'NOVEMBER'], ['December', 'DECEMBER'],
    ]);
    for (const [button] of headings) {
        assert.match(button, /type="button"/);
        assert.match(button, /focus-visible:ring-2/);
        assert.match(button, /transition-colors/);
        assert.doesNotMatch(button, /data-cds-action|cds-button-interaction/);
    }
    const selectedHeading = headings.find(([button]) => /aria-label="Open August 2026"/.test(button))[0];
    assert.match(selectedHeading, /aria-current="date"/);
    assert.match(selectedHeading, /bg-emerald-50/);
    assert.match(selectedHeading, /dark:bg-emerald-950\/50/);
    assert.equal((markup.match(/aria-current="date"/g) || []).length, 1);
    assert.match(markup, /grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4/);
    assert.equal((markup.match(/<button type="button" disabled=""/g) || []).length, 363, 'the two event/holiday dates remain active and every other date remains disabled');
    assert.match(markup, /title="Open AUGUST 20"/);
    assert.match(markup, /title="Open NOVEMBER 11"/);

    const openedMonths = [];
    const miniCalendar = YearMiniCalendar({
        year: 2026, month: 8, selected: true,
        eventDays: { '20': [{ source_type: 'bms', program_area: 'protected_area_management_and_development' }] },
        holidayDays: {}, showMovs: true,
        onOpenMonth: month => openedMonths.push(month),
    });
    const monthHeading = miniCalendar.props.children[0];
    assert.equal(monthHeading.type, 'button', 'native button semantics retain keyboard activation');
    monthHeading.props.onClick();
    const dateButton = miniCalendar.props.children[2].props.children.find(day => day?.key === '20');
    assert.ok(dateButton);
    dateButton.props.onClick();
    assert.deepEqual(openedMonths, [8, 8], 'the heading and active date cell both open their corresponding month');
});
