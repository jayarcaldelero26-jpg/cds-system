import { useMemo, useState } from 'react';
import { formatReportDate } from '@/Utils/dateFormatters';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const TYPES = [
    { key: 'NATIONAL_HOLIDAY', label: 'National Holiday', color: 'bg-emerald-500', event: 'border-emerald-200 bg-emerald-100 text-emerald-900 hover:bg-emerald-200 dark:border-emerald-900 dark:bg-emerald-950/65 dark:text-emerald-200 dark:hover:bg-emerald-950' },
    { key: 'LOCAL_HOLIDAY', label: 'Local Holiday', color: 'bg-teal-500', event: 'border-teal-200 bg-teal-100 text-teal-900 hover:bg-teal-200 dark:border-teal-900 dark:bg-teal-950/65 dark:text-teal-200 dark:hover:bg-teal-950' },
    { key: 'SPECIAL_NON_WORKING_DAY', label: 'Special Non-Working', color: 'bg-amber-500', event: 'border-amber-200 bg-amber-100 text-amber-900 hover:bg-amber-200 dark:border-amber-900 dark:bg-amber-950/65 dark:text-amber-200 dark:hover:bg-amber-950' },
    { key: 'OFFICE_DECLARED_NON_WORKING_DAY', label: 'Office-Declared', color: 'bg-orange-500', event: 'border-orange-200 bg-orange-100 text-orange-900 hover:bg-orange-200 dark:border-orange-900 dark:bg-orange-950/65 dark:text-orange-200 dark:hover:bg-orange-950' },
    { key: 'OTHER', label: 'Other', color: 'bg-indigo-400', event: 'border-indigo-200 bg-indigo-100 text-indigo-900 hover:bg-indigo-200 dark:border-indigo-900 dark:bg-indigo-950/65 dark:text-indigo-200 dark:hover:bg-indigo-950' },
];
const TYPE_BY_KEY = Object.fromEntries(TYPES.map(type => [type.key, type]));
const pad = value => String(value).padStart(2, '0');
const dateKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const monthParts = date => ({ year: date.getFullYear(), month: date.getMonth() });
const mondayIndex = date => (date.getDay() + 6) % 7;

function displayMonth(year, month, options = { month: 'long', year: 'numeric' }) {
    return new Intl.DateTimeFormat('en-US', options).format(new Date(year, month, 1));
}

function todayKey() {
    return dateKey(new Date());
}

function selectedDateLabel(value) {
    const date = parseDateKey(value);
    return date ? new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'short', day: 'numeric' }).format(date) : 'Calendar';
}

function eventMatches(event, query, filters) {
    if (!filters[event.type || 'OTHER']) return false;
    if (!event.is_active && !filters.INACTIVE) return false;
    if (!query) return true;
    return [event.name, event.type, event.remarks].filter(Boolean).join(' ').toLowerCase().includes(query.toLowerCase());
}

export default function BusinessCalendarMonth({ nonWorkingDays = [], onSelectEvent, onAdd, canManage = false }) {
    const [month, setMonth] = useState(() => monthParts(new Date()));
    const [view, setView] = useState('month');
    const [selectedDate, setSelectedDate] = useState(todayKey());
    const [query, setQuery] = useState('');
    const [showSearch, setShowSearch] = useState(false);
    const [filters, setFilters] = useState(() => Object.fromEntries([...TYPES.map(type => [type.key, true]), ['INACTIVE', false]]));
    const visibleEvents = useMemo(() => nonWorkingDays.filter(event => eventMatches(event, query.trim(), filters)), [filters, nonWorkingDays, query]);
    const visibleEventsByDate = useMemo(() => groupByDate(visibleEvents), [visibleEvents]);
    const allEventsByDate = useMemo(() => groupByDate(nonWorkingDays), [nonWorkingDays]);
    const currentToday = todayKey();
    const shiftMonth = amount => {
        const selected = parseDateKey(selectedDate) || new Date(month.year, month.month, 1);
        const next = new Date(month.year, month.month + amount, Math.min(selected.getDate(), new Date(month.year, month.month + amount + 1, 0).getDate()));
        setMonth(monthParts(next));
        setSelectedDate(dateKey(next));
    };
    const selectDate = value => {
        const date = parseDateKey(value);
        if (!date) return;
        setSelectedDate(value);
        setMonth(monthParts(date));
    };
    const selectEvent = event => { selectDate(event?.date); onSelectEvent?.(event); };
    const addForDate = value => { if (value) selectDate(value); onAdd?.(value || ''); };
    const goToday = () => { const now = new Date(); selectDate(dateKey(now)); };
    const toggleFilter = key => setFilters(current => ({ ...current, [key]: !current[key] }));

    return <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-[0_10px_35px_rgba(15,23,42,0.07)] dark:border-gray-800 dark:bg-gray-900" aria-label="Business calendar workspace">
        <div className="flex min-h-[590px]">
            <aside className="relative hidden w-[232px] shrink-0 bg-green-50/55 px-5 py-5 dark:bg-green-950/10 lg:block">
                <span className="absolute inset-y-0 left-0 w-2 bg-green-800 dark:bg-green-600" aria-hidden="true" />
                <CalendarPanel month={month} shiftMonth={shiftMonth} goToday={goToday} filters={filters} toggleFilter={toggleFilter} nonWorkingDays={nonWorkingDays} today={currentToday} onSelectEvent={selectEvent} showSearch={showSearch} setShowSearch={setShowSearch} query={query} setQuery={setQuery} />
            </aside>
            <main className="relative min-w-0 flex-1 bg-white dark:bg-gray-900">
                <CalendarHeader selectedDate={selectedDate} month={month} view={view} setView={setView} />
                <div className="px-4 pb-4 sm:px-6 sm:pb-6">
                    {view === 'month' && <MonthView month={month} selectedDate={selectedDate} today={currentToday} eventsByDate={visibleEventsByDate} allEventsByDate={allEventsByDate} canManage={canManage} onSelectDate={selectDate} onSelectEvent={selectEvent} onAdd={addForDate} />}
                    {view === 'year' && <YearView year={month.year} selectedMonth={month.month} eventsByDate={visibleEventsByDate} today={currentToday} onSelectMonth={selectedMonth => { const value = `${month.year}-${pad(selectedMonth + 1)}-01`; selectDate(value); setView('month'); }} />}
                    {view === 'agenda' && <AgendaView events={visibleEvents} onSelectEvent={selectEvent} />}
                </div>
                {canManage && <button type="button" onClick={() => addForDate('')} className="absolute bottom-5 right-5 inline-flex h-12 w-12 items-center justify-center rounded-full bg-green-800 text-white shadow-lg transition duration-150 hover:bg-green-700 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 dark:bg-green-700 dark:hover:bg-green-600" aria-label="Add Non-Working Day" title="Add Non-Working Day"><PlusIcon /></button>}
            </main>
        </div>
        <details className="border-t border-gray-100 bg-green-50/45 dark:border-gray-800 dark:bg-green-950/10 lg:hidden"><summary className="cursor-pointer px-4 py-3 text-xs font-semibold text-gray-700 dark:text-gray-200">Calendar tools</summary><div className="border-t border-gray-100 px-4 py-4 dark:border-gray-800"><CalendarPanel month={month} shiftMonth={shiftMonth} goToday={goToday} filters={filters} toggleFilter={toggleFilter} nonWorkingDays={nonWorkingDays} today={currentToday} onSelectEvent={selectEvent} showSearch={showSearch} setShowSearch={setShowSearch} query={query} setQuery={setQuery} /></div></details>
    </section>;
}

function CalendarPanel({ month, shiftMonth, goToday, filters, toggleFilter, nonWorkingDays, today, onSelectEvent, showSearch, setShowSearch, query, setQuery }) {
    const upcoming = getUpcoming(nonWorkingDays, today);
    return <div className="space-y-6"><div className="flex items-center gap-2"><button type="button" onClick={goToday} className="rounded-md px-2 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-200 dark:hover:bg-gray-800">Today</button><button type="button" onClick={() => shiftMonth(-1)} className="rounded p-1.5 text-gray-500 transition hover:bg-white hover:text-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:bg-gray-800 dark:hover:text-green-300" aria-label="Previous month"><Chevron direction="left" /></button><button type="button" onClick={() => shiftMonth(1)} className="rounded p-1.5 text-gray-500 transition hover:bg-white hover:text-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:bg-gray-800 dark:hover:text-green-300" aria-label="Next month"><Chevron direction="right" /></button><div className="relative ml-auto"><button type="button" onClick={() => setShowSearch(open => !open)} className="rounded p-1.5 text-gray-500 transition hover:bg-white hover:text-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:bg-gray-800 dark:hover:text-green-300" aria-label="Search events"><SearchIcon /></button>{showSearch && <SearchPopover query={query} setQuery={setQuery} onClose={() => setShowSearch(false)} />}</div></div><div><p className="mb-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-gray-400 dark:text-gray-500">Non-working days</p><div className="space-y-2.5">{TYPES.map(type => <FilterRow key={type.key} type={type} checked={filters[type.key]} onChange={() => toggleFilter(type.key)} />)}</div><label className="mt-4 flex cursor-pointer items-center gap-2 text-[11px] text-gray-400 dark:text-gray-500"><input type="checkbox" checked={filters.INACTIVE} onChange={() => toggleFilter('INACTIVE')} className="h-3.5 w-3.5 rounded border-gray-300 text-green-700 focus:ring-green-600 dark:border-gray-600 dark:bg-gray-800" />Show inactive</label></div><div className="border-t border-green-100 pt-5 dark:border-green-950/45"><p className="mb-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-gray-400 dark:text-gray-500">Upcoming</p><div className="space-y-1">{upcoming.length ? upcoming.map(event => <button key={event.id || `${event.date}-${event.name}`} type="button" onClick={() => onSelectEvent(event)} className="flex w-full items-start gap-2 rounded-md px-1 py-1.5 text-left transition hover:bg-white/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:bg-gray-800/80"><span className={`mt-1.5 h-2 w-2 shrink-0 rounded-sm ${TYPE_BY_KEY[event.type]?.color || 'bg-gray-400'}`} aria-hidden="true" /><span className="min-w-0"><span className="block truncate text-xs font-medium text-gray-700 dark:text-gray-200">{event.name}</span><span className="block text-[10px] text-gray-400 dark:text-gray-500">{formatShortDate(event.date)}</span></span></button>) : <p className="text-xs text-gray-400 dark:text-gray-500">No upcoming configured days.</p>}</div></div></div>;
}

function SearchPopover({ query, setQuery, onClose }) {
    return <div className="absolute right-0 top-full z-30 mt-2 w-52 rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-900"><label className="sr-only" htmlFor="calendar-event-search">Search holidays and non-working days</label><div className="relative"><SearchIcon /><input id="calendar-event-search" autoFocus type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search events" className="h-11 w-full rounded-lg border border-gray-300 bg-white py-2 pl-7 pr-7 text-sm leading-5 text-gray-700 outline-none transition placeholder:text-gray-400 focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-green-500 dark:focus:ring-green-500/20" />{query && <button type="button" onClick={() => setQuery('')} className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700" aria-label="Clear search">&times;</button>}</div><button type="button" onClick={onClose} className="mt-2 text-[10px] font-medium text-green-700 hover:text-green-800 dark:text-green-300">Close</button></div>;
}

function FilterRow({ type, checked, onChange }) {
    return <label className="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" checked={checked} onChange={onChange} className="h-3.5 w-3.5 rounded border-gray-300 text-green-700 focus:ring-green-600 dark:border-gray-600 dark:bg-gray-800" /><span className={`h-2 w-2 rounded-sm ${type.color}`} aria-hidden="true" /><span>{type.label}</span></label>;
}

function CalendarHeader({ selectedDate, month, view, setView }) {
    return <header className="flex items-end justify-between gap-4 px-4 pb-5 pt-6 sm:px-6"><div><p className="mb-1 text-xs text-gray-400 dark:text-gray-500">{displayMonth(month.year, month.month)}</p><h2 className="text-2xl font-medium tracking-tight text-slate-800 dark:text-gray-100 sm:text-3xl">{selectedDateLabel(selectedDate)}</h2></div><nav className="flex items-center gap-4 pb-1" aria-label="Calendar view">{['month', 'year', 'agenda'].map(option => <button key={option} type="button" onClick={() => setView(option)} className={`border-b-2 pb-1 text-[11px] font-medium uppercase tracking-wide transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${view === option ? 'border-green-700 text-green-800 dark:border-green-400 dark:text-green-300' : 'border-transparent text-gray-400 hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-200'}`}>{option}</button>)}</nav></header>;
}

function MonthView({ month, selectedDate, today, eventsByDate, allEventsByDate, canManage, onSelectDate, onSelectEvent, onAdd }) {
    const [moreDate, setMoreDate] = useState(null);
    const days = getMonthDays(month.year, month.month, true);
    const selectEvent = event => { setMoreDate(null); onSelectEvent(event); };
    return <div className="overflow-x-auto custom-table-scrollbar"><div className="min-w-[700px]"><div className="grid grid-cols-7 border-y border-gray-100 dark:border-gray-800">{WEEKDAYS.map((day, index) => <div key={day} className={`px-2 py-2 text-center text-[10px] font-medium ${index >= 4 ? 'text-gray-400 dark:text-gray-600' : 'text-gray-500 dark:text-gray-400'}`}>{day}</div>)}</div><div className="grid grid-cols-7 gap-px bg-gray-100 dark:bg-gray-800">{days.map(day => <CalendarDay key={day.key} entry={day} selected={selectedDate === day.key} today={today === day.key} events={eventsByDate[day.key] || []} hasEvents={Boolean(allEventsByDate[day.key]?.length)} canManage={canManage} moreOpen={moreDate === day.key} onSelectDate={onSelectDate} onSelectEvent={selectEvent} onToggleMore={() => setMoreDate(current => current === day.key ? null : day.key)} onAdd={onAdd} />)}</div></div></div>;
}

function CalendarDay({ entry, selected, today, events, hasEvents, canManage, moreOpen, onSelectDate, onSelectEvent, onToggleMore, onAdd }) {
    const visibleEvents = events.slice(0, 3);
    const remainingEvents = events.slice(3);
    const surface = !entry.inCurrentMonth ? 'bg-gray-50/75 dark:bg-gray-950/65' : entry.weekend ? 'bg-gray-50/55 dark:bg-gray-800/45' : 'bg-white dark:bg-gray-900';
    const chooseDate = () => { onSelectDate(entry.key); if (canManage && entry.inCurrentMonth && !hasEvents) onAdd(entry.key); };
    const keyDown = event => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); chooseDate(); } };
    return <div role="gridcell" tabIndex={0} aria-label={formatReportDate(entry.key)} onClick={chooseDate} onKeyDown={keyDown} className={`group relative min-h-[86px] p-2 transition duration-150 ${surface} ${selected ? 'bg-green-50/65 ring-1 ring-inset ring-green-200 dark:bg-green-950/20 dark:ring-green-800' : ''} ${canManage && entry.inCurrentMonth && !hasEvents ? 'cursor-pointer hover:bg-green-50/50 dark:hover:bg-green-950/15' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50'}`}><div className="flex justify-end"><span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-[11px] font-medium ${today ? 'bg-green-800 text-white dark:bg-green-600' : entry.inCurrentMonth ? entry.weekend ? 'text-gray-400 dark:text-gray-500' : 'text-slate-700 dark:text-gray-200' : 'text-gray-300 dark:text-gray-700'}`}>{entry.day}</span></div><div className="mt-1 space-y-1">{visibleEvents.map(event => <EventBar key={event.id || `${event.date}-${event.name}`} event={event} onSelectEvent={onSelectEvent} />)}{remainingEvents.length > 0 && <div className="relative"><button type="button" onClick={event => { event.stopPropagation(); onToggleMore(); }} className="px-1 text-[10px] font-medium text-green-700 hover:text-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300">+{remainingEvents.length} more</button>{moreOpen && <MorePopover events={events} onSelectEvent={onSelectEvent} onClose={onToggleMore} />}</div>}</div></div>;
}

function EventBar({ event, onSelectEvent }) {
    const type = TYPE_BY_KEY[event.type] || TYPE_BY_KEY.OTHER;
    const label = `${type.label}: ${event.name}, ${formatReportDate(event.date)}`;
    return <button type="button" title={label} aria-label={label} onClick={clickEvent => { clickEvent.stopPropagation(); onSelectEvent(event); }} className={`block h-[22px] w-full truncate rounded-[4px] border px-1.5 text-left text-[10px] font-medium leading-5 transition duration-150 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${event.is_active ? type.event : 'border-gray-300 bg-gray-100 text-gray-500 opacity-70 line-through dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400'}`}><span className={`mr-1 inline-block h-1.5 w-1.5 rounded-sm ${event.is_active ? type.color : 'bg-gray-400'}`} aria-hidden="true" />{event.name}</button>;
}

function MorePopover({ events, onSelectEvent, onClose }) {
    return <div className="absolute left-0 top-full z-30 mt-1 w-56 rounded-lg border border-gray-200 bg-white p-2 shadow-xl dark:border-gray-700 dark:bg-gray-900" role="dialog" aria-label="More events"><div className="mb-1 flex items-center justify-between"><span className="px-1 text-[10px] font-medium text-gray-400">Events</span><button type="button" onClick={onClose} className="rounded p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Close event list">&times;</button></div><div className="space-y-1">{events.map(event => <EventBar key={event.id || `${event.date}-${event.name}`} event={event} onSelectEvent={onSelectEvent} />)}</div></div>;
}

function YearView({ year, selectedMonth, eventsByDate, today, onSelectMonth }) {
    return <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 12 }, (_, month) => <YearMonth key={month} year={year} month={month} selected={month === selectedMonth} eventsByDate={eventsByDate} today={today} onClick={() => onSelectMonth(month)} />)}</div>;
}

function YearMonth({ year, month, selected, eventsByDate, today, onClick }) {
    const days = getMonthDays(year, month);
    const leading = days[0]?.weekday ?? 0;
    return <button type="button" onClick={onClick} className={`rounded-lg border p-3 text-left transition duration-150 hover:border-green-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:border-green-800 ${selected ? 'border-green-300 bg-green-50/40 dark:border-green-800 dark:bg-green-950/20' : 'border-gray-100 bg-white dark:border-gray-800 dark:bg-gray-900'}`}><p className="mb-2 text-xs font-medium text-gray-800 dark:text-gray-100">{displayMonth(year, month, { month: 'long' })}</p><div className="grid grid-cols-7 gap-1 text-center text-[9px]"><div className="col-span-7 grid grid-cols-7 text-gray-400">{WEEKDAYS.map(day => <span key={day}>{day.slice(0, 1)}</span>)}</div>{Array.from({ length: leading }, (_, index) => <span key={`blank-${index}`} />)}{days.map(day => { const event = eventsByDate[day.key]?.[0]; return <span key={day.key} className={`relative rounded py-1 ${day.key === today ? 'bg-green-800 text-white dark:bg-green-600 dark:text-green-950' : day.weekend ? 'text-gray-400 dark:text-gray-600' : 'text-gray-600 dark:text-gray-300'}`}>{day.day}{event && <i className={`absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full ${TYPE_BY_KEY[event.type]?.color || 'bg-gray-400'}`} aria-hidden="true" />}</span>; })}</div></button>;
}

function AgendaView({ events, onSelectEvent }) {
    const sorted = [...events].sort((a, b) => String(a.date).localeCompare(String(b.date)));
    const groups = sorted.reduce((map, event) => { const date = parseDateKey(event.date); const key = date ? `${date.getFullYear()}-${pad(date.getMonth() + 1)}` : 'other'; (map[key] ||= []).push(event); return map; }, {});
    if (!sorted.length) return <div className="py-14 text-center text-sm text-gray-400 dark:text-gray-500">No configured events found.</div>;
    return <div className="space-y-5">{Object.entries(groups).map(([key, group]) => <section key={key}><h3 className="mb-2 border-b border-gray-100 pb-2 text-[11px] font-medium uppercase tracking-wide text-gray-400 dark:border-gray-800">{key === 'other' ? 'Other dates' : displayMonth(Number(key.slice(0, 4)), Number(key.slice(5)) - 1)}</h3><div className="space-y-1">{group.map(event => <AgendaRow key={event.id || `${event.date}-${event.name}`} event={event} onSelectEvent={onSelectEvent} />)}</div></section>)}</div>;
}

function AgendaRow({ event, onSelectEvent }) {
    const type = TYPE_BY_KEY[event.type] || TYPE_BY_KEY.OTHER;
    return <button type="button" onClick={() => onSelectEvent(event)} className="grid w-full grid-cols-[70px_1fr_auto] items-center gap-3 border-b border-gray-100 py-3 text-left transition hover:bg-green-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-800 dark:hover:bg-green-950/15 sm:grid-cols-[90px_1fr_160px_auto]"><span className="text-xs font-medium text-gray-500 dark:text-gray-400">{formatShortDate(event.date)}</span><span className="min-w-0"><span className="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">{event.name}</span>{event.remarks && <span className="block truncate text-[11px] text-gray-400">{event.remarks}</span>}</span><span className="hidden items-center gap-1.5 text-[11px] text-gray-500 sm:flex"><i className={`h-2 w-2 rounded-sm ${type.color}`} aria-hidden="true" />{type.label}</span><span className={`text-[10px] ${event.is_active ? 'text-green-700 dark:text-green-300' : 'text-gray-400'}`}>{event.is_active ? 'Active' : 'Inactive'}</span></button>;
}

function Chevron({ direction }) { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true"><path d={direction === 'left' ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6'} /></svg>; }
function PlusIcon() { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className="h-5 w-5" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>; }
function SearchIcon() { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6" /><path d="m15 15 4.5 4.5" /></svg>; }

function getMonthDays(year, month, includeOverflow = false) {
    const first = new Date(year, month, 1);
    const leading = mondayIndex(first);
    const total = new Date(year, month + 1, 0).getDate();
    const dates = Array.from({ length: total }, (_, index) => makeDay(new Date(year, month, index + 1), true));
    if (!includeOverflow) return dates;
    const before = Array.from({ length: leading }, (_, index) => makeDay(new Date(year, month, index - leading + 1), false));
    const count = Math.ceil((leading + total) / 7) * 7;
    const after = Array.from({ length: count - leading - total }, (_, index) => makeDay(new Date(year, month + 1, index + 1), false));
    return [...before, ...dates, ...after];
}

function makeDay(date, inCurrentMonth) {
    return { key: dateKey(date), day: date.getDate(), weekend: mondayIndex(date) >= 4, inCurrentMonth };
}

function parseDateKey(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return date.getFullYear() === Number(match[1]) && date.getMonth() === Number(match[2]) - 1 && date.getDate() === Number(match[3]) ? date : null;
}

function groupByDate(events) {
    return events.reduce((map, event) => { if (event?.date) map[event.date] = [...(map[event.date] || []), event]; return map; }, {});
}

function getUpcoming(events, today) {
    return [...events].filter(event => event.is_active && String(event.date) >= today).sort((a, b) => String(a.date).localeCompare(String(b.date))).slice(0, 5);
}

function formatShortDate(value) {
    const date = parseDateKey(value);
    return date ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' }).format(date) : formatReportDate(value);
}
