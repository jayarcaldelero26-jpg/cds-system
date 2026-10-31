import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useState } from 'react';
import Button from '@/Components/Button';
import ComplianceTabList from '@/Components/Compliance/ComplianceTabList';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingSelect } from '@/Components/Form';
import ComplianceFirstAssessments from './ComplianceFirstAssessments';
import DocumentTurnaround from './DocumentTurnaround';
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
    documentTurnaround = null,
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
        <Head title="Office TAT" />
        <div className="mx-auto max-w-[1440px]">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-[10px] font-extrabold uppercase tracking-[0.18em] text-green-800 dark:text-green-300">Administration / Performance</p>
                    <h1 className="mt-1 flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        <Icon icon="lucide:chart-no-axes-combined" width="24" height="24" className="text-green-700 dark:text-green-300" aria-hidden="true" />
                        Office TAT
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Document turnaround and submission performance for processed reports.</p>
                </div>
                <div className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-right dark:border-gray-800 dark:bg-gray-900">
                    <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">As of</p>
                    <p className="mt-0.5 text-sm font-extrabold text-gray-900 dark:text-white">{recordedAssessment?.as_of_date || asOfDate || '—'}</p>
                </div>
            </header>

            <section className="mt-5 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Reporting group">
                <ComplianceTabList
                    tabs={authorizedUnits.filter(unit => unitLabels[unit]).map(unit => [unit, unitLabels[unit]])}
                    value={displayedReportUnit}
                    onChange={unit => { setWorkflow('all'); apply({ unit, workflow: 'all' }); }}
                    label="Reporting group"
                />
            </section>

            <section className="mt-4">
                <FilterToolbar label="Filters" className="min-w-0">
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

            <DocumentTurnaround
                data={documentTurnaround}
                selectedUnit={displayedReportUnit}
                periodLabel={periodOptions.find(option => option.value === period)?.label || period}
            />

            <ComplianceFirstAssessments
                data={recordedAssessment}
                selectedUnit={displayedReportUnit}
                periodLabel={periodOptions.find(option => option.value === period)?.label || period}
            />

            <details className="mt-4 rounded-xl border border-gray-200 bg-white px-4 py-3 text-xs leading-5 text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <summary className="cursor-pointer font-bold text-gray-900 dark:text-white">How this is calculated</summary>
                <div className="mt-3 space-y-2">
                    <p><strong>Document turnaround</strong> is elapsed time from the first successful registered report submission to the first accepted PENRO Records receipt. It continues through corrections and transit. Averages include completed reports with both timestamps; the supporting report count appears beside the average. In progress reports use the selected as-of cutoff and do not enter the average.</p>
                    <p><strong>Submission performance</strong> uses the existing workflow deadlines and calendar rules. Reports assessed are actual processed reports that were received by the cutoff or were already due; future unreceived reports remain pending. TC is on-time reports divided by assessed reports. SE averages max(0, (T - D) / T) × 100 with the existing approved source calendar and definition of D. Overall score remains 80% TC + 20% SE.</p>
                    <p>Ranking remains ordered by TC, SE, early-submission percentage, verified report volume, verified highly technical reports on time, then the existing approved tie-resolution process. Document turnaround does not affect submission scores or ranking.</p>
                </div>
            </details>
        </div>
    </main>;
}
