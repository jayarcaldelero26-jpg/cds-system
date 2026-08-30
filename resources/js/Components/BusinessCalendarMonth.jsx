import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { formatReportDate } from '@/Utils/dateFormatters';

const WEEKDAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

// One presentation map is shared by Month chips, Year markers, and the legend.
const CALENDAR_GROUPS = {
    national_holiday: { label: 'National Holiday', chip: 'from-rose-500 to-red-700' },
    local_holiday: { label: 'Local Holiday', chip: 'from-amber-400 to-orange-600' },
    engp_report: { label: 'ENGP Report', chip: 'from-emerald-500 to-green-700' },
    protected_area_management: { label: 'Protected Area Management and Development', chip: 'from-teal-500 to-emerald-700' },
    wildlife_conservation: { label: 'Wildlife Conservation and Protection', chip: 'from-blue-500 to-blue-700' },
    cbfm: { label: 'Community-Based Forest Management', chip: 'from-lime-500 to-green-600' },
    integrated_watershed: { label: 'Integrated Watershed Management', chip: 'from-cyan-500 to-sky-700' },
    conservation_report: { label: 'Conservation Report', chip: 'from-violet-500 to-purple-700' },
    development_report: { label: 'Development Report', chip: 'from-indigo-500 to-indigo-700' },
};
const LEGEND_GROUPS = ['national_holiday', 'local_holiday', 'engp_report', 'protected_area_management', 'wildlife_conservation', 'cbfm', 'integrated_watershed', 'conservation_report', 'development_report'];
const PAMD_SOURCES = new Set(['bms', 'bams', 'imea', 'imea-maintenance', 'conservation-reports', 'ipaf', 'revenue']);
const PROGRAM_AREA_GROUPS = {
    protected_area_management_and_development: 'protected_area_management',
    wildlife_conservation_and_protection: 'wildlife_conservation',
    community_based_forest_management: 'cbfm',
    integrated_watershed_management: 'integrated_watershed',
    engp: 'engp_report',
    conservation: 'conservation_report',
    development: 'development_report',
};

export default function BusinessCalendarMonth({
    view = 'month', year, month, filters = {}, modules = [], protectedAreas = [], movEvents = [],
    yearSummary = null, nonWorkingDays = [], onSelectMov, onSelectHoliday, onAdd, canManage = false,
}) {
    const [showMovs, setShowMovs] = useState(true);
    const [moreDate, setMoreDate] = useState(null);
    const parsedMonth = parseMonth(month);
    const selectedYear = Number(year || parsedMonth.year);
    const days = useMemo(() => monthDays(parsedMonth.year, parsedMonth.index), [parsedMonth.year, parsedMonth.index]);
    const movByDate = useMemo(() => groupBy(movEvents, 'submission_date'), [movEvents]);
    const holidaysByDate = useMemo(() => groupBy(nonWorkingDays, 'date'), [nonWorkingDays]);
    const today = dateKey(new Date());

    const navigate = next => {
        const nextView = next.view ?? view;
        const query = {
            view: nextView,
            module: Object.hasOwn(next, 'module') ? next.module || undefined : filters.module || undefined,
            protected_area_id: Object.hasOwn(next, 'protected_area_id') ? next.protected_area_id || undefined : filters.protected_area_id || undefined,
        };

        if (nextView === 'year') query.year = next.year ?? selectedYear;
        else query.month = next.month ?? month;

        router.get(route('business-calendar.index'), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['view', 'year', 'month', 'filters', 'movEvents', 'yearSummary', 'nonWorkingDays'],
        });
    };
    const openMonth = targetMonth => navigate({ view: 'month', month: `${selectedYear}-${String(targetMonth).padStart(2, '0')}` });

    return <section className="overflow-hidden rounded-2xl border border-slate-200/90 bg-white/90 shadow-sm backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/95">
        <div className="flex flex-col xl:flex-row">
            <aside className="flex w-full shrink-0 border-b border-emerald-100/80 bg-emerald-50/65 px-5 py-5 backdrop-blur-sm dark:border-emerald-950/60 dark:bg-emerald-950/15 xl:w-[238px] xl:flex-col xl:border-b-0 xl:border-r">
                <FilterRail
                    modules={modules}
                    filters={filters}
                    protectedAreas={protectedAreas}
                    showMovs={showMovs}
                    setShowMovs={setShowMovs}
                    chooseModule={module => navigate({ module })}
                    chooseProtectedArea={protected_area_id => navigate({ protected_area_id })}
                    canManage={canManage}
                    onAdd={onAdd}
                />
            </aside>
            <div className="min-w-0 flex-1 bg-slate-50/45 px-3 py-3 dark:bg-gray-950/20 sm:px-4 sm:py-4">
                <div className="rounded-2xl border border-slate-200/80 bg-white/75 shadow-sm backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/80">
                    <CalendarToolbar
                        view={view}
                        month={month}
                        year={selectedYear}
                        shiftMonth={amount => navigate({ month: monthKey(new Date(parsedMonth.year, parsedMonth.index + amount, 1)) })}
                        shiftYear={amount => navigate({ view: 'year', year: selectedYear + amount })}
                        goToday={() => navigate(view === 'year' ? { view: 'year', year: new Date().getFullYear() } : { view: 'month', month: monthKey(new Date()) })}
                        setView={targetView => navigate(targetView === 'year' ? { view: 'year', year: selectedYear } : { view: 'month', month })}
                    />
                    {view === 'year'
                        ? <YearView year={selectedYear} summary={yearSummary} nonWorkingDays={nonWorkingDays} showMovs={showMovs} onOpenMonth={openMonth} />
                        : <MonthView days={days} today={today} movByDate={movByDate} holidaysByDate={holidaysByDate} showMovs={showMovs} canManage={canManage} onAdd={onAdd} onSelectMov={onSelectMov} onSelectHoliday={onSelectHoliday} onMore={setMoreDate} />}
                </div>
            </div>
        </div>
        <DateEventsModal date={moreDate} events={moreDate ? movByDate[moreDate] || [] : []} onClose={() => setMoreDate(null)} onSelect={event => { setMoreDate(null); onSelectMov(event); }} />
    </section>;
}

function FilterRail({ modules, filters, protectedAreas, showMovs, setShowMovs, chooseModule, chooseProtectedArea, canManage, onAdd }) {
    return <div className="flex w-full flex-col sm:grid sm:grid-cols-2 sm:gap-5 xl:min-h-[520px] xl:flex-1 xl:flex xl:flex-col xl:gap-0">
        <div className="xl:mb-6">
            <p className="mb-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900/65 dark:text-emerald-300/70">Show on calendar</p>
            <label className="flex cursor-pointer items-center gap-2.5 text-xs font-semibold text-slate-700 dark:text-gray-200">
                <input type="checkbox" checked={showMovs} onChange={event => setShowMovs(event.target.checked)} className="h-3.5 w-3.5 rounded border-slate-300 text-green-700 focus:ring-green-600 dark:border-gray-600 dark:bg-gray-800" />
                Submitted MOVs
            </label>
        </div>
        <div className="mt-5 sm:mt-0 xl:mb-6 xl:mt-0">
            <p className="mb-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900/65 dark:text-emerald-300/70">Modules</p>
            <div className="space-y-2.5">
                <ModuleChoice label="All Modules" checked={!filters.module} onChange={() => chooseModule('')} />
                {modules.map(module => <ModuleChoice key={module.key} label={module.label} checked={filters.module === module.key} onChange={() => chooseModule(filters.module === module.key ? '' : module.key)} />)}
            </div>
        </div>
        <label className="mt-5 block sm:col-span-2 xl:mt-0">
            <span className="mb-2 block text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-900/65 dark:text-emerald-300/70">Protected Area</span>
            <span className="relative block">
                <select value={filters.protected_area_id || ''} onChange={event => chooseProtectedArea(event.target.value)} className="h-10 w-full appearance-none rounded-lg border border-slate-200 bg-white/90 px-3 pr-9 text-xs font-medium text-slate-700 outline-none transition focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">All Protected Areas</option>
                    {protectedAreas.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}
                </select>
                <ChevronDown />
            </span>
        </label>
        {canManage && <div className="mt-6 border-t border-emerald-100/90 pt-4 sm:col-span-2 xl:mt-auto">
            <button type="button" onClick={() => onAdd('')} title="Add Non-Working Day" aria-label="Add Non-Working Day" className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-emerald-600 to-green-700 text-white shadow-md transition hover:brightness-110 hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2">
                <PlusIcon />
            </button>
            <span className="ml-2 align-middle text-[10px] font-semibold uppercase tracking-[0.1em] text-emerald-900/65 dark:text-emerald-300/70">Non-Working Day</span>
        </div>}
    </div>;
}

function ModuleChoice({ label, checked, onChange }) {
    return <label className="flex cursor-pointer items-start gap-2.5 text-xs text-slate-600 dark:text-gray-300">
        <input type="checkbox" checked={checked} onChange={onChange} className="mt-0.5 h-3.5 w-3.5 rounded border-slate-300 text-green-700 focus:ring-green-600 dark:border-gray-600 dark:bg-gray-800" />
        <span className={checked ? 'font-semibold text-emerald-800 dark:text-emerald-300' : ''}>{label}</span>
    </label>;
}

function CalendarToolbar({ view, month, year, shiftMonth, shiftYear, goToday, setView }) {
    const monthMode = view === 'month';
    const navClass = 'inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-white/90 text-slate-700 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    const neutralViewClass = 'border border-slate-200 bg-white text-slate-700 shadow-sm hover:border-emerald-300 hover:bg-emerald-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';

    return <header className="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-5">
        <div className="flex items-center gap-2">
            <button type="button" onClick={() => monthMode ? shiftMonth(-1) : shiftYear(-1)} className={navClass} aria-label={monthMode ? 'Previous month' : 'Previous year'}><Chevron direction="left" /></button>
            <h2 className="min-w-[175px] text-center text-xl font-bold tracking-wide text-slate-800 dark:text-white sm:text-2xl">{monthMode ? monthLabel(month) : year}</h2>
            <button type="button" onClick={() => monthMode ? shiftMonth(1) : shiftYear(1)} className={navClass} aria-label={monthMode ? 'Next month' : 'Next year'}><Chevron direction="right" /></button>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <button type="button" onClick={goToday} className={`h-10 rounded-lg px-3.5 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${neutralViewClass}`}>Today</button>
            <button type="button" onClick={() => setView('month')} className={`h-10 rounded-lg px-3.5 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${monthMode ? 'bg-gradient-to-r from-emerald-600 to-green-700 text-white shadow-sm' : neutralViewClass}`}>Month</button>
            <button type="button" onClick={() => setView('year')} className={`h-10 rounded-lg px-3.5 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${!monthMode ? 'bg-gradient-to-r from-emerald-600 to-green-700 text-white shadow-sm' : neutralViewClass}`}>Year</button>
        </div>
    </header>;
}

function MonthView({ days, today, movByDate, holidaysByDate, showMovs, canManage, onAdd, onSelectMov, onSelectHoliday, onMore }) {
    return <>
        <div className="overflow-x-auto px-2 pb-2 custom-table-scrollbar sm:px-3 sm:pb-3">
            <div className="min-w-[840px]">
                <div className="mb-2 grid grid-cols-7">
                    {WEEKDAYS.map(day => <div key={day} className="px-2 py-1.5 text-center text-[10px] font-semibold tracking-[0.12em] text-slate-700 dark:text-gray-300">{day}</div>)}
                </div>
                <div className="grid grid-cols-7 gap-1.5 sm:gap-2">
                    {days.map(day => <CalendarDay key={day.key} day={day} today={today === day.key} movEvents={showMovs ? (movByDate[day.key] || []) : []} holidays={holidaysByDate[day.key] || []} canManage={canManage} onAdd={onAdd} onSelectMov={onSelectMov} onSelectHoliday={onSelectHoliday} onMore={() => onMore(day.key)} />)}
                </div>
            </div>
        </div>
        <CalendarLegend />
    </>;
}

function CalendarDay({ day, today, movEvents, holidays, canManage, onAdd, onSelectMov, onSelectHoliday, onMore }) {
    const visible = movEvents.slice(0, 3);
    const remaining = movEvents.length - visible.length;
    const surface = day.inMonth ? 'border-slate-200/80 bg-white/80 shadow-sm hover:-translate-y-px hover:shadow-md dark:border-gray-700 dark:bg-gray-900/85' : 'border-slate-200/55 bg-slate-100/65 opacity-80 dark:border-gray-800 dark:bg-gray-950/55';

    return <div className={`group relative min-h-[118px] rounded-xl border p-2.5 backdrop-blur-sm transition duration-150 ${surface} ${today ? 'border-emerald-400 bg-emerald-50/75 shadow-[0_0_0_1px_rgba(16,185,129,0.12)] dark:border-emerald-700 dark:bg-emerald-950/20' : ''}`}>
        <div className="mb-2 flex items-center justify-between">
            <span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs font-semibold ${today ? 'bg-gradient-to-br from-emerald-600 to-green-700 text-white shadow-sm' : day.inMonth ? 'text-slate-800 dark:text-gray-100' : 'text-slate-400 dark:text-gray-600'}`}>{day.number}</span>
            {canManage && day.inMonth && <button type="button" onClick={() => onAdd(day.key)} className="invisible rounded-md px-1.5 text-sm leading-5 text-emerald-700 transition hover:bg-emerald-50 group-hover:visible focus:visible dark:text-emerald-300 dark:hover:bg-emerald-950/40" aria-label={`Add non-working day on ${formatReportDate(day.key)}`}>+</button>}
        </div>
        <div className="space-y-1">
            {holidays.map(holiday => <HolidayChip key={holiday.id} holiday={holiday} onSelect={onSelectHoliday} />)}
            {visible.map(event => <MovChip key={event.source_key} event={event} onSelect={onSelectMov} />)}
            {remaining > 0 && <button type="button" onClick={onMore} className="block px-1 text-[10px] font-semibold text-emerald-800 transition hover:text-emerald-950 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-emerald-300">+{remaining} more</button>}
        </div>
    </div>;
}

function HolidayChip({ holiday, onSelect }) {
    const style = getCalendarGroupStyle(getCalendarEventGroup(holiday));
    const label = holiday.type === 'OFFICE_DECLARED_NON_WORKING_DAY' ? `Office Declared • ${holiday.name}` : `Holiday • ${holiday.name}`;
    return <button type="button" onClick={() => onSelect(holiday)} title={holiday.name} className={`block h-[22px] w-full truncate rounded-md bg-gradient-to-r px-2 text-left text-[10px] font-semibold leading-[22px] text-white shadow-sm transition hover:brightness-110 hover:shadow focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${holiday.is_active ? style.chip : 'from-slate-400 to-slate-500 opacity-70 line-through'}`}>{label}</button>;
}

function MovChip({ event, onSelect }) {
    const style = getCalendarGroupStyle(getCalendarEventGroup(event));
    const label = `${event.module} • ${event.source_name || event.office || 'Office'}`;
    return <button type="button" onClick={() => onSelect(event)} title={`${label} — ${event.title}`} className={`block h-[22px] w-full truncate rounded-md bg-gradient-to-r px-2 text-left text-[10px] font-semibold leading-[22px] text-white shadow-sm transition hover:brightness-110 hover:shadow focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${style.chip}`}>{label}</button>;
}

function YearView({ year, summary, nonWorkingDays, showMovs, onOpenMonth }) {
    const months = summary?.months || {};
    const overview = summary?.overview || {};
    const holidaysByMonth = groupHolidaysByMonth(nonWorkingDays);

    return <div className="px-3 pb-3 sm:px-4 sm:pb-4">
        <div className="mb-4 rounded-xl border border-slate-200/80 bg-slate-50/70 px-3 py-2.5 shadow-sm dark:border-gray-800 dark:bg-gray-950/25">
            <p className="text-xs font-bold text-slate-800 dark:text-white">{year} Overview</p>
            <div className="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-[11px] text-slate-600 dark:text-gray-300">
                <span><b className="text-slate-800 dark:text-white">{showMovs ? overview.submitted_movs || 0 : 0}</b> Submitted MOVs</span>
                <span><b className="text-slate-800 dark:text-white">{showMovs ? overview.months_with_submissions || 0 : 0}</b> Months with Activity</span>
                <span><b className="text-slate-800 dark:text-white">{overview.non_working_days || 0}</b> Non-Working Days</span>
            </div>
        </div>
        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 12 }, (_, index) => <YearMiniCalendar key={index} year={year} month={index + 1} eventDays={months[String(index + 1).padStart(2, '0')]?.days || {}} holidayDays={holidaysByMonth[index + 1] || {}} showMovs={showMovs} onOpenMonth={onOpenMonth} />)}
        </div>
    </div>;
}

function YearMiniCalendar({ year, month, eventDays, holidayDays, showMovs, onOpenMonth }) {
    const calendarDays = miniMonthDays(year, month - 1);
    const title = new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(year, month - 1, 1)).toUpperCase();

    return <article className="rounded-xl border border-slate-200/80 bg-white/80 p-3 shadow-sm backdrop-blur-sm transition hover:border-emerald-200 hover:shadow-md dark:border-gray-700 dark:bg-gray-900/85 dark:hover:border-emerald-800">
        <button type="button" onClick={() => onOpenMonth(month)} className="mb-2 block w-full text-left text-xs font-bold tracking-wide text-slate-800 transition hover:text-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-white dark:hover:text-emerald-300">{title}</button>
        <div className="mb-1 grid grid-cols-7 text-center text-[8px] font-semibold tracking-[0.08em] text-slate-400 dark:text-gray-500">
            {WEEKDAYS.map(day => <span key={day}>{day.slice(0, 1)}</span>)}
        </div>
        <div className="grid grid-cols-7 gap-y-1">
            {calendarDays.map((day, index) => {
                if (!day) return <span key={`blank-${index}`} className="min-h-[28px]" aria-hidden="true" />;
                const key = String(day).padStart(2, '0');
                const markers = [
                    ...(showMovs ? (eventDays[key] || []).map(getCalendarEventGroup) : []),
                    ...(holidayDays[key] || []).map(getCalendarEventGroup),
                ];
                const visible = markers.slice(0, 3);
                const remaining = markers.length - visible.length;
                const active = markers.length > 0;

                return <button key={key} type="button" disabled={!active} onClick={() => onOpenMonth(month)} title={active ? `Open ${title} ${day}` : undefined} className={`flex min-h-[28px] flex-col items-center rounded-md pt-0.5 text-[10px] font-medium transition ${active ? 'cursor-pointer text-slate-800 hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-100 dark:hover:bg-emerald-950/30' : 'cursor-default text-slate-500 dark:text-gray-500'}`}>
                    <span>{day}</span>
                    {active && <span className="mt-0.5 flex h-2.5 items-center justify-center gap-0.5">
                        {visible.map((group, markerIndex) => <i key={`${group}-${markerIndex}`} className={`h-1.5 w-1.5 rounded-full bg-gradient-to-br ${getCalendarGroupStyle(group).chip}`} aria-hidden="true" />)}
                        {remaining > 0 && <span className="text-[7px] font-bold leading-none text-slate-500 dark:text-gray-400">+{remaining}</span>}
                    </span>}
                </button>;
            })}
        </div>
    </article>;
}

function CalendarLegend() {
    return <div className="mx-2 mb-2 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-slate-200/80 bg-white/80 px-3 py-2.5 text-[10px] font-medium text-slate-600 shadow-sm backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/80 dark:text-gray-300 sm:mx-3 sm:mb-3">
        <span className="font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-gray-400">Legend</span>
        {LEGEND_GROUPS.map(group => {
            const style = getCalendarGroupStyle(group);
            return <span key={group} className="inline-flex items-center gap-1.5 whitespace-nowrap"><i className={`h-2.5 w-2.5 rounded-full bg-gradient-to-br shadow-sm ${style.chip}`} aria-hidden="true" />{style.label}</span>;
        })}
    </div>;
}

function DateEventsModal({ date, events, onClose, onSelect }) {
    if (!date) return null;
    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" role="presentation">
        <section role="dialog" aria-modal="true" aria-label={`Submitted reports for ${formatReportDate(date)}`} className="max-h-[80vh] w-full max-w-lg overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <header className="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div><h3 className="text-sm font-bold text-gray-900 dark:text-white">Submitted reports</h3><p className="mt-0.5 text-xs text-gray-500">{formatReportDate(date)} · {events.length} events</p></div>
                <button type="button" onClick={onClose} className="rounded-lg px-2 py-1 text-xl text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Close">&times;</button>
            </header>
            <div className="max-h-[60vh] space-y-2 overflow-y-auto p-4">
                {events.map(event => <button key={event.source_key} type="button" onClick={() => onSelect(event)} className="w-full rounded-lg border border-gray-200 p-3 text-left hover:border-green-300 hover:bg-green-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:hover:border-green-800 dark:hover:bg-green-950/20"><span className="block text-xs font-bold text-gray-900 dark:text-white">{event.module} • {event.source_name || event.office || 'Office'}</span><span className="mt-1 block text-[11px] text-gray-500 dark:text-gray-400">{event.title}{event.reporting_period ? ` · ${event.reporting_period}` : ''}</span></button>)}
            </div>
        </section>
    </div>;
}

function getCalendarEventGroup(event) {
    if (event?.type) return event.type === 'NATIONAL_HOLIDAY' ? 'national_holiday' : 'local_holiday';
    if (event?.program_area && PROGRAM_AREA_GROUPS[event.program_area]) return PROGRAM_AREA_GROUPS[event.program_area];
    if (event?.source_type === 'engp') return 'engp_report';
    if (PAMD_SOURCES.has(event?.source_type)) return 'protected_area_management';
    if (event?.source_type === 'aws') return 'conservation_report';
    if (['technical-reports', 'management-plans'].includes(event?.source_type)) return 'development_report';
    return 'conservation_report';
}

function getCalendarGroupStyle(group) { return CALENDAR_GROUPS[group] || CALENDAR_GROUPS.conservation_report; }
function groupBy(items, field) { return items.reduce((result, item) => { const key = item?.[field]; if (key) (result[key] ||= []).push(item); return result; }, {}); }
function groupHolidaysByMonth(items) { return items.reduce((result, holiday) => { const match = String(holiday?.date || '').match(/^(\d{4})-(\d{2})-(\d{2})$/); if (!match) return result; (result[Number(match[2])] ||= {})[match[3]] ||= []; result[Number(match[2])][match[3]].push(holiday); return result; }, {}); }
function parseMonth(value) { const match = String(value || '').match(/^(\d{4})-(\d{2})$/); const now = new Date(); return match ? { year: Number(match[1]), index: Number(match[2]) - 1 } : { year: now.getFullYear(), index: now.getMonth() }; }
function monthKey(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`; }
function dateKey(date) { return `${monthKey(date)}-${String(date.getDate()).padStart(2, '0')}`; }
function monthLabel(value) { const item = parseMonth(value); return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(new Date(item.year, item.index, 1)).toUpperCase(); }
function mondayIndex(date) { return (date.getDay() + 6) % 7; }
function monthDays(year, month) { const first = new Date(year, month, 1); const leading = mondayIndex(first); const total = new Date(year, month + 1, 0).getDate(); const count = Math.ceil((leading + total) / 7) * 7; return Array.from({ length: count }, (_, index) => { const date = new Date(year, month, index - leading + 1); return { key: dateKey(date), number: date.getDate(), inMonth: date.getMonth() === month }; }); }
function miniMonthDays(year, month) { const leading = mondayIndex(new Date(year, month, 1)); const total = new Date(year, month + 1, 0).getDate(); const slots = Math.ceil((leading + total) / 7) * 7; return Array.from({ length: slots }, (_, index) => index >= leading && index < leading + total ? index - leading + 1 : null); }
function Chevron({ direction }) { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true"><path d={direction === 'left' ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6'} /></svg>; }
function ChevronDown() { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>; }
function PlusIcon() { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" className="h-5 w-5" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>; }
