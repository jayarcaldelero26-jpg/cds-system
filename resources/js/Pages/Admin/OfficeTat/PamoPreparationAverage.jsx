import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useState } from 'react';
import Button from '@/Components/Button';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingSelect } from '@/Components/Form';

export default function PamoPreparationAverage({ preparation = null, filters = {}, periodOptions = [], today = '', canViewLiveRoutingAverage = false }) {
    const [period, setPeriod] = useState(filters.processing_period || preparation?.processing_period || '');
    const [asOfDate, setAsOfDate] = useState(filters.as_of_date || preparation?.as_of_date || today);
    const rows = preparation?.rows || [];
    const overall = preparation?.overall || {};

    useEffect(() => {
        setPeriod(filters.processing_period || preparation?.processing_period || '');
        setAsOfDate(filters.as_of_date || preparation?.as_of_date || today);
    }, [filters.processing_period, filters.as_of_date, preparation?.processing_period, preparation?.as_of_date, today]);

    const submit = event => {
        event.preventDefault();
        router.get('/admin/office-tat/pamo-preparation-average', { processing_period: period, as_of_date: asOfDate }, { preserveScroll: true, preserveState: true, replace: true });
    };

    return <main className="min-h-screen bg-gray-50 px-3 py-5 dark:bg-gray-950 sm:px-6 lg:px-8">
        <Head title="PAMO preparation average" />
        <div className="mx-auto max-w-[1280px] space-y-4">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-[10px] font-extrabold uppercase tracking-[0.18em] text-green-800 dark:text-green-300">Administration / Performance</p>
                    <h1 className="mt-1 flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        <Icon icon="lucide:timer" width="24" height="24" className="text-green-700 dark:text-green-300" aria-hidden="true" />
                        PAMO preparation average
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">First saved PAMO draft to the next accepted PAMO handoff. This average is separate from TC, SE and overall score.</p>
                </div>
                <nav className="flex flex-wrap gap-2" aria-label="Office TAT views">
                    <Link href={'/admin/office-tat?processing_period=' + encodeURIComponent(period) + '&as_of_date=' + encodeURIComponent(asOfDate)} className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-bold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800">
                        <Icon icon="lucide:chart-no-axes-combined" width="15" height="15" aria-hidden="true" />
                        Report Performance
                    </Link>
                    {canViewLiveRoutingAverage && <Link href={'/admin/office-tat/routing-average?processing_period=' + encodeURIComponent(period) + '&as_of_date=' + encodeURIComponent(asOfDate)} className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-bold text-gray-800 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800">
                        CENRO processing average
                    </Link>}
                </nav>
            </header>

            <FilterToolbar label="PAMO preparation filters" className="min-w-0">
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-3">
                    <FloatingSelect label="Processing period" size="sm" value={period} onChange={event => setPeriod(event.target.value)}>
                        {periodOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </FloatingSelect>
                    <DatePicker id="office-tat-pamo-preparation-as-of" label="As of date" value={asOfDate} maxDate={today} onChange={setAsOfDate} />
                    <div className="flex items-end">
                        <Button type="submit" size="compact" className="w-full justify-center rounded-lg px-4 py-2 text-xs">Apply filters</Button>
                    </div>
                </form>
            </FilterToolbar>

            {preparation?.available === false ? <section className="rounded-xl border border-gray-200 bg-white px-4 py-5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" role="status">
                {preparation.unavailable_reason || 'PAMO preparation intervals are not available for this period.'}
            </section> : <>
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="PAMO preparation summary">
                    <Summary label="Drafts started" value={count(overall.documents_started)} detail="First saved draft in the selected period" />
                    <Summary label="Completed handoffs" value={count(overall.completed_documents)} detail="Accepted PAMO handoff recorded" />
                    <Summary label="Average preparation" value={overall.average_elapsed_seconds == null ? 'No completed handoffs' : duration(overall.average_elapsed_seconds)} detail="Total elapsed time ÷ completed intervals" />
                    <Summary label="Pending" value={count(overall.pending_documents)} detail="No accepted handoff yet" />
                    <Summary label="Missing event evidence" value={count(overall.missing_evidence)} detail="A usable start or handoff event is missing" />
                </section>

                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <table className="min-w-full divide-y divide-gray-200 text-left text-xs dark:divide-gray-800">
                        <caption className="sr-only">PAMO draft-to-handoff preparation intervals for {preparation?.processing_period || period}</caption>
                        <thead className="bg-gray-50 text-[10px] font-extrabold uppercase tracking-wide text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                            <tr>
                                <th scope="col" className="px-3 py-3">PAMO office</th>
                                <th scope="col" className="px-3 py-3">Drafts started</th>
                                <th scope="col" className="px-3 py-3">Completed handoffs</th>
                                <th scope="col" className="px-3 py-3">Average preparation</th>
                                <th scope="col" className="px-3 py-3">Total elapsed</th>
                                <th scope="col" className="px-3 py-3">Pending</th>
                                <th scope="col" className="px-3 py-3">Missing event evidence</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                            {rows.map(row => <tr key={row.office_id} className="text-gray-800 dark:text-gray-100">
                                <th scope="row" className="px-3 py-3 font-bold">{row.office}</th>
                                <td className="px-3 py-3">{count(row.documents_started)}</td>
                                <td className="px-3 py-3">{count(row.completed_documents)}</td>
                                <td className="px-3 py-3 font-extrabold">{row.average_elapsed_seconds == null ? 'No completed handoffs' : duration(row.average_elapsed_seconds)}</td>
                                <td className="px-3 py-3">{duration(row.elapsed_seconds_total)}</td>
                                <td className="px-3 py-3">{count(row.pending_documents)}</td>
                                <td className="px-3 py-3">{count(row.missing_evidence)}</td>
                            </tr>)}
                            {rows.length === 0 && <tr><td colSpan="7" className="px-4 py-8 text-center text-gray-500">No PAMO offices are available for this account.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </>}
            <p className="text-[11px] leading-5 text-gray-500 dark:text-gray-400">Period: {preparation?.processing_period || period} | {preparation?.cohort_start} to {preparation?.cohort_end} | As of {filters.as_of_date || asOfDate}</p>
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
    const days = Math.floor(whole / 86400);
    const hours = Math.floor((whole % 86400) / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const remainder = whole % 60;
    return [days ? days + 'd' : null, hours ? hours + 'h' : null, minutes ? minutes + 'm' : null, remainder + 's'].filter(Boolean).join(' ');
}
