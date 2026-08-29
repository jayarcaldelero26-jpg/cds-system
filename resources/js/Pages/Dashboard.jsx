import CrudTable from '@/Components/Crud/CrudTable';
import { FloatingSelect } from '@/Components/Form';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import TimelinessBadge from '@/Components/TimelinessBadge';
import { parseDateOnly } from '@/Utils/dateFormatters';

const DASH = '—';
const DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

const formatDashboardDate = value => {
    if (value === null || value === undefined || String(value).trim() === '') return DASH;
    const raw = String(value).trim();
    if (['n/a', 'na'].includes(raw.toLowerCase()) || raw === DASH) return 'N/A';
    const date = parseDateOnly(raw);
    if (!date) return DASH;
    return DATE_FORMATTER.format(date);
};

const badge = (value, tone = 'neutral') => {
    const tones = { green: 'bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300', blue: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300', red: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300', amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300', neutral: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' };
    return <span className={`inline-flex max-w-44 items-center justify-center rounded-full px-2 py-1 text-center text-[11px] font-semibold leading-tight ${tones[tone] || tones.neutral}`}>{value || DASH}</span>;
};
const statusTone = value => { const status = String(value || '').toLowerCase(); if (status.includes('submitted') || status.includes('completed')) return 'green'; if (status.includes('endorsement')) return 'amber'; if (status.includes('not yet') || status.includes('overdue')) return 'red'; if (status.includes('ongoing') || status.includes('allowable')) return 'blue'; return 'neutral'; };

function MovCell({ row }) {
    if (!row.mov_url) return <span className="text-gray-400 dark:text-gray-500">{DASH}</span>;
    return <a href={row.mov_url} target={row.mov_external ? '_blank' : undefined} rel={row.mov_external ? 'noopener noreferrer' : undefined} onClick={event => event.stopPropagation()} title={row.mov_external ? 'Open external MOV' : 'View MOV'} className="inline-flex items-center gap-1 font-semibold text-green-700 hover:text-green-900 dark:text-green-400"><Icon icon={row.mov_external ? 'solar:round-arrow-up-outline' : 'solar:document-text-bold'} width="16" height="16" />{row.mov_external ? 'Open' : 'View'}</a>;
}

export default function Dashboard({ rows, pagination, filterOptions, filters }) {
    const [values, setValues] = useState(filters);
    useEffect(() => {
        const next = {
            year: filters.year ?? filterOptions.years?.[0] ?? '',
            program: filters.program ?? 'all',
            office: filters.office ?? '',
            period: filters.period ?? '',
        };
        setValues(current => Object.keys(next).every(key => String(current?.[key] ?? '') === String(next[key] ?? '')) ? current : next);
    }, [filters.year, filters.program, filters.office, filters.period, filterOptions.years?.[0]]);
    const defaultValues = { year: filterOptions.years?.[0] ?? values.year, program: 'all', office: '', period: '' };
    const filtersChanged = ['year', 'program', 'office', 'period'].some(key => String(values[key] ?? '') !== String(defaultValues[key] ?? ''));
    const navigate = next => router.get(route('dashboard'), next, { preserveState: true, preserveScroll: true, replace: true });
    const setFilter = (key, value) => { const next = { ...values, [key]: value, page: 1 }; setValues(next); navigate(next); };
    const resetFilters = () => { const next = { ...defaultValues, page: 1 }; setValues(next); navigate(next); };
    const page = number => navigate({ ...values, page: number });
    const total = pagination?.total ?? rows.length;
    const first = total ? ((pagination.current_page - 1) * pagination.per_page) + 1 : 0;
    const last = total ? Math.min(pagination.current_page * pagination.per_page, total) : 0;
    const columns = [
        { key: 'module', label: 'Program / Module', headerClassName: 'min-w-48 text-left md:sticky md:left-0 md:z-30 md:bg-green-900', cellClassName: 'min-w-48 md:sticky md:left-0 md:z-10 md:bg-white dark:md:bg-gray-900', tooltip: row => `${row.module}${row.program ? ` — ${row.program}` : ''}`, render: row => <div className="min-w-0"><p className="font-semibold text-gray-900 dark:text-white">{row.module}</p><p className="mt-0.5 text-xs text-green-700 dark:text-green-400">{row.program}</p></div> },
        { key: 'office_or_pa', label: 'Office / Protected Area', headerClassName: 'min-w-52 text-left md:sticky md:left-48 md:z-30 md:bg-green-900', cellClassName: 'min-w-52 md:sticky md:left-48 md:z-10 md:bg-white dark:md:bg-gray-900', tooltip: row => row.office_or_pa },
        { key: 'reporting_period', label: 'Reporting Period', headerClassName: 'min-w-36 text-left', tooltip: row => row.reporting_period },
        { key: 'deadline_submission', label: 'Deadline for Submission to PENRO', headerClassName: 'min-w-40 text-center', cellClassName: 'text-center', render: row => formatDashboardDate(row.deadline_submission) },
        { key: 'date_received_penro', label: 'Date Received by PENRO Records', headerClassName: 'min-w-40 text-center', cellClassName: 'text-center', render: row => formatDashboardDate(row.date_received_penro) },
        { key: 'days_complied', label: 'Number of Days Complied', headerClassName: 'min-w-32 text-center', cellClassName: 'text-center font-semibold', render: row => row.days_complied ?? DASH },
        { key: 'timeliness', label: 'Timeliness', headerClassName: 'min-w-36 text-center', cellClassName: 'text-center', render: row => <TimelinessBadge value={row.timeliness} /> },
        { key: 'submission_status', label: 'Status of Submission', headerClassName: 'min-w-40 text-center', cellClassName: 'text-center', render: row => badge(row.submission_status, statusTone(row.submission_status)) },
        { key: 'mov', label: 'MOV', headerClassName: 'min-w-20 text-center', cellClassName: 'text-center', render: row => <MovCell row={row} /> },
    ];
    return <AuthenticatedLayout title="eDATS Monitoring Dashboard"><div className="space-y-5"><PageHeader title="eDATS Monitoring Dashboard" description="Live report submission and compliance monitoring" />
        <section className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><div className="mb-3 flex items-center justify-between gap-3"><p className="text-xs font-semibold uppercase tracking-[0.1em] text-gray-500 dark:text-gray-400">Monitoring filters</p>{filtersChanged && <button type="button" onClick={resetFilters} className="text-xs font-semibold text-green-700 transition hover:text-green-900 focus:outline-none focus:ring-2 focus:ring-green-600/40 dark:text-green-400 dark:hover:text-green-300">Reset Filters</button>}</div><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><FloatingSelect label="Reporting Year" size="sm" focusTone="green" value={values.year} onChange={event => setFilter('year', event.target.value)}>{filterOptions.years.map(year => <option key={year} value={year}>{year}</option>)}</FloatingSelect><FloatingSelect label="Program / Source" size="sm" focusTone="green" value={values.program} onChange={event => setFilter('program', event.target.value)}>{filterOptions.programs.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</FloatingSelect><FloatingSelect label="Office / Protected Area" size="sm" focusTone="green" value={values.office} onChange={event => setFilter('office', event.target.value)}><option value="">All offices / protected areas</option>{filterOptions.offices.map(item => <option key={item} value={item}>{item}</option>)}</FloatingSelect><FloatingSelect label="Reporting Period" size="sm" focusTone="green" value={values.period} onChange={event => setFilter('period', event.target.value)}><option value="">All reporting periods</option>{filterOptions.periods.map(item => <option key={item} value={item}>{item}</option>)}</FloatingSelect></div></section>
        <CrudTable title="Submission Status Overview" subtitle="Live report submission and compliance records" headerActions={<span className="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-800 dark:bg-green-950/50 dark:text-green-300">{total} {total === 1 ? 'record' : 'records'}</span>} columns={columns} rows={rows} rowKey={row => `${row.source}-${row.source_id}`} onRowClick={row => row.source_url && router.visit(row.source_url)} tableClassName="min-w-[1100px]" tableContainerClassName="max-h-[70vh] overscroll-x-contain" tableHeaderClassName="sticky top-0 z-20" compact emptyTitle="No monitoring records match the selected filters." emptyDescription="Try changing a filter or reset the selected filters." pagination={<div className="flex flex-col gap-2 text-xs text-gray-500 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between"><span>Showing {first}–{last} of {total} records</span>{pagination.last_page > 1 && <div className="flex items-center gap-2"><button type="button" disabled={pagination.current_page === 1} onClick={() => page(pagination.current_page - 1)} className="rounded-lg border border-gray-200 px-3 py-1.5 font-semibold text-gray-700 transition hover:border-green-500 hover:text-green-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-200">Previous</button><span>Page {pagination.current_page} of {pagination.last_page}</span><button type="button" disabled={pagination.current_page === pagination.last_page} onClick={() => page(pagination.current_page + 1)} className="rounded-lg border border-gray-200 px-3 py-1.5 font-semibold text-gray-700 transition hover:border-green-500 hover:text-green-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-200">Next</button></div>}</div>} />
    </div></AuthenticatedLayout>;
}
