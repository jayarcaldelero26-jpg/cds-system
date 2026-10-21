import { Head, router, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useMemo, useState } from 'react';
import Button from '@/Components/Button';
import ComplianceTabList from '@/Components/Compliance/ComplianceTabList';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudTable from '@/Components/Crud/CrudTable';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput, FloatingSelect } from '@/Components/Form';
import UtilityIconButton from '@/Components/UtilityIconButton';
import ComplianceFirstAssessments from './ComplianceFirstAssessments';
import { formatDuration, rankTatRows, weightedAverageSeconds } from './ranking.mjs';
import { assessmentPeriodKey, parseAssessmentPeriodKey, selectAssessmentType } from './assessmentPeriodSelection.mjs';
import { officeTatDisplayUnit } from './unitPresentation.mjs';

const categories = [
    ['PAMO', 'PAMO offices'],
    ['CENRO', 'CENRO offices'],
];

const defaultWeights = { timeliness: 0.5, tat_compliance: 0.35, volume: 0.15 };
export default function OfficeTatIndex({ analytics = {}, filters = {}, canConfigure = false, configurationSaved = null, recordedAssessment = null, selectedUnit = 'conservation', authorizedUnits = [], selectedView = 'report-performance' }) {
    const [search, setSearch] = useState('');
    const [showConfig, setShowConfig] = useState(null);
    const [secondaryOpen, setSecondaryOpen] = useState(false);
    const [activeView, setActiveView] = useState(selectedView || filters.view || 'report-performance');
    const displayedReportUnit = officeTatDisplayUnit(recordedAssessment, selectedUnit);
    const [assessment, setAssessment] = useState(() => analytics.assessment_period || parseAssessmentPeriodKey(analytics.processing_period));
    const [draftFilters, setDraftFilters] = useState({
        processing_period: analytics.processing_period || filters.processing_period || '',
        as_of_date: analytics.as_of_date || filters.as_of_date || '',
        reporting_year: filters.reporting_year || '',
        reporting_period: filters.reporting_period || '',
        workflow: filters.workflow || 'all',
        protected_area_id: filters.protected_area_id || '',
    });
    useEffect(() => {
        const nextAssessment = analytics.assessment_period || parseAssessmentPeriodKey(analytics.processing_period);
        if (nextAssessment) setAssessment(nextAssessment);
        if (analytics.processing_period) setDraftFilters(current => ({ ...current, processing_period: analytics.processing_period }));
    }, [analytics.processing_period]);
    useEffect(() => setActiveView(selectedView || 'report-performance'), [selectedView]);
    const form = useForm({ office_category: '', office: '', period_key: '', volume_target: '', weights: defaultWeights });
    const selectedCategory = categories.find(([value]) => value === analytics.category);
    const periodLabel = analytics.processing_period_label || assessment?.label || analytics.processing_period || 'Selected assessment period';

    const visibleRows = useMemo(() => (analytics.rows || []).filter(row =>
        String(row.office || '').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()),
    ), [analytics.rows, search]);
    const liveRanking = useMemo(() => rankTatRows(visibleRows, 'live'), [visibleRows]);
    const pamoPreparation = analytics.pamo_preparation || null;
    const visiblePreparationRows = useMemo(() => (pamoPreparation?.rows || []).filter(row =>
        String(row.office || '').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()),
    ), [pamoPreparation?.rows, search]);
    const pamoPreparationRanking = useMemo(() => rankTatRows(visiblePreparationRows, 'pamo_preparation'), [visiblePreparationRows]);
    const liveAverage = weightedAverageSeconds(liveRanking);
    const liveCompleted = sum(liveRanking, row => row.completedDocuments);
    const livePending = sum(liveRanking, row => row.pendingDocuments);
    const liveMissing = sum(liveRanking, row => row.missingDocuments);
    const pamoPreparationAverage = weightedAverageSeconds(pamoPreparationRanking);
    const pamoPreparationCompleted = sum(pamoPreparationRanking, row => row.completedDocuments);
    const pamoPreparationPending = sum(pamoPreparationRanking, row => row.pendingDocuments);
    const pamoPreparationMissing = sum(pamoPreparationRanking, row => row.missingDocuments);

    const applyValues = values => {
        const query = { ...filters, ...values, category: values.category || analytics.category, view: values.view || activeView, unit: values.unit || selectedUnit };
        delete query.assessment_type;
        delete query.assessment_year;
        delete query.semester;
        query.processing_period = values.processing_period || assessmentPeriodKey(assessment);
        query.evidence_type = 'live';
        router.get('/admin/office-tat', query, { preserveScroll: true, preserveState: true, replace: true });
    };

    const removeFilter = (key, value) => {
        const next = { ...draftFilters, [key]: value };
        setDraftFilters(next);
        applyValues(next);
    };

    const clearFilters = () => {
        const next = {
            ...draftFilters,
            reporting_year: '',
            reporting_period: '',
            workflow: 'all',
            protected_area_id: '',
        };
        setDraftFilters(next);
        applyValues(next);
    };

    const apply = event => {
        event.preventDefault();
        applyValues({ ...draftFilters, processing_period: assessmentPeriodKey(assessment) });
    };

    const updateAssessment = next => {
        setAssessment(next);
        setDraftFilters(current => ({ ...current, processing_period: assessmentPeriodKey(next) }));
    };

    const chips = makeFilterChips(filters, analytics.options || {}, removeFilter);
    const switchCategory = value => applyValues({ ...draftFilters, category: value });
    const switchView = value => { setActiveView(value); applyValues({ ...draftFilters, view: value }); };
    const switchReportUnit = value => applyValues({ ...draftFilters, unit: value });

    const startConfig = row => {
        const currentWeights = row.weights || defaultWeights;
        form.setData({
            office_category: analytics.category,
            office: row.office,
            period_key: analytics.processing_period,
            volume_target: row.volume_target || '',
            weights: {
                timeliness: Number(currentWeights.timeliness ?? 0.5),
                tat_compliance: Number(currentWeights.tat_compliance ?? 0.35),
                volume: Number(currentWeights.volume ?? 0.15),
            },
        });
        form.clearErrors();
        setShowConfig(row);
    };

    const submitConfig = event => {
        event.preventDefault();
        form.transform(data => ({
            ...data,
            weights: Object.fromEntries(Object.entries(data.weights).map(([key, value]) => [key, Number(value)])),
        }));
        form.post('/admin/office-tat/configuration', {
            preserveScroll: true,
            onSuccess: () => { setShowConfig(null); form.reset(); },
        });
    };

    return <main className="min-h-screen bg-gray-50 px-3 py-5 dark:bg-gray-950 sm:px-6 lg:px-8">
        <Head title="Office TAT" />
        <div className="mx-auto max-w-[1440px]">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-[10px] font-extrabold uppercase tracking-[0.18em] text-green-800 dark:text-green-300">Administration / Performance</p>
                    <h1 className="mt-1 flex items-center gap-2 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                        <Icon icon="lucide:chart-no-axes-combined" width="24" height="24" className="text-green-700 dark:text-green-300" aria-hidden="true" />
                        Office Turnaround Time
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Report Performance and Average Office Processing Time use eligible live reports and accepted routing events.</p>
                </div>
                <div className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-right dark:border-gray-800 dark:bg-gray-900">
                    <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Results as of</p>
                    <p className="mt-0.5 text-sm font-extrabold text-gray-900 dark:text-white">{formatDate(analytics.as_of_date)}</p>
                </div>
            </header>

            <section className="mt-5 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Office TAT view">
                <ComplianceTabList tabs={[["report-performance", "Report Performance"], ["average-tat", "Average Office Processing Time"]]} value={activeView} onChange={switchView} label="Office TAT view" />
            </section>

            {activeView === "average-tat" && <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Average Office Processing Time office category">
                <ComplianceTabList tabs={categories} value={analytics.category || "PAMO"} onChange={switchCategory} label="Average Office Processing Time office category" />
                <p className="mt-2 px-1 text-xs text-gray-500 dark:text-gray-400">{selectedCategory?.[1] || "Authorized offices"} · live office receipt-to-handoff intervals.</p>
            </section>}

            <section className="mt-4 flex flex-col gap-3 lg:flex-row lg:items-start">
                <FilterToolbar label="Period and date filters" chips={activeView === 'average-tat' ? chips : []} onClear={activeView === 'average-tat' ? clearFilters : null} className="min-w-0 flex-1">
                    <form onSubmit={apply} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <FloatingSelect label="Assessment type" size="sm" value={assessment?.type || 'semestral'} onChange={event => updateAssessment(selectAssessmentType(assessment, event.target.value))}>
                            {(analytics.options?.assessment_types || [{ value: 'semestral', label: 'Semestral' }, { value: 'annual', label: 'Annual' }]).map(type => <option key={type.value} value={type.value}>{type.label}</option>)}
                        </FloatingSelect>
                        <FloatingSelect label="Assessment year" size="sm" value={assessment?.year || ''} onChange={event => updateAssessment({ ...assessment, year: Number(event.target.value) })}>
                            {(analytics.options?.assessment_years || []).map(year => <option key={year} value={year}>{year}</option>)}
                        </FloatingSelect>
                        {assessment?.type === 'semestral' && <FloatingSelect label="Semester" size="sm" value={assessment.semester || 'H1'} onChange={event => updateAssessment({ ...assessment, semester: event.target.value })}>
                            {(analytics.options?.semesters || []).map(semester => <option key={semester.value} value={semester.value}>{semester.label}</option>)}
                        </FloatingSelect>}
                        <DatePicker id="office-tat-as-of" label="As of date" value={draftFilters.as_of_date} maxDate={analytics.as_of_date} onChange={value => setDraftFilters(current => ({ ...current, as_of_date: value }))} />
                        {activeView === 'average-tat' && <>
                        <FloatingInput label="Source requirement year" size="sm" type="number" min="2000" max="2100" placeholder="Any year" value={draftFilters.reporting_year} onChange={event => setDraftFilters(current => ({ ...current, reporting_year: event.target.value }))} />
                        <FloatingInput label="Reporting period" size="sm" placeholder="Any period" value={draftFilters.reporting_period} onChange={event => setDraftFilters(current => ({ ...current, reporting_period: event.target.value }))} />
                        <FloatingSelect label="Report / Activity" size="sm" value={draftFilters.workflow} onChange={event => setDraftFilters(current => ({ ...current, workflow: event.target.value }))}>
                            <option value="all">All available modules</option>
                            {(analytics.options?.workflows || []).map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
                        </FloatingSelect>
                        <FloatingSelect label="Protected Area" size="sm" value={draftFilters.protected_area_id} onChange={event => setDraftFilters(current => ({ ...current, protected_area_id: event.target.value }))}>
                            <option value="">All authorized areas</option>
                            {(analytics.options?.protectedAreas || []).map(area => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </FloatingSelect>
                        </>}
                        <div className="flex items-end sm:col-span-2 sm:justify-end">
                            <Button type="submit" size="compact" className="rounded-lg px-4 py-2 text-xs">
                                <Icon icon="lucide:filter" width="14" height="14" aria-hidden="true" />
                                Apply period
                            </Button>
                        </div>
                    </form>
                </FilterToolbar>
                {activeView === 'average-tat' && <div className="w-full lg:max-w-xs">
                    <FloatingInput label="Search offices" size="sm" type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Office name" leadingIcon={<Icon icon="lucide:search" width="16" height="16" aria-hidden="true" />} />
                </div>}
            </section>

            {activeView === 'report-performance' && <ComplianceFirstAssessments
                data={recordedAssessment}
                periodLabel={periodLabel}
                asOfDate={analytics.as_of_date}
                selectedUnit={displayedReportUnit}
                authorizedUnits={authorizedUnits}
                onUnitChange={switchReportUnit}
            />}

            {activeView === 'average-tat' && <>
            {configurationSaved && <div role="status" className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
                Version {configurationSaved.version} saved for {configurationSaved.office} · {periodLabel}.
            </div>}

            <section className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Average Office Processing Time summary">
                <SummaryCard icon="lucide:clock-3" label="Average processing time" value={liveAverage == null ? 'No completed intervals' : formatDuration(liveAverage)} detail={periodLabel + ' · ' + liveCompleted + ' completed live reports · weighted by report count'} />
                <SummaryCard icon="lucide:files" label="Eligible completed reports" value={liveCompleted} detail="One completed interval sample per source report and office." />
                <SummaryCard icon="lucide:hourglass" label="Pending / incomplete intervals" value={livePending + ' / ' + liveMissing} detail="Pending and incomplete intervals are excluded from the average." />
                {analytics.category === 'PAMO' && pamoPreparation?.available && <>
                    <SummaryCard icon="lucide:hourglass" label="PAMO preparation average" value={pamoPreparationAverage == null ? 'No completed intervals' : formatDuration(pamoPreparationAverage)} detail={periodLabel + ' · first saved draft to accepted PAMO handoff · weighted by report count'} />
                    <SummaryCard icon="lucide:files" label="PAMO preparation reports" value={pamoPreparationCompleted + ' / ' + (pamoPreparation.overall?.documents_started || 0)} detail="Completed samples / drafts started in the selected start-date cohort." />
                    <SummaryCard icon="lucide:clock-3" label="PAMO pending / incomplete" value={pamoPreparationPending + ' / ' + pamoPreparationMissing} detail="Open drafts and incomplete intervals are excluded from the average." />
                </>}
            </section>

            <section className="mt-5" aria-labelledby="tat-ranking-heading">
                <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-green-800 dark:text-green-300">{periodLabel}</p>
                        <h2 id="tat-ranking-heading" className="mt-1 text-lg font-extrabold text-gray-950 dark:text-white">Average Office Processing Time</h2>
                        <p className="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">Elapsed calendar time from an office’s accepted receipt to its next accepted handoff; time in transit and source reporting time are excluded.</p>
                    </div>
                    <span className="rounded-full border border-green-200 bg-green-50 px-3 py-1.5 text-[11px] font-bold text-green-900 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200">Shortest time ranks first</span>
                </div>

                <RankingSection title="Live office processing time" description={periodLabel + ' · accepted office receipt to next accepted handoff · elapsed calendar time.'} rows={liveRanking} evidence="live" />
                {analytics.category === 'PAMO' && pamoPreparation?.available && <RankingSection
                    title="PAMO preparation time"
                    description={periodLabel + ' · first persisted draft to accepted PAMO handoff · correction intervals are summed once per report.'}
                    rows={pamoPreparationRanking}
                    evidence="pamo_preparation"
                />}
                {analytics.category === 'PAMO' && pamoPreparation && !pamoPreparation.available && <div role="status" className="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">PAMO preparation ranking is unavailable: {pamoPreparation.unavailable_reason || 'No preparation interval evidence is available for this period.'}</div>}
            </section>

            <details open={secondaryOpen} onToggle={event => setSecondaryOpen(event.currentTarget.open)} className="mt-5 rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <summary className="cursor-pointer list-none px-4 py-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-700 sm:px-5">
                    <span className="flex flex-wrap items-center justify-between gap-3">
                        <span><span className="block text-sm font-extrabold text-gray-900 dark:text-white">Legacy measures and configuration</span><span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">Saved SLA, target and draft weight versions remain for history; they do not set the official 80/20 compliance-first OTPS.</span></span>
                        <Icon icon={secondaryOpen ? 'lucide:chevron-up' : 'lucide:chevron-down'} width="18" height="18" className="text-gray-500" aria-hidden="true" />
                    </span>
                </summary>
                <div className="border-t border-gray-100 p-3 dark:border-gray-800 sm:p-4">
                    <CrudTable
                        compact
                        title="Office performance details"
                        subtitle={`${periodLabel} · Timeliness follows source deadlines. A target or SLA is required only for the corresponding secondary measure.`}
                        rows={visibleRows}
                        rowKey={row => `${row.office_category}:${row.office}`}
                        emptyTitle="No office rows match"
                        emptyDescription="Clear the office search or select another category."
                        columns={secondaryColumns(canConfigure, startConfig)}
                    />
                </div>
            </details>
            </>}
        </div>

        <CrudFormModal
            open={activeView === 'average-tat' && Boolean(showConfig)}
            title="Office performance settings"
            subtitle={showConfig ? `${showConfig.office} · ${periodLabel} · appends a version` : ''}
            icon={<Icon icon="lucide:settings-2" width="18" height="18" aria-hidden="true" />}
            onClose={() => setShowConfig(null)}
            onSubmit={submitConfig}
            processing={form.processing}
            errors={form.errors}
            saveLabel="Append configuration version"
            canSave={Boolean(showConfig)}
            maxWidth="max-w-xl"
        >
            {showConfig && <div className="space-y-4">
                <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    Average Office Processing Time remains available without an SLA or volume target. The legacy weighted performance score is separate from Overall Score; these settings affect only secondary targets and legacy scores.
                </p>
                <FloatingInput id="office-tat-volume-target" label="Positive volume target" type="number" min="1" max="1000000" required value={form.data.volume_target} onChange={event => form.setData('volume_target', event.target.value)} error={form.errors.volume_target} placeholder="Enter an approved target" />
                <section>
                    <h3 className="text-xs font-bold text-gray-800 dark:text-gray-100">Draft performance score weights</h3>
                    <p className="mt-1 text-[11px] leading-4 text-gray-500 dark:text-gray-400">These saved weights remain separate from fastest-to-slowest processing time rank.</p>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        {Object.entries({ timeliness: 'Timeliness', tat_compliance: 'Within processing limit', volume: 'Volume' }).map(([key, label]) => <FloatingInput
                            key={key}
                            id={`office-tat-weight-${key}`}
                            label={`${label} (%)`}
                            type="number"
                            min="0"
                            max="100"
                            step="1"
                            value={Math.round(Number(form.data.weights[key] || 0) * 100)}
                            onChange={event => form.setData('weights', { ...form.data.weights, [key]: Number(event.target.value) / 100 })}
                        />)}
                    </div>
                </section>
            </div>}
        </CrudFormModal>
    </main>;
}

function RankingSection({ title, description, rows, evidence = 'live' }) {
    const [selectedRow, setSelectedRow] = useState(null);
    const measured = rows.filter(row => row.averageSeconds != null);
    const columns = [
        { key: 'rank', label: 'Rank', headerClassName: 'w-16', render: row => row.rank == null ? 'Not ranked' : <span className="inline-flex h-8 min-w-8 items-center justify-center rounded-full bg-gradient-to-r from-emerald-600 to-green-700 px-2 text-xs font-extrabold text-white">{row.rank}</span> },
        { key: 'office', label: 'Office', render: row => <span className="font-semibold text-gray-900 dark:text-white">{row.office}</span> },
        { key: 'average', label: evidence === 'live' ? 'Average processing time' : 'Average preparation time', headerClassName: 'min-w-48', render: row => <span className={'font-extrabold ' + (row.averageSeconds == null ? 'text-gray-500 dark:text-gray-400' : 'text-green-900 dark:text-green-200')}>{row.averageSeconds == null ? 'No completed interval' : formatDuration(row.averageSeconds)}</span> },
        { key: 'interval', label: 'Processing interval', headerClassName: 'min-w-36', render: row => <DurationBar row={row} maximum={measured.length ? Math.max(...measured.map(item => item.averageSeconds)) : 0} /> },
        { key: 'completed', label: 'Completed reports', headerClassName: 'min-w-32', render: row => <span className="font-bold text-gray-900 dark:text-white">{row.completedDocuments}</span> },
        { key: 'evidence', label: 'Interval evidence', headerClassName: 'min-w-52', render: row => <EvidenceDetails row={row} evidence={evidence} /> },
    ];
    const titleId = 'tat-' + evidence + '-ranking';

    return <>
        <section className="mb-4" aria-labelledby={titleId}>
            <h3 id={titleId} className="sr-only">{title}</h3>
            {measured.length === 0 && <div role="status" className="mb-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                No completed {evidence === 'live' ? 'accepted receipt-to-handoff' : 'PAMO preparation'} intervals are available for this period and filter. Pending and incomplete intervals have no numeric rank.
            </div>}
            <CrudTable
                compact
                title={title}
                subtitle={description}
                helperText="Average = total completed interval time ÷ completed reports. Lower time is faster."
                rows={rows}
                columns={columns}
                rowKey={row => String(row.office_category) + ':' + row.office}
                caption={title + ': shortest valid average ranks first'}
                emptyTitle="No authorized offices to rank"
                emptyDescription="No offices are available for this category and access scope."
                rowFocusVisibleOnly={evidence === 'live'}
                onRowClick={evidence === 'live' ? setSelectedRow : undefined}
                className="mb-3"
            />
        </section>
        {evidence === 'live' && <CrudDetailsModal
            open={Boolean(selectedRow)}
            title="Processing time details"
            subtitle={selectedRow ? selectedRow.office + ' · ' + title : ''}
            onClose={() => setSelectedRow(null)}
            compact
            maxWidth="max-w-4xl"
        >
            {selectedRow && <div className="space-y-3">
                <p className="text-xs leading-5 text-gray-700 dark:text-gray-200">Elapsed calendar time from each accepted office receipt to the next accepted handoff. Each report contributes the sum of its completed correction intervals once.</p>
                {(selectedRow.tatIntervalDetails || []).map(sample => <article key={sample.source_reference} className="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <h3 className="text-xs font-extrabold text-gray-900 dark:text-white">{sample.source_reference}</h3>
                    <p className="mt-1 text-[11px] font-semibold text-emerald-800 dark:text-emerald-200">Total report processing time: {formatDuration(sample.elapsed_seconds)}</p>
                    {(sample.intervals || []).map((interval, index) => <dl key={sample.source_reference + '-' + index} className="mt-2 grid gap-2 text-[11px] sm:grid-cols-3">
                        <DataPoint label="Accepted receipt timestamp" value={interval.accepted_receipt_at} />
                        <DataPoint label="Next accepted handoff timestamp" value={interval.accepted_handoff_at} />
                        <DataPoint label="Elapsed calendar time" value={formatDuration(interval.elapsed_seconds)} />
                    </dl>)}
                </article>)}
                {(selectedRow.tatIntervalDetails || []).length === 0 && <p className="text-xs text-gray-600 dark:text-gray-300">No completed accepted receipt-to-handoff interval is available; pending or incomplete evidence is excluded from the average.</p>}
            </div>}
        </CrudDetailsModal>}
    </>;
}

function DurationBar({ row, maximum }) {
    if (row.averageSeconds == null) return <span className="text-xs text-gray-400">No completed interval</span>;
    const width = maximum > 0 ? Math.max(2, (row.averageSeconds / maximum) * 100) : 2;
    return <div className="min-w-28" aria-label={formatDuration(row.averageSeconds) + '; shorter time is faster'}>
        <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
            <div className="h-full rounded-full bg-gradient-to-r from-emerald-600 to-green-700" style={{ width: width + '%' }} />
        </div>
    </div>;
}

function EvidenceDetails({ row, evidence }) {
    const pending = row.pendingDocuments;
    const missing = row.missingDocuments;
    return <div className="space-y-1 text-xs leading-4 text-gray-600 dark:text-gray-300">
        <p>{evidence === 'live' ? 'Accepted office receipt → next accepted handoff' : 'First saved draft → accepted PAMO handoff'}</p>
        {(pending > 0 || missing > 0) && <p className="text-amber-800 dark:text-amber-300">{pending} pending · {missing} incomplete intervals</p>}
        {pending === 0 && missing === 0 && <p className="text-gray-500 dark:text-gray-400">{row.completedDocuments} completed report samples</p>}
    </div>;
}

function DataPoint({ label, value }) {
    return <div><dt className="font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-0.5 font-semibold text-gray-900 dark:text-gray-100">{value}</dd></div>;
}

function SummaryCard({ icon, label, value, detail }) {
    return <article className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div className="flex items-center justify-between gap-3"><span className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</span><Icon icon={icon} width="17" height="17" className="shrink-0 text-green-700 dark:text-green-300" aria-hidden="true" /></div>
        <p className="mt-2 text-xl font-extrabold tracking-tight text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-500 dark:text-gray-400">{detail}</p>
    </article>;
}

function secondaryColumns(canConfigure, onConfigure) {
    return [
        { key: 'office', label: 'Office', render: row => <span className="font-bold text-gray-900 dark:text-white">{row.office}</span> },
        { key: 'timeliness', label: 'Timeliness', render: row => row.timeliness_percent == null ? 'No eligible reports' : String(Math.round(row.timeliness_percent)) + '%' },
        { key: 'volume', label: 'Live reports / target', render: row => String(row.event_derived_received || 0) + ' / ' + (row.volume_target || 'No target set') },
        { key: 'limit', label: 'Processing limit', render: () => <span title="No office-specific processing limit is configured">No limit set</span> },
        { key: 'score', label: 'Legacy score', render: row => <span title="Legacy configurable measure; excluded from Overall Score">{row.score == null ? 'No target or limit set' : row.score}</span> },
        { key: 'configuration', label: 'Configuration', headerClassName: 'text-right', cellClassName: 'text-right', render: row => canConfigure
            ? <UtilityIconButton compact aria-label={'Configure ' + row.office} title={'Configure ' + row.office} onClick={() => onConfigure(row)}><Icon icon="lucide:settings-2" width="15" height="15" aria-hidden="true" /></UtilityIconButton>
            : <span className="text-xs text-gray-400">Admin only</span> },
    ];
}

function makeFilterChips(filters, options, remove) {
    const chips = [];
    if (filters.reporting_year) chips.push({ key: 'reporting_year', label: 'Year', value: filters.reporting_year, onRemove: () => remove('reporting_year', '') });
    if (filters.reporting_period) chips.push({ key: 'reporting_period', label: 'Period', value: filters.reporting_period, onRemove: () => remove('reporting_period', '') });
    if (filters.workflow && filters.workflow !== 'all') {
        const workflow = (options.workflows || []).find(item => item.value === filters.workflow);
        chips.push({ key: 'workflow', label: 'Report', value: workflow?.label || filters.workflow, onRemove: () => remove('workflow', 'all') });
    }
    if (filters.protected_area_id) {
        const area = (options.protectedAreas || []).find(item => String(item.id) === String(filters.protected_area_id));
        chips.push({ key: 'protected_area_id', label: 'Protected Area', value: area?.name || filters.protected_area_id, onRemove: () => remove('protected_area_id', '') });
    }
    return chips;
}

function sum(rows, getValue) {
    return rows.reduce((total, row) => total + Number(getValue(row) || 0), 0);
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
