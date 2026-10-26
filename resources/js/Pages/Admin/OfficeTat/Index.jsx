import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useState } from 'react';
import Button from '@/Components/Button';
import ComplianceTabList from '@/Components/Compliance/ComplianceTabList';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingSelect } from '@/Components/Form';
import ComplianceFirstAssessments from './ComplianceFirstAssessments';
import { officeTatDisplayUnit } from './unitPresentation.mjs';

const unitLabels = {
    conservation: 'Conservation',
    development: 'Development',
};

export default function OfficeTatIndex({
    filters = {},
    recordedAssessment = null,
    selectedUnit = 'conservation',
    authorizedUnits = [],
    periodOptions = [],
    moduleOptions = [],
    today = '',
    canViewLiveRoutingAverage = false,
}) {
    const [period, setPeriod] = useState(filters.processing_period || recordedAssessment?.period_key || '');
    const [asOfDate, setAsOfDate] = useState(filters.as_of_date || recordedAssessment?.as_of_date || today);
    const [workflow, setWorkflow] = useState(filters.workflow || 'all');
    const displayedReportUnit = officeTatDisplayUnit(recordedAssessment, selectedUnit);

    useEffect(() => {
        setPeriod(filters.processing_period || recordedAssessment?.period_key || '');
        setAsOfDate(filters.as_of_date || recordedAssessment?.as_of_date || today);
        setWorkflow(filters.workflow || 'all');
    }, [filters.processing_period, filters.as_of_date, filters.workflow, recordedAssessment?.period_key, recordedAssessment?.as_of_date, today]);

    const apply = (next = {}) => {
        const unit = next.unit || displayedReportUnit;
        const params = {
            unit,
            processing_period: next.processing_period || period,
            as_of_date: next.as_of_date || asOfDate,
            workflow: next.workflow ?? workflow,
        };
        router.get('/admin/office-tat', params, { preserveScroll: true, preserveState: true, replace: true });
    };

    const handleSubmit = event => {
        event.preventDefault();
        apply();
    };

    return <main className="min-h-screen bg-gray-50 px-3 py-5 dark:bg-gray-950 sm:px-6 lg:px-8">
        <Head title="Office TAT recognition" />
        <div className="mx-auto max-w-[1440px]">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-[10px] font-extrabold uppercase tracking-[0.18em] text-green-800 dark:text-green-300">Administration / Performance</p>
                    <h1 className="mt-1 flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        <Icon icon="lucide:chart-no-axes-combined" width="24" height="24" className="text-green-700 dark:text-green-300" aria-hidden="true" />
                        Office TAT recognition
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Conservation and Development are ranked separately from accepted PENRO Records receipts and their canonical reporting schedules.</p>
                </div>
                {canViewLiveRoutingAverage && <Link
                    href={`/admin/office-tat/routing-average?processing_period=${encodeURIComponent(period)}&as_of_date=${encodeURIComponent(asOfDate)}&return_unit=${encodeURIComponent(displayedReportUnit || '')}`}
                    className="inline-flex items-center gap-2 rounded-xl border border-green-200 bg-white px-3 py-2 text-xs font-bold text-green-900 hover:bg-green-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-green-900 dark:bg-gray-900 dark:text-green-200 dark:hover:bg-gray-800"
                >
                    <Icon icon="lucide:timer" width="15" height="15" aria-hidden="true" />
                    Average TAT / CENRO offices
                </Link>}
                <div className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-right dark:border-gray-800 dark:bg-gray-900">
                    <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Assessment date</p>
                    <p className="mt-0.5 text-sm font-extrabold text-gray-900 dark:text-white">{recordedAssessment?.as_of_date || asOfDate || '—'}</p>
                </div>
            </header>

            <section className="mt-5 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Recognition group">
                <ComplianceTabList
                    tabs={authorizedUnits.filter(unit => unitLabels[unit]).map(unit => [unit, unitLabels[unit]])}
                    value={displayedReportUnit}
                    onChange={unit => { setWorkflow('all'); apply({ unit, workflow: 'all' }); }}
                    label="Recognition group"
                />
            </section>

            <section className="mt-4">
                <FilterToolbar label="Recognition filters" className="min-w-0">
                    <form onSubmit={handleSubmit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <FloatingSelect label="Reporting period" size="sm" value={period} onChange={event => setPeriod(event.target.value)}>
                            {periodOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </FloatingSelect>
                        <DatePicker id="office-tat-as-of" label="As of date" value={asOfDate} maxDate={today} onChange={setAsOfDate} />
                        <FloatingSelect label="Module" size="sm" value={workflow} onChange={event => setWorkflow(event.target.value)}>
                            <option value="all">All modules</option>
                            {moduleOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </FloatingSelect>
                        <div className="flex items-end sm:col-span-2 lg:col-span-1">
                            <Button type="submit" size="compact" className="w-full justify-center rounded-lg px-4 py-2 text-xs">
                                <Icon icon="lucide:filter" width="14" height="14" aria-hidden="true" />
                                Apply filters
                            </Button>
                        </div>
                    </form>
                </FilterToolbar>
            </section>

            <ComplianceFirstAssessments
                data={recordedAssessment}
                selectedUnit={displayedReportUnit}
                authorizedUnits={authorizedUnits}
                onUnitChange={unit => { setWorkflow('all'); apply({ unit, workflow: 'all' }); }}
                periodLabel={periodOptions.find(option => option.value === period)?.label || period}
            />
        </div>
    </main>;
}
