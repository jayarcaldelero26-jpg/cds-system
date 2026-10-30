import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useMemo, useState } from 'react';
import Button from '@/Components/Button';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingSelect } from '@/Components/Form';
import { isValidLiveAnalytics } from './liveRoutingAveragePayload.mjs';

export default function LiveRoutingAverage({ liveAnalytics = null, filters = {}, periodOptions = [], today = '', canViewPamoPreparationAverage = false }) {
    const [period, setPeriod] = useState(filters.processing_period || liveAnalytics?.processing_period || '');
    const [asOfDate, setAsOfDate] = useState(filters.as_of_date || liveAnalytics?.as_of_date || today);
    const returnUnit = filters.return_unit || 'development';
    const validAnalytics = isValidLiveAnalytics(liveAnalytics, filters);
    const rows = validAnalytics ? liveAnalytics.rows : [];

    useEffect(() => {
        setPeriod(filters.processing_period || liveAnalytics?.processing_period || '');
        setAsOfDate(filters.as_of_date || liveAnalytics?.as_of_date || today);
    }, [filters.processing_period, filters.as_of_date, liveAnalytics?.processing_period, liveAnalytics?.as_of_date, today]);

    const completed = useMemo(() => rows.reduce((sum, row) => sum + Number(row.tat_completed_documents || 0), 0), [rows]);
    const seconds = useMemo(() => rows.reduce((sum, row) => sum + Number(row.tat_elapsed_seconds_total || 0), 0), [rows]);
    const pending = useMemo(() => rows.reduce((sum, row) => sum + Number(row.pending || 0), 0), [rows]);
    const missing = useMemo(() => rows.reduce((sum, row) => sum + Number(row.tat_missing_evidence || 0), 0), [rows]);
    const average = completed > 0 ? seconds / completed : null;

    const submit = event => {
        event.preventDefault();
        router.get('/admin/office-tat/routing-average', { processing_period: period, as_of_date: asOfDate, return_unit: returnUnit }, { preserveScroll: true, preserveState: true, replace: true });
    };

    return <main className="min-h-screen bg-gray-50 px-3 py-5 dark:bg-gray-950 sm:px-6 lg:px-8">
        <Head title="CENRO processing average" />
        <div className="mx-auto max-w-[1280px] space-y-4">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-[10px] font-extrabold uppercase tracking-[0.18em] text-green-800 dark:text-green-300">Administration / Performance</p>
                    <h1 className="mt-1 flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        <Icon icon="lucide:timer" width="24" height="24" className="text-green-700 dark:text-green-300" aria-hidden="true" />
                        CENRO processing average
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Accepted CENRO receipt to the next accepted handoff. This average is separate from TC, SE and overall score.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                <Link href={`/admin/office-tat?unit=${encodeURIComponent(returnUnit)}&processing_period=${encodeURIComponent(period)}&as_of_date=${encodeURIComponent(asOfDate)}`} className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-bold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800">
                    <Icon icon="lucide:chart-no-axes-combined" width="15" height="15" aria-hidden="true" />
                    Report Performance
                </Link>
                {canViewPamoPreparationAverage && <Link href={`/admin/office-tat/pamo-preparation-average?processing_period=${encodeURIComponent(period)}&as_of_date=${encodeURIComponent(asOfDate)}`} className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-bold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800">PAMO preparation average</Link>}
                </div>
            </header>

            <FilterToolbar label="Live routing filters" className="min-w-0">
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-3">
                    <FloatingSelect label="Processing period" size="sm" value={period} onChange={event => setPeriod(event.target.value)}>
                        {periodOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </FloatingSelect>
                    <DatePicker id="office-tat-routing-as-of" label="As of date" value={asOfDate} maxDate={today} onChange={setAsOfDate} />
                    <div className="flex items-end">
                        <Button type="submit" size="compact" className="w-full justify-center rounded-lg px-4 py-2 text-xs">
                            <Icon icon="lucide:filter" width="14" height="14" aria-hidden="true" />
                            Apply filters
                        </Button>
                    </div>
                </form>
            </FilterToolbar>

            <section className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100" aria-label="Live routing average definition">
                <p className="font-extrabold">CENRO receipt to next accepted handoff</p>
                <p className="mt-1">{liveAnalytics?.tat_definition || 'Average elapsed time is calculated only from completed live routing intervals. A later unfinished correction cycle remains pending.'}</p>
            <p className="mt-1">This CENRO interval is separate from Conservation and Development TC, SE and overall score.</p>
            </section>

            {!validAnalytics ? <section className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100" role="alert">
                Live routing data is unavailable or does not match the selected period and as-of date.
            </section> : <>
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Live routing summary">
                    <Summary label="Completed intervals" value={count(completed)} detail="Unique office receipt-to-handoff intervals" />
                    <Summary label="Weighted average" value={average == null ? 'No completed handoffs' : duration(average)} detail="Total elapsed time divided by completed intervals" />
                    <Summary label="Pending" value={count(pending)} detail="A live receipt has no completed next handoff" />
                    <Summary label="Missing event evidence" value={count(missing)} detail="Received documents without a usable event interval" />
                </section>

                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <table className="min-w-full divide-y divide-gray-200 text-left text-xs dark:divide-gray-800">
                        <caption className="sr-only">Live CENRO receipt-to-handoff intervals by office for {liveAnalytics.processing_period_label || period}</caption>
                        <thead className="bg-gray-50 text-[10px] font-extrabold uppercase tracking-wide text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                            <tr>
                                <th scope="col" className="px-3 py-3">CENRO office</th>
                                <th scope="col" className="px-3 py-3">Received documents</th>
                                <th scope="col" className="px-3 py-3">Completed intervals</th>
                                <th scope="col" className="px-3 py-3">Average receipt to handoff</th>
                                <th scope="col" className="px-3 py-3">Total elapsed</th>
                                <th scope="col" className="px-3 py-3">Pending</th>
                                <th scope="col" className="px-3 py-3">Missing evidence</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                            {rows.map(row => <tr key={row.office} className="text-gray-800 dark:text-gray-100">
                                <th scope="row" className="px-3 py-3 font-bold">{row.office}</th>
                                <td className="px-3 py-3">{count(row.documents_received)}</td>
                                <td className="px-3 py-3">{count(row.tat_completed_documents)}</td>
                                <td className="px-3 py-3 font-extrabold">{row.tat_average_elapsed_seconds == null ? 'No completed handoffs' : duration(row.tat_average_elapsed_seconds)}</td>
                                <td className="px-3 py-3">{duration(row.tat_elapsed_seconds_total)}</td>
                                <td className="px-3 py-3">{count(row.pending)}</td>
                                <td className="px-3 py-3">{count(row.tat_missing_evidence)}</td>
                            </tr>)}
                            {rows.length === 0 && <tr><td colSpan="7" className="px-4 py-8 text-center text-gray-500">No CENRO office results are available for this period.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </>}
            <p className="text-[11px] leading-5 text-gray-500 dark:text-gray-400">Period: {liveAnalytics?.processing_period_label || period} | {liveAnalytics?.processing_period_start} to {liveAnalytics?.processing_period_end} | As of {filters.as_of_date || asOfDate}</p>
        </div>
    </main>;
}

function Summary({ label, value, detail }) {
    return <article className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-600 dark:text-gray-300">{detail}</p>
    </article>;
}

function count(value) {
    return value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString();
}

function duration(value) {
    const seconds = Math.max(0, Number(value) || 0);
    const whole = Math.floor(seconds);
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const remaining = (seconds % 60).toFixed(2).padStart(5, '0');
    return hours > 0 ? `${hours}h ${minutes}m ${remaining}s` : `${minutes}m ${remaining}s`;
}
