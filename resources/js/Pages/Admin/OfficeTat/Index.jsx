import { Head, router, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useMemo, useState } from 'react';
import Button from '@/Components/Button';
import ComplianceTabList from '@/Components/Compliance/ComplianceTabList';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudTable from '@/Components/Crud/CrudTable';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput, FloatingSelect } from '@/Components/Form';
import UtilityIconButton from '@/Components/UtilityIconButton';
import ComplianceFirstAssessments from './ComplianceFirstAssessments';
import DocumentTurnaround from './DocumentTurnaround';
import { formatDuration, rankTatRows, weightedAverageSeconds } from './ranking.mjs';
import { assessmentPeriodKey, parseAssessmentPeriodKey, selectAssessmentType } from './assessmentPeriodSelection.mjs';
import { officeTatDisplayUnit } from './unitPresentation.mjs';

const categories = [
    ['PAMO', 'PAMO offices'],
    ['CENRO', 'CENRO offices'],
];

const defaultWeights = { timeliness: 0.5, tat_compliance: 0.35, volume: 0.15 };
const historicalGapBases = {
    preparation: 'Date Conducted → Date Accomplished',
    processing: 'Accomplished date → Original CENRO Release',
    handoff: 'Original CENRO Release → Original PENRO Receipt',
};

export default function OfficeTatIndex({ analytics = {}, filters = {}, canConfigure = false, configurationSaved = null, assessmentSaved = null, complianceAssessments = null, recordedAssessment = null, documentTurnaround = null, selectedUnit = 'conservation', authorizedUnits = [], selectedView = 'report-performance', globalAssessmentAccess = false }) {
    const [search, setSearch] = useState('');
    const [showConfig, setShowConfig] = useState(null);
    const [secondaryOpen, setSecondaryOpen] = useState(false);
    const [requestedHistoricalBasis, setRequestedHistoricalBasis] = useState('processing');
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
        evidence_type: filters.evidence_type || 'all',
    });
    useEffect(() => {
        const nextAssessment = analytics.assessment_period || parseAssessmentPeriodKey(analytics.processing_period);
        if (nextAssessment) setAssessment(nextAssessment);
        if (analytics.processing_period) setDraftFilters(current => ({ ...current, processing_period: analytics.processing_period }));
    }, [analytics.processing_period]);
    useEffect(() => setActiveView(selectedView || 'report-performance'), [selectedView]);
    const form = useForm({ office_category: '', office: '', period_key: '', volume_target: '', weights: defaultWeights });
    const selectedCategory = categories.find(([value]) => value === analytics.category);
    const evidenceType = analytics.evidence_type || filters.evidence_type || 'all';
    const includeLive = evidenceType === 'all' || evidenceType === 'live';
    const includeHistorical = evidenceType === 'all' || evidenceType === 'historical';
    const historicalBasis = analytics.category === 'CENRO' ? 'handoff' : requestedHistoricalBasis;
    const historicalGapLabel = historicalGapBases[historicalBasis] || historicalGapBases.processing;
    const periodLabel = analytics.processing_period_label || assessment?.label || analytics.processing_period || 'Selected assessment period';

    const visibleRows = useMemo(() => (analytics.rows || []).filter(row =>
        String(row.office || '').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()),
    ), [analytics.rows, search]);
    const liveRanking = useMemo(() => rankTatRows(visibleRows, 'live'), [visibleRows]);
    const historicalRanking = useMemo(() => rankTatRows(visibleRows, 'historical', historicalBasis), [visibleRows, historicalBasis]);
    const pamoPreparation = analytics.pamo_preparation || null;
    const visiblePreparationRows = useMemo(() => (pamoPreparation?.rows || []).filter(row =>
        String(row.office || '').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()),
    ), [pamoPreparation?.rows, search]);
    const pamoPreparationRanking = useMemo(() => rankTatRows(visiblePreparationRows, 'pamo_preparation'), [visiblePreparationRows]);
    const liveAverage = weightedAverageSeconds(liveRanking);
    const historicalAverage = weightedAverageSeconds(historicalRanking);
    const liveCompleted = sum(liveRanking, row => row.completedDocuments);
    const livePending = sum(liveRanking, row => row.pendingDocuments);
    const liveMissing = sum(liveRanking, row => row.missingDocuments);
    const historicalCompleted = sum(historicalRanking, row => row.completedDocuments);
    const historicalMissing = sum(historicalRanking, row => row.missingDocuments + row.invalidDocuments);
    const historicalUnavailable = sum(historicalRanking, row => row.unsupportedDocuments + row.notApplicableDocuments);
    const historicalReportCount = sum(historicalRanking, row => row.historicalReports);
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
            evidence_type: 'all',
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
                    <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Review the separate compliance-first unit ratings and the existing receipt-to-handoff Average TAT measures. Historical date gaps remain separate from live evidence.</p>
                </div>
                <div className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-right dark:border-gray-800 dark:bg-gray-900">
                    <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Results as of</p>
                    <p className="mt-0.5 text-sm font-extrabold text-gray-900 dark:text-white">{formatDate(analytics.as_of_date)}</p>
                </div>
            </header>

            <section className="mt-5 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Office TAT view">
                <ComplianceTabList tabs={[["report-performance", "Report Performance"], ["average-tat", "Average TAT"]]} value={activeView} onChange={switchView} label="Office TAT view" />
            </section>

            {activeView === "average-tat" && <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900" aria-label="Average TAT office category">
                <ComplianceTabList tabs={categories} value={analytics.category || "PAMO"} onChange={switchCategory} label="Average TAT office category" />
                <p className="mt-2 px-1 text-xs text-gray-500 dark:text-gray-400">{selectedCategory?.[1] || "Authorized offices"} · PA custody and ENGP office receipt-to-handoff intervals remain separate.</p>
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
                        <FloatingSelect label="Evidence basis" size="sm" value={draftFilters.evidence_type} onChange={event => setDraftFilters(current => ({ ...current, evidence_type: event.target.value }))}>
                            <option value="all">Live and historical, shown separately</option>
                            <option value="live">Live routing events</option>
                            <option value="historical">Manual historical dates</option>
                        </FloatingSelect>
                        {includeHistorical && analytics.category === 'PAMO' && <FloatingSelect label="Historical date gap" size="sm" value={historicalBasis} onChange={event => setRequestedHistoricalBasis(event.target.value)}>
                            {Object.entries(historicalGapBases).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </FloatingSelect>}
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
                complianceAssessments={globalAssessmentAccess ? complianceAssessments : null}
                canConfigure={canConfigure}
                periodLabel={periodLabel}
                periodKey={analytics.processing_period}
                asOfDate={analytics.as_of_date}
                selectedUnit={displayedReportUnit}
                authorizedUnits={authorizedUnits}
                onUnitChange={switchReportUnit}
                saved={assessmentSaved}
            />}
            {activeView === 'report-performance' && <DocumentTurnaround
                data={documentTurnaround}
                selectedUnit={displayedReportUnit}
                periodLabel={periodLabel}
            />}

            {activeView === 'average-tat' && <>
            {configurationSaved && <div role="status" className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
                Version {configurationSaved.version} saved for {configurationSaved.office} · {periodLabel}.
            </div>}

            <section className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Average turnaround time summary">
                {includeLive && <>
                    <SummaryCard icon="lucide:clock-3" label="Overall live average TAT" value={liveAverage == null ? 'N/A' : formatDuration(liveAverage)} detail={`${periodLabel} · ${liveCompleted} completed documents · weighted by document count`} />
                    <SummaryCard icon="lucide:files" label="Live completed documents" value={liveCompleted} detail={`${periodLabel} · one sample per source document and office`} />
                    <SummaryCard icon="lucide:hourglass" label="Live pending / missing" value={`${livePending} / ${liveMissing}`} detail={`${periodLabel} · incomplete and invalid interval evidence stays outside the average`} />
                </>}
                {includeHistorical && <>
                <SummaryCard
                    icon="lucide:archive"
                    label={`Historical gap · ${historicalBasis === 'preparation' ? 'preparation' : historicalBasis === 'processing' ? 'processing' : 'handoff'}`}
                    value={historicalAverage == null ? 'N/A' : formatDuration(historicalAverage)}
                    detail={`${periodLabel} · ${historicalCompleted} eligible documents · ${historicalGapLabel} · Asia/Manila calendar days`}
                />
                <SummaryCard icon="lucide:archive" label="Eligible historical reports" value={historicalReportCount} detail={`${periodLabel} · authorized reports with an original receipt anchor in this cohort`} />
                <SummaryCard icon="lucide:clock-3" label="Missing / invalid · unsupported / N/A" value={`${historicalMissing} · ${historicalUnavailable}`} detail="Incomplete intervals are excluded from their date-gap averages; unsupported source endpoints are reported separately." />
                </>}
                {includeLive && analytics.category === 'PAMO' && pamoPreparation?.available && <>
                    <SummaryCard icon="lucide:hourglass" label="PAMO preparation average" value={pamoPreparationAverage == null ? 'N/A' : formatDuration(pamoPreparationAverage)} detail={`${periodLabel} · first saved draft to accepted PAMO handoff · document weighted`} />
                    <SummaryCard icon="lucide:files" label="PAMO preparation documents" value={`${pamoPreparationCompleted} / ${pamoPreparation.overall?.documents_started || 0}`} detail="Completed samples / drafts started in the selected start-date cohort" />
                    <SummaryCard icon="lucide:clock-3" label="PAMO pending / missing evidence" value={`${pamoPreparationPending} / ${pamoPreparationMissing}`} detail="Open drafts and correction episodes remain pending; missing clocks stay outside the average." />
                </>}
            </section>

            <section className="mt-5" aria-labelledby="tat-ranking-heading">
                <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-green-800 dark:text-green-300">{periodLabel} · {evidenceLabel(evidenceType)}</p>
                        <h2 id="tat-ranking-heading" className="mt-1 text-lg font-extrabold text-gray-950 dark:text-white">Turnaround Time and Historical Date Gap Ranking</h2>
                        <p className="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">Live time runs from an office’s accepted receipt to its next accepted handoff. Historical date gaps use separate original source dates and never represent live custody time.</p>
                    </div>
                    <span className="rounded-full border border-green-200 bg-green-50 px-3 py-1.5 text-[11px] font-bold text-green-900 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200">Shortest duration ranks first</span>
                </div>

                {includeLive && <RankingSection title="Live routing event average" description={`${periodLabel} · accepted receipt-to-handoff event durations · calendar time · one completed sample per office and source record.`} rows={liveRanking} evidence="live" />}
                {includeHistorical && <RankingSection
                    title={`Historical date gap · ${historicalGapLabel}`}
                    description={`${periodLabel} · ${historicalAttributionDescription(analytics.category, historicalBasis)}`}
                    rows={historicalRanking}
                    evidence="historical"
                    basis={historicalBasis}
                />}
                {includeLive && analytics.category === 'PAMO' && pamoPreparation?.available && <RankingSection
                    title="PAMO preparation time"
                    description={`${periodLabel} · first persisted draft start → successful PAMO outbound handoff · supported correction intervals are included once per document · cohort by start date`}
                    rows={pamoPreparationRanking}
                    evidence="pamo_preparation"
                />}
                {includeLive && analytics.category === 'PAMO' && pamoPreparation && !pamoPreparation.available && <div role="status" className="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">PAMO preparation ranking is unavailable: {pamoPreparation.unavailable_reason || 'pilot evidence is not available for this period.'}</div>}
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
                        columns={secondaryColumns(canConfigure, startConfig, evidenceType)}
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
                    Average TAT remains available without an SLA or volume target. The weighted performance score is a different measure from compliance-first OTPS; these settings affect only secondary targets and legacy scores.
                </p>
                <FloatingInput id="office-tat-volume-target" label="Positive volume target" type="number" min="1" max="1000000" required value={form.data.volume_target} onChange={event => form.setData('volume_target', event.target.value)} error={form.errors.volume_target} placeholder="Enter an approved target" />
                <section>
                    <h3 className="text-xs font-bold text-gray-800 dark:text-gray-100">Draft performance score weights</h3>
                    <p className="mt-1 text-[11px] leading-4 text-gray-500 dark:text-gray-400">These saved weights remain separate from fastest-to-slowest average TAT rank.</p>
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

function RankingSection({ title, description, rows, evidence, basis = 'handoff' }) {
    const measured = rows.filter(row => row.averageSeconds != null);
    const columns = [
        { key: 'rank', label: 'Rank', headerClassName: 'w-16', render: row => row.rank == null ? <span className="text-gray-400">N/A</span> : <span className="inline-flex h-8 min-w-8 items-center justify-center rounded-full bg-gradient-to-r from-emerald-600 to-green-700 px-2 text-xs font-extrabold text-white">{row.rank}</span> },
        { key: 'office', label: evidence === 'historical' ? 'Reports attributed to' : 'Office', render: row => <span className="font-semibold text-gray-900 dark:text-white">{row.office}</span> },
        { key: 'average', label: evidence === 'historical' ? 'Average gap · calendar days' : (evidence === 'pamo_preparation' ? 'Average preparation' : 'Average TAT'), headerClassName: 'min-w-48', render: row => <span className={`font-extrabold ${row.averageSeconds == null ? 'text-gray-500 dark:text-gray-400' : 'text-green-900 dark:text-green-200'}`}>{row.averageSeconds == null ? 'N/A' : formatDuration(row.averageSeconds)}</span> },
        { key: 'duration', label: 'Duration comparison', headerClassName: 'min-w-36', render: row => <DurationBar row={row} maximum={measured.length ? Math.max(...measured.map(item => item.averageSeconds)) : 0} /> },
        ...(evidence === 'historical' ? [{ key: 'historical-reports', label: 'Historical reports', headerClassName: 'min-w-28', render: row => <span className="font-bold text-gray-900 dark:text-white">{row.historicalReports}</span> }] : []),
        { key: 'completed', label: evidence === 'historical' ? 'Eligible gap samples' : 'Completed documents', headerClassName: 'min-w-32', render: row => <span className="font-bold text-gray-900 dark:text-white">{row.completedDocuments}</span> },
        { key: 'evidence', label: 'Evidence / incomplete', headerClassName: 'min-w-52', render: row => <EvidenceDetails row={row} evidence={evidence} basis={basis} /> },
    ];
    const titleId = `tat-${evidence}-ranking`;

    return <section className="mb-4" aria-labelledby={titleId}>
        <h3 id={titleId} className="sr-only">{title}</h3>
        {measured.length === 0 && <div role="status" className="mb-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">No valid {evidence === 'live' ? 'live event' : evidence === 'pamo_preparation' ? 'PAMO preparation' : `${historicalGapBases[basis] || 'historical date'} gap`} averages for this period and filter. {evidence === 'historical' && `${rows.reduce((total, row) => total + row.historicalReports, 0)} eligible historical reports remain visible under the original receipt cohort. `}Offices with no valid samples have no numeric rank.</div>}
        <CrudTable
            compact
            title={title}
            subtitle={description}
            helperText="Average = total eligible document duration Ã· completed documents. Lower time is faster."
            rows={rows}
            columns={columns}
            rowKey={row => `${row.office_category}:${row.office}`}
            caption={`${title}: shortest valid average duration ranks first`}
            emptyTitle="No authorized offices to rank"
            emptyDescription="No offices are available for this category and access scope."
            className="mb-3"
        />
    </section>;
}

function DurationBar({ row, maximum }) {
    if (row.averageSeconds == null) return <span className="text-xs text-gray-400">No measurement</span>;
    const width = maximum > 0 ? Math.max(2, (row.averageSeconds / maximum) * 100) : 2;
    return <div className="min-w-28" aria-label={`${formatDuration(row.averageSeconds)}; shorter duration is faster`}>
        <div className="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" aria-hidden="true">
            <div className="h-full rounded-full bg-gradient-to-r from-emerald-600 to-green-700" style={{ width: `${width}%` }} />
        </div>
    </div>;
}

function EvidenceDetails({ row, evidence, basis }) {
    const historical = evidence === 'historical';
    const pending = row.pendingDocuments;
    const missing = row.missingDocuments;
    return <div className="space-y-1 text-xs leading-4 text-gray-600 dark:text-gray-300">
        <p>{historical
            ? `${historicalGapBases[basis] || historicalGapBases.handoff} · ${row.office_category === 'PAMO' ? 'grouped by assigned PAMO; this reconstructed interval is not PAMO custody time' : 'original ENGP date evidence'}`
            : evidence === 'pamo_preparation' ? 'First saved draft → accepted PAMO handoff; completed correction periods are included once per document.' : 'Accepted office receipt → next accepted handoff'}</p>
        {historical && <p>{row.completedDocuments} eligible document sample{row.completedDocuments === 1 ? '' : 's'}</p>}
        {(pending > 0 || missing > 0 || row.invalidDocuments > 0 || row.unsupportedDocuments > 0 || row.notApplicableDocuments > 0) && <p className="text-amber-800 dark:text-amber-300">{!historical && `${pending} pending`}{!historical && missing > 0 ? ' · ' : ''}{missing > 0 && `${missing} missing`}{row.invalidDocuments > 0 && `${missing > 0 ? ' · ' : ''}${row.invalidDocuments} invalid`}{row.unsupportedDocuments > 0 && ` · ${row.unsupportedDocuments} unsupported`}{row.notApplicableDocuments > 0 && ` · ${row.notApplicableDocuments} not applicable`}</p>}
        {!historical && pending === 0 && missing === 0 && <p className="text-gray-500 dark:text-gray-400">{row.completedDocuments} valid completed sample{row.completedDocuments === 1 ? '' : 's'}</p>}
    </div>;
}

function historicalAttributionDescription(category, basis) {
    return category === 'PAMO'
        ? `PA records use the Protected Area’s assigned PAMO. The ${historicalGapBases[basis] || historicalGapBases.processing} gap reconstructs original document dates and is not measured PAMO custody time.`
        : 'ENGP records are grouped by their assigned CENRO. ENGP has no parent conducted or accomplished date in its source contract, so only original CENRO release → PENRO receipt is supported.';
}

function SummaryCard({ icon, label, value, detail }) {
    return <article className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div className="flex items-center justify-between gap-3"><span className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</span><Icon icon={icon} width="17" height="17" className="shrink-0 text-green-700 dark:text-green-300" aria-hidden="true" /></div>
        <p className="mt-2 text-xl font-extrabold tracking-tight text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-500 dark:text-gray-400">{detail}</p>
    </article>;
}

function secondaryColumns(canConfigure, onConfigure, evidenceType) {
    const volumeLabel = evidenceType === 'historical'
        ? 'Historical reports / target'
        : evidenceType === 'live'
            ? 'Live documents / target'
            : 'Live / historical / target';
    return [
        { key: 'office', label: 'Office', render: row => <span className="font-bold text-gray-900 dark:text-white">{row.office}</span> },
        { key: 'timeliness', label: 'Timeliness', render: row => row.timeliness_percent == null ? 'N/A' : `${Math.round(row.timeliness_percent)}%` },
        { key: 'volume', label: volumeLabel, render: row => evidenceType === 'historical'
            ? `${row.historical_report_count || 0} / ${row.volume_target || 'No target'}`
            : evidenceType === 'live'
                ? `${row.event_derived_received || 0} / ${row.volume_target || 'No target'}`
                : `${row.event_derived_received || 0} live + ${row.historical_report_count || 0} historical / ${row.volume_target || 'No target'}` },
        { key: 'limit', label: 'Within processing limit', render: row => <span title="No office-specific processing limit is configured">N/A</span> },
        { key: 'score', label: 'Legacy draft score', render: row => <span title="Legacy configurable 50/35/15 measure; excluded from official OTPS">{row.score == null ? 'N/A' : row.score}</span> },
        { key: 'configuration', label: 'Configuration', headerClassName: 'text-right', cellClassName: 'text-right', render: row => canConfigure
            ? <UtilityIconButton compact aria-label={`Configure ${row.office}`} title={`Configure ${row.office}`} onClick={() => onConfigure(row)}><Icon icon="lucide:settings-2" width="15" height="15" aria-hidden="true" /></UtilityIconButton>
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
    if (filters.evidence_type && filters.evidence_type !== 'all') chips.push({ key: 'evidence_type', label: 'Evidence', value: filters.evidence_type === 'live' ? 'Live routing events' : 'Manual historical dates', onRemove: () => remove('evidence_type', 'all') });
    return chips;
}

function evidenceLabel(value) {
    return value === 'live' ? 'Live routing events' : value === 'historical' ? 'Manual historical dates' : 'Live and historical, ranked separately';
}

function sum(rows, getValue) {
    return rows.reduce((total, row) => total + Number(getValue(row) || 0), 0);
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
