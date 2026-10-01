import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import Card from '@/Components/Card';
import { FloatingSelect } from '@/Components/Form';
import AppliedFilterChip from '@/Components/Form/AppliedFilterChip';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const FALLBACK = '\u2014';
const cards = [
    ['Expected Reports', 'expected'], ['Submitted Reports', 'submitted'], ['Submission Compliance', 'compliance_rate', '%'],
    ['Overdue / Not Submitted', 'overdue'], ['Pending PENRO Receipt', 'pending_receipt'],
];

function value(valueToShow, suffix = '') { return valueToShow === null || valueToShow === undefined ? FALLBACK : `${valueToShow}${suffix}`; }
function reportYearNow() { return Number(new Intl.DateTimeFormat('en', { year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date())); }

export function makeFilterChips(filters, options) {
    const chips = [{ key: 'year', label: 'Reporting Year', value: String(filters.year ?? reportYearNow()) }];
    if (filters.period) chips.push({ key: 'period', label: 'Period', value: options.periods?.find(option => String(option.value) === String(filters.period))?.label || String(filters.period) });
    if (filters.domain && filters.domain !== 'all') chips.push({ key: 'domain', label: 'Domain', value: { pa: 'PA Monitoring', engp: 'ENGP Monitoring' }[filters.domain] || String(filters.domain) });
    if (filters.office) chips.push({ key: 'office', label: 'Office', value: String(filters.office) });
    if (filters.protected_area_id) chips.push({ key: 'protected_area_id', label: 'Protected Area', value: options.protected_areas?.find(area => String(area.id) === String(filters.protected_area_id))?.name || String(filters.protected_area_id) });
    if (filters.workflow) chips.push({ key: 'workflow', label: 'Report Family', value: options.families?.find(option => String(option.value) === String(filters.workflow))?.label || String(filters.workflow) });
    if (filters.status) chips.push({ key: 'status', label: 'Status', value: String(filters.status) });
    return chips;
}
export function reportFilterParams(values) {
    return { year: values.year || undefined, domain: values.domain || undefined, period: values.period || undefined, office: values.office || undefined, protected_area_id: values.protected_area_id || undefined, workflow: values.workflow || undefined, status: values.status || undefined };
}

function PerformanceTable({ title, rows = [], first = 'Report Family' }) {
    const columns = [first, 'Expected', 'Submitted', 'Compliance', 'Overdue'];
    return <Card className="overflow-hidden border border-gray-100 shadow-sm dark:border-gray-800"><div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h2 className="font-bold text-gray-900 dark:text-white">{title}</h2><p className="text-xs text-gray-500">Expected-versus-submitted management view</p></div><div className="overflow-x-auto"><table className="cds-data-table min-w-[620px] w-full text-left text-xs"><thead className="bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500 dark:bg-gray-800/60"><tr>{columns.map(label => <th key={label} className="px-3 py-2.5">{label}</th>)}</tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{rows.length ? rows.map(row => <tr key={row.label} className="text-gray-700 dark:text-gray-200"><td className="max-w-[260px] px-3 py-2.5 font-semibold">{row.label}</td><td className="px-3 py-2.5">{value(row.expected)}</td><td className="px-3 py-2.5">{value(row.submitted)}</td><td className="px-3 py-2.5">{value(row.compliance_rate, '%')}</td><td className="px-3 py-2.5">{value(row.overdue)}</td></tr>) : <tr><td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">No Data</td></tr>}</tbody></table></div></Card>;
}

function TrendChart({ rows = [] }) {
    return <Card className="border border-gray-100 shadow-sm dark:border-gray-800"><div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h2 className="font-bold text-gray-900 dark:text-white">Period Compliance Trend</h2><p className="text-xs text-gray-500">Expected and submitted requirements for the selected scope</p></div>{rows.length ? <div className="h-64 p-3"><ResponsiveContainer width="100%" height="100%"><BarChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 8 }}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="period" tick={{ fontSize: 10 }} /><YAxis allowDecimals={false} width={32} /><Tooltip /><Bar dataKey="expected" name="Expected" fill="#86efac" radius={[4, 4, 0, 0]} /><Bar dataKey="submitted" name="Submitted" fill="#166534" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div> : <p className="p-8 text-center text-sm text-gray-500">No period data available.</p>}</Card>;
}

export default function Index({ report = {} }) {
    const filters = report.filters || {};
    const options = report.filter_options || {};
    const [year, setYear] = useState(filters.year || '');
    const [domain, setDomain] = useState(filters.domain || 'all');
    const [period, setPeriod] = useState(filters.period || '');
    const [office, setOffice] = useState(filters.office || '');
    const [protectedAreaId, setProtectedAreaId] = useState(filters.protected_area_id || '');
    const [workflow, setWorkflow] = useState(filters.workflow || '');
    const [status, setStatus] = useState(filters.status || '');
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [filtersClosing, setFiltersClosing] = useState(false);
    const filtersTriggerRef = useRef(null);
    const filtersPanelRef = useRef(null);
    const filtersCloseTimerRef = useRef(null);
    const [filtersPanelStyle, setFiltersPanelStyle] = useState(null);
    const appliedChips = makeFilterChips(filters, options);

    const closeFilters = useCallback((restoreFocus = false) => {
        if (!filtersOpen) return;
        if (filtersClosing) {
            if (restoreFocus) filtersTriggerRef.current?.focus();
            return;
        }
        const finishClose = () => {
            setFiltersOpen(false);
            setFiltersClosing(false);
            filtersCloseTimerRef.current = null;
            if (restoreFocus) filtersTriggerRef.current?.focus();
        };
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            finishClose();
            return;
        }
        setFiltersClosing(true);
        window.clearTimeout(filtersCloseTimerRef.current);
        filtersCloseTimerRef.current = window.setTimeout(finishClose, 240);
    }, [filtersOpen, filtersClosing]);
    const toggleFilters = () => {
        if (filtersOpen) closeFilters();
        else { setFiltersClosing(false); setFiltersOpen(true); }
    };

    useEffect(() => () => window.clearTimeout(filtersCloseTimerRef.current), []);

    useEffect(() => {
        setYear(filters.year || ''); setDomain(filters.domain || 'all'); setPeriod(filters.period || ''); setOffice(filters.office || '');
        setProtectedAreaId(filters.protected_area_id || ''); setWorkflow(filters.workflow || ''); setStatus(filters.status || '');
    }, [filters.year, filters.domain, filters.period, filters.office, filters.protected_area_id, filters.workflow, filters.status]);

    const submitFilters = values => router.get(route('reports.index'), reportFilterParams(values), { preserveState: true, preserveScroll: true, replace: true });
    const apply = event => { event.preventDefault(); submitFilters({ year, domain, period, office, protected_area_id: protectedAreaId, workflow, status }); closeFilters(); };
    const reset = () => { closeFilters(); router.get(route('reports.index')); };
    const removeFilter = key => {
        const next = {
            year: key === 'year' ? reportYearNow() : filters.year,
            domain: key === 'domain' ? 'all' : filters.domain,
            period: key === 'period' ? '' : filters.period,
            office: key === 'office' ? '' : filters.office,
            protected_area_id: key === 'protected_area_id' ? '' : filters.protected_area_id,
            workflow: key === 'workflow' ? '' : filters.workflow,
            status: key === 'status' ? '' : filters.status,
        };
        setYear(next.year); setDomain(next.domain); setPeriod(next.period); setOffice(next.office);
        setProtectedAreaId(next.protected_area_id); setWorkflow(next.workflow); setStatus(next.status);
        submitFilters(next);
    };
    useEffect(() => {
        if (!filtersOpen) return undefined;
        const positionPanel = () => {
            const trigger = filtersTriggerRef.current;
            if (!trigger) return;
            const rect = trigger.getBoundingClientRect();
            const margin = 12;
            const width = Math.min(736, window.innerWidth - margin * 2);
            const left = window.innerWidth < 768
                ? margin
                : Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin));
            const desiredHeight = Math.min(filtersPanelRef.current?.scrollHeight || 480, window.innerHeight * 0.8);
            const roomBelow = Math.max(0, window.innerHeight - rect.bottom - 8 - margin);
            const roomAbove = Math.max(0, rect.top - 8 - margin);
            const openAbove = roomBelow < desiredHeight && roomAbove > roomBelow;
            const availableHeight = openAbove ? roomAbove : roomBelow;
            const maxHeight = Math.max(120, Math.min(window.innerHeight * 0.8, availableHeight));
            const top = openAbove ? Math.max(margin, rect.top - 8 - maxHeight) : rect.bottom + 8;
            setFiltersPanelStyle({ position: 'fixed', left, top, width, maxHeight: `${maxHeight}px` });
        };
        const onKeyDown = event => { if (event.key === 'Escape') { event.preventDefault(); closeFilters(true); } };
        const onPointerDown = event => {
            const target = event.target;
            if (filtersPanelRef.current?.contains(target) || filtersTriggerRef.current?.contains(target) || target.closest?.('.cds-select-menu, .cds-filter-dropdown, .cds-menu-panel')) return;
            closeFilters();
        };
        positionPanel();
        window.addEventListener('resize', positionPanel);
        window.addEventListener('scroll', positionPanel, true);
        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onPointerDown, true);
        return () => { window.removeEventListener('resize', positionPanel); window.removeEventListener('scroll', positionPanel, true); document.removeEventListener('keydown', onKeyDown); document.removeEventListener('pointerdown', onPointerDown, true); };
    }, [filtersOpen, closeFilters]);
    const exportUrl = format => route('reports.export', { format, year, domain, period, office, protected_area_id: protectedAreaId, workflow, status });
    const summary = report.summary || {};

    return <AuthenticatedLayout title="Reports"><PageHeader title="Executive Report Monitoring" description="Formal management summary for authorized PA and ENGP report compliance and timeliness." />
        <div className="mt-4 flex flex-wrap gap-2" aria-label="Report exports"><a href={exportUrl('pdf')} data-cds-action="true" data-cds-action-variant="primary" className="rounded-lg px-3 py-2 text-xs font-bold">Export PDF</a><a href={exportUrl('xlsx')} data-cds-action="true" data-cds-action-variant="primary" className="rounded-lg px-3 py-2 text-xs font-bold">Export Excel</a><a href={exportUrl('docx')} data-cds-action="true" data-cds-action-variant="primary" className="rounded-lg px-3 py-2 text-xs font-bold">Export Word</a></div>
        <div className="mt-4 space-y-5">
            <div className="relative rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div className="flex flex-wrap items-center gap-2">
                    <button ref={filtersTriggerRef} type="button" aria-expanded={filtersOpen && !filtersClosing} aria-controls="executive-filter-panel" onClick={toggleFilters} className="inline-flex h-10 min-w-[10rem] shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-800 hover:border-green-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"><svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.7" className={`h-4 w-4 transition-transform duration-200 motion-reduce:transition-none ${filtersOpen && !filtersClosing ? 'rotate-180' : ''}`}><path d="M3 5h14M5.5 10h9M8 15h4" strokeLinecap="round" /></svg><span className="transition-opacity duration-200 motion-reduce:transition-none">{appliedChips.length ? `Filters · ${appliedChips.length} selected` : 'Filters'}</span></button>
                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5" aria-label="Applied filters">{appliedChips.map(chip => <AppliedFilterChip key={chip.key} label={chip.label} value={chip.value} onRemove={() => removeFilter(chip.key)} />)}</div>
                    <button type="button" onClick={reset} className="h-9 shrink-0 rounded-lg px-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:text-gray-300 dark:hover:bg-gray-800">Clear all</button>
                </div>
                {filtersOpen && <div ref={filtersPanelRef} id="executive-filter-panel" role="region" aria-label="Report filters" aria-hidden={filtersClosing} inert={filtersClosing} style={filtersPanelStyle || { position: 'fixed', left: 12, top: 88, width: 'min(46rem, calc(100vw - 24px))' }} className={`${filtersClosing ? 'cds-filter-panel-exit' : 'cds-filter-panel-enter'} z-[100] overflow-y-auto rounded-2xl border border-gray-200 bg-white p-4 shadow-2xl dark:border-gray-700 dark:bg-gray-900`}>
                    <div className="mb-4 flex items-start justify-between gap-3"><div><h2 className="text-sm font-bold text-gray-900 dark:text-white">Filters</h2><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Adjust criteria, then apply to refresh results.</p></div><button type="button" onClick={() => closeFilters()} className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-lg text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="Close filters">{'\u00d7'}</button></div>
                    <form onSubmit={apply} className="space-y-4">
                        <section className="grid gap-3 border-b border-gray-100 pb-4 dark:border-gray-800 sm:grid-cols-2"><h3 className="col-span-full text-xs font-bold uppercase tracking-wide text-gray-500">Reporting period</h3><FloatingSelect id="executive-year" label="Reporting Year" value={year} onChange={event => setYear(event.target.value)} size="sm">{(options.years || []).map(item => <option key={item} value={item}>{item}</option>)}</FloatingSelect><FloatingSelect id="executive-period" label="Period" value={period} onChange={event => setPeriod(event.target.value)} size="sm"><option value="">All periods</option>{(options.periods || []).map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</FloatingSelect></section>
                        <section className="grid gap-3 border-b border-gray-100 pb-4 dark:border-gray-800 sm:grid-cols-2 lg:grid-cols-3"><h3 className="col-span-full text-xs font-bold uppercase tracking-wide text-gray-500">Coverage</h3><FloatingSelect id="executive-domain" label="Domain" value={domain} onChange={event => setDomain(event.target.value)} size="sm"><option value="all">All Authorized</option><option value="pa">PA Monitoring</option><option value="engp">ENGP Monitoring</option></FloatingSelect><FloatingSelect id="executive-office" label="Office" value={office} onChange={event => setOffice(event.target.value)} size="sm"><option value="">All offices</option>{(options.offices || []).map(item => <option key={item} value={item}>{item}</option>)}</FloatingSelect><FloatingSelect id="executive-pa" label="Protected Area" value={protectedAreaId} onChange={event => setProtectedAreaId(event.target.value)} size="sm"><option value="">All protected areas</option>{(options.protected_areas || []).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</FloatingSelect></section>
                        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><h3 className="col-span-full text-xs font-bold uppercase tracking-wide text-gray-500">Report</h3><FloatingSelect id="executive-family" label="Report Family" value={workflow} onChange={event => setWorkflow(event.target.value)} size="sm"><option value="">All report families</option>{(options.families || []).map(item => <option key={`${item.domain}-${item.value}`} value={item.value}>{item.label}</option>)}</FloatingSelect><FloatingSelect id="executive-status" label="Status" value={status} onChange={event => setStatus(event.target.value)} size="sm"><option value="">All statuses</option>{(options.statuses || []).map(item => <option key={item}>{item}</option>)}</FloatingSelect><div className="flex items-end gap-2"><button type="submit" className="h-10 rounded-lg bg-green-800 px-4 text-xs font-bold text-white hover:bg-green-900">Apply</button><button type="button" onClick={reset} className="h-10 rounded-lg border border-gray-300 bg-white px-4 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Reset</button></div></section>
                    </form>
                </div>}
                <p className="mt-2 text-xs text-gray-500">{filters.scope_label || 'All authorized reports'} {'\u00b7'} active effective registry requirements are used for expected counts.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">{cards.map(([label, key, suffix = '']) => <Card key={key} className="border border-gray-100 shadow-sm dark:border-gray-800"><p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">{label}</p><p className="mt-1 text-2xl font-black text-gray-900 dark:text-white">{value(summary[key], suffix)}</p></Card>)}</div><Card className="border border-green-100 bg-green-50/60 shadow-sm dark:border-green-900/40 dark:bg-green-950/20"><p className="text-[10px] font-bold uppercase tracking-wider text-green-700 dark:text-green-300">Executive Interpretation</p><p className="mt-1 text-sm text-gray-700 dark:text-gray-200">{report.interpretation || 'No Data'}</p></Card><TrendChart rows={report.period_trend} /><PerformanceTable title="Protected Area Performance" rows={report.pa_performance} first="Protected Area" /><PerformanceTable title="Office Performance" rows={report.office_performance} first="Office" /><PerformanceTable title="Report Family Performance" rows={report.family_performance} /><Card className="border border-gray-100 shadow-sm dark:border-gray-800"><div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h2 className="font-bold text-gray-900 dark:text-white">Timeliness</h2></div><div className="grid grid-cols-2 gap-3 p-4 text-sm sm:grid-cols-4"><div><p className="text-xs text-gray-500">On-time rated</p><p className="font-bold">{value(report.timeliness?.on_time)}</p></div><div><p className="text-xs text-gray-500">Late rated</p><p className="font-bold">{value(report.timeliness?.late)}</p></div><div><p className="text-xs text-gray-500">Rated records</p><p className="font-bold">{value(report.timeliness?.rated)}</p></div><div><p className="text-xs text-gray-500">Average days complied</p><p className="font-bold">{value(report.timeliness?.average_days_complied, ' d')}</p></div></div></Card><Card className="overflow-hidden border border-amber-100 shadow-sm dark:border-amber-900/40"><div className="border-b border-amber-100 px-4 py-3 dark:border-amber-900/40"><h2 className="font-bold text-gray-900 dark:text-white">Reports Requiring Attention</h2><p className="text-xs text-gray-500">Authorized reports that are overdue or awaiting receipt</p></div><div className="overflow-x-auto"><table className="cds-data-table min-w-[850px] w-full text-left text-xs"><thead className="bg-amber-50 text-[10px] uppercase text-gray-500 dark:bg-amber-950/20"><tr>{['Tracking No.', 'Report', 'PA / Office', 'Period', 'Status', 'Deadline', 'Reason'].map(label => <th key={label} className="px-3 py-2.5">{label}</th>)}</tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{(report.attention || []).length ? report.attention.map((row, index) => <tr key={`${row.tracking_number || row.report}-${index}`}><td className="px-3 py-2.5 font-semibold">{row.tracking_number || FALLBACK}</td><td className="px-3 py-2.5">{row.report || FALLBACK}</td><td className="px-3 py-2.5">{row.scope || FALLBACK}</td><td className="px-3 py-2.5">{row.period || FALLBACK}</td><td className="px-3 py-2.5">{row.status || FALLBACK}</td><td className="px-3 py-2.5">{row.deadline || FALLBACK}</td><td className="px-3 py-2.5">{row.reason || FALLBACK}</td></tr>) : <tr><td colSpan="7" className="px-3 py-6 text-center text-gray-500">No reports require attention for this scope.</td></tr>}</tbody></table></div></Card>
        </div>
    </AuthenticatedLayout>;
}
