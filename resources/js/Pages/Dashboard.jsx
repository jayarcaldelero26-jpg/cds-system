import CrudTable from '@/Components/Crud/CrudTable';
import { FloatingSelect } from '@/Components/Form';
import StatusBadge from '@/Components/StatusBadge';
import TimelinessBadge from '@/Components/TimelinessBadge';
import MonitoringPageHeader from '@/Components/Dashboard/MonitoringPageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from '@iconify/react';
import { parseDateOnly } from '@/Utils/dateFormatters';

const DASH = '—';
const DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

const formatDate = value => {
    if (!value) return DASH;
    const date = parseDateOnly(String(value));
    return date ? DATE_FORMATTER.format(date) : DASH;
};

const percent = value => String(Number(value || 0).toFixed(Number(value || 0) % 1 === 0 ? 0 : 1)) + '%';

const statusVariant = value => {
    const normalized = String(value || '').toLowerCase();
    if (normalized.includes('not yet')) return 'inactive';
    if (normalized.includes('submitted') || normalized.includes('completed')) return 'active';
    if (normalized.includes('overdue')) return 'inactive';
    if (normalized.includes('ongoing') || normalized.includes('preparation')) return 'pending';
    return 'info';
};

function MetricCard({ label, value, helper, tone = 'green', icon = 'solar:document-text-linear' }) {
    const tones = {
        green: { card: 'border-green-100 bg-green-50 text-green-950 dark:border-green-900/60 dark:bg-green-950/30 dark:text-green-100', icon: 'bg-green-600 text-white', accent: 'border-l-green-600' },
        amber: { card: 'border-amber-100 bg-amber-50 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100', icon: 'bg-amber-500 text-white', accent: 'border-l-amber-500' },
        red: { card: 'border-red-100 bg-red-50 text-red-950 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-100', icon: 'bg-red-600 text-white', accent: 'border-l-red-600' },
        blue: { card: 'border-blue-100 bg-blue-50 text-blue-950 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-100', icon: 'bg-blue-600 text-white', accent: 'border-l-blue-600' },
    };
    const selected = tones[tone] || tones.green;
    return <article className={'flex min-h-[112px] items-start gap-3 rounded-2xl border border-l-4 p-3.5 transition duration-200 hover:-translate-y-0.5 ' + selected.accent + ' ' + selected.card}>
        <span className={'flex h-10 w-10 shrink-0 items-center justify-center rounded-full ' + selected.icon}><Icon icon={icon} width="20" height="20" /></span>
        <div className="min-w-0 pt-0.5">
            <p className="text-[11px] font-bold uppercase tracking-[0.1em] opacity-70">{label}</p>
            <p className="mt-1 text-[26px] font-black leading-none tracking-tight">{value}</p>
            {helper && <p className="mt-1.5 truncate text-[11px] font-medium opacity-70">{helper}</p>}
        </div>
    </article>;
}

function SectionCard({ title, subtitle, icon, children, className = '' }) {
    return <section className={'overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 ' + className}>
        <div className="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
            {icon && <Icon icon={icon} width="17" height="17" className="shrink-0 text-green-700 dark:text-green-400" />}
            <div className="min-w-0">
                <h2 className="text-xs font-bold uppercase tracking-[0.08em] text-gray-900 dark:text-white">{title}</h2>
                {subtitle && <p className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{subtitle}</p>}
            </div>
        </div>
        {children}
    </section>;
}

function Progress({ value }) {
    return <div className="flex items-center gap-2"><div className="h-2 min-w-20 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><div className="h-full rounded-full bg-green-600" style={{ width: Math.min(100, Math.max(0, Number(value || 0))) + '%' }} /></div><span className="w-12 text-right text-xs font-bold text-gray-700 dark:text-gray-200">{percent(value)}</span></div>;
}

function ViewTabs({ view }) {
    const tabs = [['pa', 'PA Monitoring'], ['engp', 'ENGP Monitoring']];
    return <nav aria-label="Dashboard monitoring views" className="grid h-10 grid-cols-2 overflow-hidden rounded-lg border border-green-800 bg-white shadow-sm dark:border-green-700 dark:bg-gray-900">{tabs.map(([key, label]) => <button key={key} type="button" onClick={() => router.get(route('dashboard'), { view: key }, { preserveState: true, preserveScroll: true, replace: true })} className={'px-3 py-2 text-xs font-bold transition ' + (view === key ? 'bg-green-800 text-white' : 'text-green-800 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-950/40')} aria-current={view === key ? 'page' : undefined}>{label}</button>)}</nav>;
}

function PaHeaderPeriod({ filters = {} }) {
    const ranges = { 'Quarter 1': ['January 1', 'March 31'], 'Quarter 2': ['April 1', 'June 30'], 'Quarter 3': ['July 1', 'September 30'], 'Quarter 4': ['October 1', 'December 31'] };
    const year = filters.year || 'Current';
    const range = ranges[filters.period];
    return { title: filters.period ? filters.period + ', ' + year : year + ' Monitoring', subtitle: range ? range[0] + ' – ' + range[1] + ', ' + year : filters.period ? 'Selected reporting period' : 'All reporting periods' };
}

function EngpHeaderPeriod({ data = {} }) {
    const filters = data.filters || {};
    const period = (data.filterOptions?.periods || []).find(item => item.value === filters.period);
    const frequency = filters.frequency && filters.frequency !== 'all' ? filters.frequency[0].toUpperCase() + filters.frequency.slice(1) : 'All frequencies';
    return { title: (filters.year || 2026) + ' Monitoring', subtitle: period?.label || frequency };
}

function EngpFilters({ data }) {
    const { filterOptions = {}, filters = {} } = data;
    const [values, setValues] = useState({ year: filters.year || 2026, period: filters.period || '', office: filters.office || '', frequency: filters.frequency || 'all' });
    useEffect(() => setValues({ year: filters.year || 2026, period: filters.period || '', office: filters.office || '', frequency: filters.frequency || 'all' }), [filters.year, filters.period, filters.office, filters.frequency]);
    const navigate = next => router.get(route('dashboard'), { view: 'engp', ...next, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
    const setFilter = (key, value) => setValues(current => ({ ...current, [key]: value }));
    const reset = () => navigate({ year: filterOptions.years?.[0] || 2026, period: '', office: '', frequency: 'all' });
    return <section className="rounded-2xl border border-gray-100 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900"><div className="mb-2 flex items-center justify-between"><p className="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 dark:text-gray-400">Monitoring filters</p><span className="text-[11px] text-gray-400">ENGP report tracking only</span></div><div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-6"><FloatingSelect label="Year" size="sm" focusTone="green" value={values.year} onChange={event => setFilter('year', event.target.value)}>{(filterOptions.years || [2026]).map(year => <option key={year} value={year}>{year}</option>)}</FloatingSelect><FloatingSelect label="Period" size="sm" focusTone="green" value={values.period} onChange={event => setFilter('period', event.target.value)}><option value="">All / Annual</option>{(filterOptions.periods || []).map(period => <option key={period.value} value={period.value}>{period.label}{period.frequency ? ' · ' + period.frequency : ''}</option>)}</FloatingSelect><FloatingSelect label="Office" size="sm" focusTone="green" value={values.office} onChange={event => setFilter('office', event.target.value)}><option value="">All Offices</option>{(filterOptions.offices || []).map(office => <option key={office} value={office}>{office}</option>)}</FloatingSelect><FloatingSelect label="Frequency" size="sm" focusTone="green" value={values.frequency} onChange={event => setFilter('frequency', event.target.value)}>{(filterOptions.frequencies || []).map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</FloatingSelect><button type="button" onClick={() => navigate(values)} className="inline-flex h-10 items-center justify-center gap-1.5 rounded-lg bg-green-800 px-3 text-xs font-bold text-white shadow-sm hover:bg-green-900"><Icon icon="solar:filter-linear" width="15" />Apply Filters</button><button type="button" onClick={reset} className="h-10 rounded-lg border border-gray-200 px-3 text-xs font-bold text-gray-600 hover:border-green-300 hover:text-green-800 dark:border-gray-700 dark:text-gray-300">Reset</button></div></section>;
}

function EngpKpis({ summary }) {
    return <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6"><MetricCard label="Scheduled Requirements" value={summary?.scheduled_requirements ?? summary?.expected ?? 0} helper="Generated active registry obligations" icon="solar:document-list-linear" tone="blue" /><MetricCard label="Reports Submitted" value={summary?.reports_submitted ?? summary?.submitted ?? 0} helper="Matched record with PENRO receipt" icon="solar:check-circle-linear" tone="green" /><MetricCard label="Within Preparation Period" value={summary?.within_preparation_period ?? 0} helper="Not due within the reminder window" icon="solar:calendar-linear" tone="blue" /><MetricCard label="Ongoing Preparation" value={summary?.ongoing_preparation ?? 0} helper="Due today or within the reminder window" icon="solar:clock-circle-linear" tone="amber" /><MetricCard label="Not Yet Submitted / Overdue" value={summary?.not_yet_submitted ?? summary?.overdue ?? 0} helper="Deadline passed without PENRO receipt" icon="solar:danger-triangle-linear" tone="red" /><MetricCard label="Compliance Rate" value={percent(summary?.compliance_rate)} helper="Reports submitted / scheduled requirements" icon="solar:chart-2-linear" tone="green" /></div>;
}

function EngpPerformance({ officePerformance = [], reportTypeCompliance = [] }) {
    return <div className="grid gap-3 xl:grid-cols-2"><SectionCard title="Office Performance" subtitle="Authorized ENGP offices; scheduled requirements matched to actual submissions" icon="solar:buildings-2-linear" className="h-[300px]"><div className="max-h-[244px] overflow-auto"><table className="min-w-full text-left text-xs"><thead className="sticky top-0 z-10 bg-gray-50 text-[10px] uppercase text-gray-500 dark:bg-gray-800/95 dark:text-gray-400"><tr><th className="px-3 py-2">Office</th><th className="px-2 py-2 text-right">Scheduled</th><th className="px-2 py-2 text-right">Submitted</th><th className="px-2 py-2 text-right">Within</th><th className="px-2 py-2 text-right">Ongoing</th><th className="px-2 py-2 text-right">Not Yet Submitted</th><th className="min-w-28 px-3 py-2">Compliance</th></tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{officePerformance.map(row => <tr key={row.office}><td className="px-3 py-2 font-semibold text-gray-900 dark:text-white">{row.office}</td><td className="px-2 py-2 text-right">{row.scheduled_requirements ?? row.expected}</td><td className="px-2 py-2 text-right">{row.reports_submitted ?? row.submitted}</td><td className="px-2 py-2 text-right">{row.within_preparation_period ?? 0}</td><td className="px-2 py-2 text-right">{row.ongoing_preparation ?? 0}</td><td className="px-2 py-2 text-right">{row.not_yet_submitted ?? row.overdue}</td><td className="px-3 py-2"><Progress value={row.compliance_rate} /></td></tr>)}</tbody></table>{!officePerformance.length && <p className="px-3 py-8 text-center text-xs text-gray-500">No authorized ENGP requirements match the selected filters.</p>}</div></SectionCard><SectionCard title="Report Type Compliance" subtitle="Active definitions from the ENGP workflow registry" icon="solar:chart-square-linear" className="h-[300px]"><div className="max-h-[244px] overflow-auto"><table className="min-w-full text-left text-xs"><thead className="sticky top-0 z-10 bg-gray-50 text-[10px] uppercase text-gray-500 dark:bg-gray-800/95 dark:text-gray-400"><tr><th className="px-3 py-2">Report / Activity</th><th className="px-2 py-2">Frequency</th><th className="px-2 py-2 text-right">Scheduled</th><th className="px-2 py-2 text-right">Submitted</th><th className="px-2 py-2 text-right">Within</th><th className="px-2 py-2 text-right">Ongoing</th><th className="px-2 py-2 text-right">Not Yet Submitted</th><th className="min-w-28 px-3 py-2">Compliance</th></tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{reportTypeCompliance.map(row => <tr key={row.workflow_key}><td className="px-3 py-2"><p className="font-semibold text-gray-900 dark:text-white">{row.label}</p><p className="mt-0.5 text-[11px] text-gray-500">{row.activity}</p></td><td className="px-2 py-2">{row.frequency}</td><td className="px-2 py-2 text-right">{row.scheduled_requirements ?? row.expected}</td><td className="px-2 py-2 text-right">{row.reports_submitted ?? row.submitted}</td><td className="px-2 py-2 text-right">{row.within_preparation_period ?? 0}</td><td className="px-2 py-2 text-right">{row.ongoing_preparation ?? 0}</td><td className="px-2 py-2 text-right">{row.not_yet_submitted ?? row.overdue}</td><td className="px-3 py-2"><Progress value={row.compliance_rate} /></td></tr>)}</tbody></table>{!reportTypeCompliance.length && <p className="px-3 py-8 text-center text-xs text-gray-500">No report definitions match the selected filters.</p>}</div></SectionCard></div>;
}

function AlertMetric({ label, value, tone, icon }) {
    const tones = { amber: 'border-amber-100 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100', red: 'border-red-100 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100', gray: 'border-gray-100 bg-gray-50 text-gray-900 dark:border-gray-800 dark:bg-gray-800/70 dark:text-white' };
    return <div className={'flex items-center gap-2 rounded-xl border p-2 ' + (tones[tone] || tones.gray)}><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70"><Icon icon={icon} width="16" height="16" /></span><div><p className="text-[10px] font-bold uppercase opacity-70">{label}</p><p className="mt-0.5 text-xl font-extrabold">{value ?? 0}</p></div></div>;
}

function AlertsCard({ alerts = {}, canViewComplianceAlerts }) {
    return <SectionCard title="Compliance Alerts & History" subtitle="Existing alert data; dashboard load never sends alerts" icon="solar:bell-bing-linear" className="h-[330px]"><div className="grid gap-2 p-3 sm:grid-cols-2"><AlertMetric label="Due within 3 days" value={alerts.due_within_3_days} tone="amber" icon="solar:clock-circle-linear" /><AlertMetric label="Due today" value={alerts.due_today} tone="gray" icon="solar:calendar-mark-linear" /><AlertMetric label="Overdue" value={alerts.overdue} tone="red" icon="solar:danger-triangle-linear" /><AlertMetric label="Alerts sent today" value={alerts.alerts_sent_today} tone="gray" icon="solar:letter-linear" /></div><div className="space-y-1.5 px-3 pb-3 text-[11px] text-gray-600 dark:text-gray-300"><p><span className="font-bold">Last memorandum sent:</span> {alerts.last_memorandum_sent ? formatDate(alerts.last_memorandum_sent) : 'None recorded today'}</p><p><span className="font-bold">Recent recipient offices:</span> {alerts.recent_recipient_offices?.join(', ') || 'None recorded'}</p>{canViewComplianceAlerts && alerts.view_url && <a href={alerts.view_url} className="inline-flex items-center gap-1 pt-1 font-bold text-green-700 hover:text-green-900 dark:text-green-400">View Compliance Alerts <Icon icon="solar:arrow-right-up-linear" width="14" /></a>}</div></SectionCard>;
}

function EngpTable({ data }) {
    const { rows = [], pagination = {}, filters = {} } = data;
    const navigate = page => router.get(route('dashboard'), { view: 'engp', ...filters, page }, { preserveState: true, preserveScroll: true, replace: true });
    const columns = [
        { key: 'office', label: 'Office', headerClassName: 'min-w-36', render: row => <span className="font-semibold text-gray-900 dark:text-white">{row.office}</span> },
        { key: 'report', label: 'Report / Activity', headerClassName: 'min-w-64', render: row => <div><p className="font-semibold text-gray-900 dark:text-white">{row.report}</p><p className="mt-0.5 text-xs text-gray-500">{row.activity}</p></div> },
        { key: 'record_source', label: 'Record Source', headerClassName: 'min-w-44', render: row => <span className={row.submission_matched ? 'font-semibold text-green-700 dark:text-green-300' : 'text-gray-600 dark:text-gray-300'}>{row.record_source}</span> },
        { key: 'frequency', label: 'Frequency', cellClassName: 'whitespace-nowrap' },
        { key: 'reporting_period', label: 'Reporting Period', cellClassName: 'whitespace-nowrap' },
        { key: 'deadline', label: 'Deadline', cellClassName: 'whitespace-nowrap', render: row => formatDate(row.deadline) },
        { key: 'date_released_cenro', label: 'Date Released by CENRO', cellClassName: 'whitespace-nowrap', render: row => formatDate(row.date_released_cenro) },
        { key: 'date_received', label: 'Date Received by PENRO', cellClassName: 'whitespace-nowrap', render: row => formatDate(row.date_received) },
        { key: 'days_complied', label: 'Days Complied', cellClassName: 'text-center', render: row => row.days_complied ?? DASH },
        { key: 'timeliness', label: 'Timeliness', cellClassName: 'whitespace-nowrap', render: row => row.timeliness ? <TimelinessBadge value={row.timeliness} /> : DASH },
        { key: 'status', label: 'Submission Status', headerClassName: 'min-w-56', render: row => <div><StatusBadge variant={statusVariant(row.status)}>{row.status}</StatusBadge><p className="mt-1 text-[11px] text-gray-500">{row.submission_matched ? 'Matched actual submission' : 'Scheduled only'}</p></div> },
        { key: 'action', label: 'MOV / Action', cellClassName: 'whitespace-nowrap', render: row => <div className="flex flex-col gap-1"><span className="text-xs text-gray-500">{row.mov_status || DASH}</span><a href={row.source_url} onClick={event => event.stopPropagation()} className="font-bold text-green-700 hover:text-green-900 dark:text-green-400">View Details</a></div> },
    ];
    const total = pagination.total || 0;
    const first = total ? ((pagination.current_page - 1) * pagination.per_page) + 1 : 0;
    const last = total ? Math.min(pagination.current_page * pagination.per_page, total) : 0;
    return <CrudTable title="Submission Monitoring" subtitle="Scheduled registry requirements matched to actual encoded submissions; missing dates remain unrecorded" rows={rows} columns={columns} rowKey="id" className="h-[330px]" tableClassName="min-w-[1900px]" tableContainerClassName="h-[270px]" compact emptyTitle="No ENGP requirements match the selected filters." emptyDescription="Try another office, period, or frequency." pagination={<div className="flex flex-col gap-1 text-[11px] text-gray-500 sm:flex-row sm:items-center sm:justify-between"><span>Showing {first}–{last} of {total} scheduled requirements</span>{pagination.last_page > 1 && <div className="flex items-center gap-1.5"><button type="button" disabled={pagination.current_page === 1} onClick={() => navigate(pagination.current_page - 1)} className="rounded-lg border border-gray-200 px-2.5 py-1 font-semibold disabled:opacity-40 dark:border-gray-700">Previous</button><span>Page {pagination.current_page} of {pagination.last_page}</span><button type="button" disabled={pagination.current_page === pagination.last_page} onClick={() => navigate(pagination.current_page + 1)} className="rounded-lg border border-gray-200 px-2.5 py-1 font-semibold disabled:opacity-40 dark:border-gray-700">Next</button></div>}</div>} />;
}

function EngpSummary({ data }) {
    const period = EngpHeaderPeriod({ data });
    return <><MonitoringPageHeader title="ENGP Monitoring Overview" description="National Greening Program monitoring, tracking, and compliance" periodTitle={period.title} periodSubtitle={period.subtitle} icon="solar:leaf-linear" /><EngpFilters data={data} /><EngpKpis summary={data.summary} /></>;
}

function EngpContent({ data }) {
    return <><EngpPerformance officePerformance={data.officePerformance} reportTypeCompliance={data.reportTypeCompliance} /><div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_22rem]"><EngpTable data={data} /><AlertsCard alerts={data.alerts} canViewComplianceAlerts={data.canViewComplianceAlerts} /></div></>;
}

function PaFilters({ filterOptions = {}, filters = {} }) {
    const [values, setValues] = useState({ year: filters.year || '', period: filters.period || '', report_type: filters.report_type || '', office: filters.office || '', protected_area_id: filters.protected_area_id || '' });
    useEffect(() => setValues({ year: filters.year || '', period: filters.period || '', report_type: filters.report_type || '', office: filters.office || '', protected_area_id: filters.protected_area_id || '' }), [filters.year, filters.period, filters.report_type, filters.office, filters.protected_area_id]);
    const navigate = next => router.get(route('dashboard'), { view: 'pa', program: 'conservation', ...next, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
    const apply = () => navigate(values);
    const reset = () => navigate({ year: filterOptions.years?.[0] || '', period: '', report_type: '', office: '', protected_area_id: '' });
    return <section className="rounded-2xl border border-gray-100 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900"><div className="mb-2 flex items-center justify-between"><p className="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-500 dark:text-gray-400">PA monitoring filters</p><span className="text-[11px] text-gray-400">Report tracking only</span></div><div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-7"><FloatingSelect label="Year" size="sm" focusTone="green" value={values.year} onChange={event => setValues(current => ({ ...current, year: event.target.value }))}>{(filterOptions.years || []).map(year => <option key={year} value={year}>{year}</option>)}</FloatingSelect><FloatingSelect label="Quarter / Period" size="sm" focusTone="green" value={values.period} onChange={event => setValues(current => ({ ...current, period: event.target.value }))}><option value="">All periods</option>{(filterOptions.periods || []).map(period => <option key={period} value={period}>{period}</option>)}</FloatingSelect><FloatingSelect label="Program / Report Type" size="sm" focusTone="green" value={values.report_type} onChange={event => setValues(current => ({ ...current, report_type: event.target.value }))}><option value="">All PA report types</option>{(filterOptions.reportTypes || []).map(type => <option key={type} value={type}>{type}</option>)}</FloatingSelect><FloatingSelect label="Office" size="sm" focusTone="green" value={values.office} onChange={event => setValues(current => ({ ...current, office: event.target.value }))}><option value="">All offices</option>{(filterOptions.offices || []).map(office => <option key={office} value={office}>{office}</option>)}</FloatingSelect><FloatingSelect label="Protected Area" size="sm" focusTone="green" value={values.protected_area_id} onChange={event => setValues(current => ({ ...current, protected_area_id: event.target.value }))}><option value="">All protected areas</option>{(filterOptions.protectedAreas || []).map(area => <option key={area.id} value={area.id}>{area.label}</option>)}</FloatingSelect><button type="button" onClick={apply} className="inline-flex h-10 items-center justify-center gap-1.5 rounded-lg bg-green-800 px-3 text-xs font-bold text-white shadow-sm hover:bg-green-900"><Icon icon="solar:filter-linear" width="15" />Apply Filters</button><button type="button" onClick={reset} className="h-10 rounded-lg border border-gray-200 px-3 text-xs font-bold text-gray-600 hover:border-green-300 hover:text-green-800 dark:border-gray-700 dark:text-gray-300">Reset</button></div></section>;
}

function PaMatrix({ rows = [] }) {
    return <SectionCard title="PA Report Monitoring Matrix" subtitle="Authorized PA report-tracking workflows only" icon="solar:chart-square-linear" className="h-[300px]"><div className="max-h-[244px] overflow-auto"><table className="min-w-full text-left text-xs"><thead className="sticky top-0 z-10 bg-gray-50 text-[10px] uppercase text-gray-500 dark:bg-gray-800/95 dark:text-gray-400"><tr><th className="px-3 py-2">Module / Activity</th><th className="px-2 py-2 text-right">Tracked</th><th className="px-2 py-2 text-right">Submitted</th><th className="px-2 py-2 text-right">Pending</th><th className="px-2 py-2 text-right">Overdue</th><th className="min-w-28 px-3 py-2">Rate</th></tr></thead><tbody className="divide-y divide-gray-100 dark:divide-gray-800">{rows.map(row => <tr key={row.module}><td className="px-3 py-2 font-semibold text-gray-900 dark:text-white">{row.module}</td><td className="px-2 py-2 text-right">{row.tracked}</td><td className="px-2 py-2 text-right">{row.submitted}</td><td className="px-2 py-2 text-right">{row.pending}</td><td className="px-2 py-2 text-right">{row.overdue}</td><td className="px-3 py-2"><Progress value={row.compliance_rate} /></td></tr>)}</tbody></table>{!rows.length && <p className="px-3 py-8 text-center text-xs text-gray-500">No tracked PA report workflows match the selected filters.</p>}</div></SectionCard>;
}

function RoutingBottlenecks({ data = {} }) {
    const stages = data.stages || [];
    return <SectionCard title="Current Routing Bottlenecks" subtitle="Active PA documents grouped by their server-derived current stage" icon="solar:route-linear" className="h-[300px]"><div className="p-3"><div className="mb-2 flex items-end justify-between rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-800/70"><div><p className="text-[10px] font-bold uppercase text-gray-500">Average Routing Time</p><p className="mt-0.5 text-lg font-extrabold text-gray-900 dark:text-white">{data.average_routing_time ?? DASH}<span className="ml-1 text-[11px] font-medium text-gray-500">{data.average_routing_time !== null && data.average_routing_time !== undefined ? data.routing_time_unit : ''}</span></p></div><Icon icon="solar:route-linear" width="20" className="text-green-700" /></div><div className="max-h-[184px] overflow-auto">{stages.length ? <div className="divide-y divide-gray-100 dark:divide-gray-800">{stages.map(row => <div key={row.stage} className="flex items-center justify-between gap-3 py-2 text-xs"><div className="flex min-w-0 items-center gap-2"><span className="h-2 w-2 shrink-0 rounded-full bg-amber-500" /><div className="min-w-0"><p className="truncate font-semibold text-gray-800 dark:text-gray-200">{row.stage}</p><p className="text-[11px] text-gray-500">{row.average_pending_days !== null ? 'Avg ' + row.average_pending_days + ' working days pending' : 'Pending duration not recorded'}</p></div></div><div className="shrink-0 text-right"><p className="font-extrabold text-amber-700">{row.active_documents}</p><p className="text-[10px] uppercase text-gray-400">active</p></div></div>)}</div> : <p className="py-7 text-center text-xs text-gray-500">No active routing bottlenecks in the authorized scope.</p>}</div></div></SectionCard>;
}

function TopOverdueCard({ overdue = [] }) {
    return <SectionCard title="Top Overdue Reports" subtitle="Maximum five PA attention items" icon="solar:danger-triangle-linear" className="h-[300px]"><div className="max-h-[212px] overflow-auto divide-y divide-gray-100 dark:divide-gray-800">{overdue.length ? overdue.map(row => <a key={row.id} href={row.source_url || route('submission-tracking.index')} className="block px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800/50"><div className="flex items-start justify-between gap-2"><p className="truncate text-xs font-semibold text-gray-900 dark:text-white">{row.report}</p><span className="shrink-0 text-[11px] font-bold text-red-700">{row.days_overdue ?? DASH}d overdue</span></div><p className="mt-0.5 truncate text-[11px] text-gray-500">{row.office_or_pa} · Due {formatDate(row.deadline)}</p><p className="mt-0.5 truncate text-[11px] text-gray-500">Stage: {row.current_stage}</p></a>) : <p className="px-3 py-8 text-center text-xs text-gray-500">No overdue PA reports in the authorized scope.</p>}</div><div className="border-t border-gray-100 px-3 py-2 dark:border-gray-800"><a href={route('submission-tracking.index')} className="text-[11px] font-bold text-green-700 hover:text-green-900 dark:text-green-400">View All Overdue Reports <Icon icon="solar:arrow-right-up-linear" width="13" className="inline" /></a></div></SectionCard>;
}

function PaSubmissionTracking({ rows = [], pagination = {}, filters = {} }) {
    const navigate = page => router.get(route('dashboard'), { view: 'pa', program: 'conservation', ...filters, page }, { preserveState: true, preserveScroll: true, replace: true });
    const columns = [
        { key: 'module', label: 'Program / Module', headerClassName: 'sticky left-0 z-30 w-[180px] min-w-[180px] max-w-[180px] bg-green-900', cellClassName: 'relative sticky left-0 z-10 w-[180px] min-w-[180px] max-w-[180px] whitespace-normal break-words bg-white dark:bg-gray-900', render: row => <span className="break-words font-semibold text-gray-900 dark:text-white">{row.module}</span> },
        { key: 'office_or_pa', label: 'Office / Protected Area', headerClassName: 'sticky left-[180px] z-30 w-[230px] min-w-[230px] max-w-[230px] bg-green-900', cellClassName: 'sticky left-[180px] z-10 w-[230px] min-w-[230px] max-w-[230px] whitespace-normal break-words bg-white dark:bg-gray-900' },
        { key: 'activity_name', label: 'Activity / Report Type', headerClassName: 'sticky left-[410px] z-30 w-[224px] min-w-[224px] max-w-[224px] border-r border-green-700/70 bg-green-900 pr-5', cellClassName: 'relative sticky left-[410px] z-10 w-[224px] min-w-[224px] max-w-[224px] whitespace-normal break-words border-r border-gray-200 bg-white pr-5 dark:border-gray-700 dark:bg-gray-900', render: row => <span className="break-words">{row.activity_name || row.document_type || DASH}</span> },
        { key: 'reporting_period', label: 'Reporting Period', cellClassName: 'whitespace-nowrap' },
        { key: 'deadline_submission', label: 'Deadline', cellClassName: 'whitespace-nowrap', render: row => formatDate(row.deadline_submission) },
        { key: 'date_received_penro', label: 'Date Received', cellClassName: 'whitespace-nowrap', render: row => formatDate(row.date_received_penro) },
        { key: 'days_complied', label: 'Days Complied', cellClassName: 'text-center', render: row => row.days_complied ?? DASH },
        { key: 'timeliness', label: 'Timeliness', cellClassName: 'whitespace-nowrap', render: row => <TimelinessBadge value={row.timeliness} /> },
        { key: 'current_stage', label: 'Current Stage', headerClassName: 'min-w-40', render: row => row.routing?.current_status || row.submission_status || DASH },
        { key: 'current_location', label: 'Current Location', headerClassName: 'min-w-40', render: row => row.current_document_location || DASH },
        { key: 'status', label: 'Status', cellClassName: 'whitespace-nowrap', render: row => <StatusBadge variant={statusVariant(row.submission_status)}>{row.submission_status || DASH}</StatusBadge> },
        { key: 'action', label: 'MOV / Action', headerClassName: 'min-w-28 whitespace-nowrap', cellClassName: 'whitespace-nowrap', render: row => <div className="flex flex-col gap-1"><a href={row.source_url} onClick={event => event.stopPropagation()} className="font-bold text-green-700 hover:text-green-900 dark:text-green-400">View Details</a>{row.mov_url && <a href={row.mov_url} target={row.mov_external ? '_blank' : undefined} rel={row.mov_external ? 'noopener noreferrer' : undefined} onClick={event => event.stopPropagation()} className="text-[11px] font-semibold text-gray-500">View MOV</a>}</div> },
    ];
    const total = pagination.total || 0;
    const first = total ? ((pagination.current_page - 1) * pagination.per_page) + 1 : 0;
    const last = total ? Math.min(pagination.current_page * pagination.per_page, total) : 0;
    return <CrudTable title="PA Submission Tracking" subtitle="All tracked PA reports and their submission status" rows={rows} columns={columns} rowKey={row => row.source + '-' + row.source_id} className="overflow-hidden" tableClassName="min-w-[1250px]" tableContainerClassName="max-h-[420px]" tableHeaderClassName="sticky top-0 z-20" compact compactEmpty emptyTitle="No tracked PA reports match the selected filters." emptyDescription="Try changing a filter or reset the selected filters." pagination={<div className="flex flex-col gap-1 text-[11px] text-gray-500 sm:flex-row sm:items-center sm:justify-between"><span>Showing {first}–{last} of {total} reports</span>{pagination.last_page > 1 && <div className="flex items-center gap-1.5"><button type="button" disabled={pagination.current_page === 1} onClick={() => navigate(pagination.current_page - 1)} className="rounded-lg border border-gray-200 px-2.5 py-1 font-semibold disabled:opacity-40 dark:border-gray-700">Previous</button><span>Page {pagination.current_page} of {pagination.last_page}</span><button type="button" disabled={pagination.current_page === pagination.last_page} onClick={() => navigate(pagination.current_page + 1)} className="rounded-lg border border-gray-200 px-2.5 py-1 font-semibold disabled:opacity-40 dark:border-gray-700">Next</button></div>}</div>} />;
}

function PaInterpretation({ items = [] }) {
    return <SectionCard title="Executive Interpretation" subtitle="Deterministic summaries from current PA tracking metrics" icon="solar:lightbulb-linear"><div className="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4">{items.length ? items.map(item => <div key={item.label} className="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-800/70"><p className="text-[10px] font-bold uppercase text-green-700 dark:text-green-400">{item.label}</p><p className="mt-0.5 text-xs text-gray-700 dark:text-gray-300">{item.text}</p></div>) : <p className="px-3 py-3 text-xs text-gray-500">Insufficient tracked PA data for an interpretation.</p>}</div></SectionCard>;
}

function PaSummary({ filterOptions = {}, filters = {}, summary = {} }) {
    const period = PaHeaderPeriod({ filters });
    return <><MonitoringPageHeader title="PA Monitoring Overview" description="Quarterly report tracking, routing, compliance, and accomplishments" periodTitle={period.title} periodSubtitle={period.subtitle} icon="solar:shield-check-linear" /><PaFilters filterOptions={filterOptions} filters={filters} /><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><MetricCard label="Tracked Reports" value={summary.tracked_reports ?? 0} helper="Actual authorized PA tracking records" icon="solar:document-list-linear" tone="blue" /><MetricCard label="Submitted" value={summary.submitted ?? 0} helper="PENRO receipt recorded" icon="solar:check-circle-linear" tone="green" /><MetricCard label="Pending" value={summary.pending ?? 0} helper="Due today or later" icon="solar:clock-circle-linear" tone="amber" /><MetricCard label="Overdue" value={summary.overdue ?? 0} helper="Past authoritative deadline" icon="solar:danger-triangle-linear" tone="red" /><MetricCard label="Compliance Rate" value={percent(summary.compliance_rate)} helper="Submitted / tracked" icon="solar:chart-2-linear" tone="green" /></div></>;
}

function PaContent({ rows = [], pagination = {}, filters = {}, paMatrix = [], routingBottlenecks = {}, topOverdueReports = [], executiveInterpretation = [] }) {
    return <><PaSubmissionTracking rows={rows} pagination={pagination} filters={filters} /><div className="grid gap-3 xl:grid-cols-3"><PaMatrix rows={paMatrix} /><RoutingBottlenecks data={routingBottlenecks} /><TopOverdueCard overdue={topOverdueReports} /></div><PaInterpretation items={executiveInterpretation} /></>;
}

export default function Dashboard({ view = 'pa', engp, rows, pagination, filterOptions, filters, summary, paMatrix, routingBottlenecks, topOverdueReports, executiveInterpretation }) {
    return <AuthenticatedLayout title="eDATS Monitoring Dashboard"><div className="space-y-3"><ViewTabs view={view} />{view === 'engp' ? <><EngpSummary data={engp || {}} /><EngpContent data={engp || {}} /></> : <><PaSummary filterOptions={filterOptions} filters={filters} summary={summary} /><PaContent rows={rows} pagination={pagination} filters={filters} paMatrix={paMatrix} routingBottlenecks={routingBottlenecks} topOverdueReports={topOverdueReports} executiveInterpretation={executiveInterpretation} /></>}</div></AuthenticatedLayout>;
}
