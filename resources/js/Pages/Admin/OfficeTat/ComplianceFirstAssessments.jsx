import { Icon } from '@iconify/react';
import { useEffect, useState } from 'react';
import Button from '@/Components/Button';
import ComplianceTabList from '@/Components/Compliance/ComplianceTabList';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudTable from '@/Components/Crud/CrudTable';
import Modal from '@/Components/Modal';
import OfficeTatAssessmentSettings from './OfficeTatAssessmentSettings';
import { recordedResultPresentation } from './recordedResultPresentation.mjs';
import { officeTatDisplayUnit } from './unitPresentation.mjs';

const unitTabs = [['conservation', 'Conservation'], ['development', 'Development']];

export default function ComplianceFirstAssessments({
    data = null, complianceAssessments = null, canConfigure = false, periodLabel = '',
    periodKey = '', asOfDate = '', selectedUnit = 'conservation', authorizedUnits = [],
    onUnitChange = () => {}, saved = null,
}) {
    const [showOfficial, setShowOfficial] = useState(false);
    const [detailRow, setDetailRow] = useState(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const assessmentUnit = officeTatDisplayUnit(data, selectedUnit);
    const official = complianceAssessments?.[assessmentUnit] || null;
    const officialAvailable = Boolean(official?.coverage_complete);
    const activeOfficial = showOfficial && officialAvailable;
    const active = activeOfficial ? official : data;
    const rows = active?.rows || [];

    useEffect(() => {
        setShowOfficial(false);
        setDetailRow(null);
    }, [assessmentUnit, periodKey, asOfDate]);

    const tableRows = rows.map(row => ({
        ...row,
        dueValue: row.due_count ?? (row.score_status === 'No due reports' ? 0 : null),
        activityValue: activeOfficial ? (row.submitted_count || 0) : (row.activity_count || 0),
        receivedValue: activeOfficial ? (row.submitted_count || 0) : (row.received_activity_count || 0),
    }));
    const dueCount = activeOfficial ? (official.due_count ?? (official.unit_score_status === 'No due reports' ? 0 : null)) : data?.due_count;
    const onTimeCount = activeOfficial ? official.on_time_count : data?.on_time_count;
    const activityCount = activeOfficial ? (official.submitted_count || 0) : (data?.activity_count || 0);
    const receivedCount = activeOfficial ? (official.submitted_count || 0) : (data?.received_activity_count || 0);
    const tc = activeOfficial ? official.tc_percent : data?.tc_percent;
    const se = activeOfficial ? official.se_percent : data?.se_percent;
    const otps = activeOfficial ? official.otps : data?.otps;
    const notYetDue = activeOfficial ? null : data?.not_yet_due_count;
    const incompleteDates = activeOfficial ? null : data?.incomplete_date_count;
    const basis = activeOfficial
        ? 'Official complete-period assessment · all required obligations'
        : 'Based on recorded reports · full-period coverage incomplete';
    const detailResult = detailRow && !activeOfficial
        ? recordedResultPresentation(detailRow, asOfDate)
        : null;

    const columns = [
        { key: 'rank', label: 'Rank', render: row => row.rank || rankStatus(row) },
        { key: 'participant', label: assessmentUnit === 'conservation' ? 'Protected Area' : 'CENRO', headerClassName: 'min-w-52', render: row => <div><p className="font-bold text-gray-950 dark:text-white">{row.name}</p>{assessmentUnit === 'conservation' && <p className="mt-1 text-[11px] text-gray-600 dark:text-gray-300">Directory status: {row.legal_status || 'Unknown'}{row.office_binding ? ' · PAMO ' + row.office_binding : ''}</p>}</div> },
        { key: 'activity', label: activeOfficial ? 'Submitted' : 'Recorded activities', render: row => displayCount(row.activityValue) },
        { key: 'received', label: 'Received by cutoff', render: row => displayCount(row.receivedValue) },
        { key: 'due', label: 'Assessable due (N)', render: row => row.dueValue == null ? '—' : displayCount(row.dueValue) },
        { key: 'on_time', label: 'On time / N', render: row => row.dueValue ? displayCount(row.on_time_count) + ' / ' + displayCount(row.dueValue) : '—' },
        { key: 'tc', label: 'Timeliness Compliance', headerClassName: 'min-w-40', render: row => formatPercent(row.tc_percent) },
        { key: 'se', label: 'Submission Efficiency', headerClassName: 'min-w-40', render: row => formatPercent(row.se_percent) },
        { key: 'otps', label: 'Overall Score (OTPS)', render: row => row.otps == null ? '—' : Number(row.otps).toFixed(2) },
    ];

    return <section className="mt-4 space-y-4" aria-labelledby="report-performance-heading">
        <header className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p className="text-[10px] font-extrabold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">{periodLabel} · As of {asOfDate}</p>
                <h2 id="report-performance-heading" className="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">Report Performance</h2>
                <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Conservation uses the six Protected Area roster. Development uses the four CENRO ENGP roster. PA reporting remains attributed to its Protected Area, regardless of custody.</p>
                {assessmentUnit === 'conservation' && <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">Proposed directory status is shown as stored. Registration eligibility is handled separately.</p>}
            </div>
            {canConfigure && complianceAssessments && <Button type="button" size="compact" variant="cancel" onClick={() => setSettingsOpen(true)} className="rounded-lg px-4 py-2.5 text-xs"><Icon icon="lucide:settings-2" width="15" height="15" className="mr-2" aria-hidden="true" />Assessment settings</Button>}
        </header>

        <div className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <ComplianceTabList tabs={unitTabs.filter(([key]) => authorizedUnits.includes(key))} value={assessmentUnit} onChange={onUnitChange} label="Report performance unit" />
        </div>

        <div role="status" className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
            <p className="font-extrabold">{basis}</p>
            <p>{activeOfficial ? 'The official result uses the supported full required-obligation inventory.' : 'Only unique live source identities with supported dates are scored. Records available does not prove that every required report or event is represented.'}</p>
            {!activeOfficial && <p className="mt-1">{displayCount(notYetDue)} recorded activities are not yet due · {displayCount(incompleteDates)} have incomplete dates · {displayCount(data?.incomplete_evidence_count)} have unsupported or conflicting evidence.</p>}
        </div>

        {official && <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <p className="font-extrabold">Official complete-period assessment: {official.coverage_complete ? 'Coverage complete' : 'Coverage incomplete'}.</p>
            {!official.coverage_complete && <p>Records are available. Full-period coverage is not yet established.</p>}
            {official.coverage_complete && <button type="button" onClick={() => setShowOfficial(value => !value)} className="mt-1 font-bold underline">{activeOfficial ? 'Return to recorded reports' : 'View official complete-period results'}</button>}
            {!official.coverage_complete && (official.coverage_reasons || []).length > 0 && <details className="mt-1"><summary className="cursor-pointer font-semibold underline">Coverage details</summary><p className="mt-1">Some required duties, applicability periods, source dates, event inventories or historical evidence still need an authoritative basis. Missing rows are not treated as exemptions or verified misses.</p></details>}
        </div>}

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Summary label={activeOfficial ? 'Submitted' : 'Recorded activities'} value={displayCount(activityCount)} detail="Unique source identities in this view" />
            <Summary label="Received by cutoff" value={displayCount(receivedCount)} detail="Accepted PENRO receipts, counted once" />
            <Summary label="Assessable due (N)" value={dueCount == null ? '—' : displayCount(dueCount)} detail={dueCount === 0 ? 'No due obligations; score is N/A' : 'Only due, assessable obligations enter the denominator'} />
            <Summary label="Overall Score (OTPS)" value={otps == null ? 'N/A' : Number(otps).toFixed(2)} detail="80% TC + 20% SE" />
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
            <Summary label="Timeliness Compliance" value={formatPercent(tc)} detail={dueCount > 0 ? displayCount(onTimeCount) + ' on time / ' + displayCount(dueCount) + ' due' : 'N/A · no assessable due obligations'} />
            <Summary label="Submission Efficiency" value={formatPercent(se)} detail="Mean efficiency across the same due N" />
            <Summary label="Not yet due / incomplete" value={activeOfficial ? '—' : displayCount(notYetDue) + ' / ' + displayCount(incompleteDates)} detail="Future deadlines are excluded from N; missing dates are not scored." />
        </div>

        <details className="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <summary className="cursor-pointer px-4 py-3 text-xs font-bold text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:text-gray-100">How these measures work</summary>
            <div className="border-t border-gray-100 px-4 py-3 text-xs leading-5 text-gray-700 dark:border-gray-800 dark:text-gray-200">
                <p><strong>Timeliness Compliance (TC)</strong> is on-time obligations divided by due N. <strong>Submission Efficiency (SE)</strong> averages the existing duration formula across the same due N; verified misses contribute zero, while unknown evidence is excluded from the assessable population.</p>
                <p className="mt-1"><strong>Overall Score (OTPS)</strong> is 80% TC plus 20% SE. Ranks sort by TC, SE, early percentage, unique received volume, then supported highly technical on-time count. Technical classification limits ranking only when that tie-break is needed.</p>
            </div>
        </details>

        <CrudTable compact title={(active?.label || 'Unit') + ' ranking'} subtitle={basis + ' · ' + periodLabel} helperText="Open a participant row for source dates, receipt status and evidence notes." rows={tableRows} rowKey={row => row.participant_key} onRowClick={setDetailRow} rowFocusVisibleOnly caption={basis + ', ' + periodLabel + ', participant ranking'} emptyTitle="No recorded source reports" emptyDescription="No eligible live source identity is available for this unit and period. The full-period assessment remains separate." columns={columns} />

        <CrudDetailsModal open={Boolean(detailRow)} title="Participant performance details" subtitle={detailRow ? detailRow.name + ' · ' + (activeOfficial ? 'official complete-period assessment' : 'recorded reports') + ' · ' + periodLabel : ''} icon={<Icon icon="lucide:clipboard-check" width="18" height="18" aria-hidden="true" />} onClose={() => setDetailRow(null)} compact maxWidth="max-w-4xl" summary={detailRow && <div className="space-y-2"><div className="grid gap-2 sm:grid-cols-4"><Metric label="Due denominator N" value={detailRow.dueValue == null ? 'N/A' : detailRow.dueValue} /><Metric label="Recorded activities" value={detailRow.activityValue} /><Metric label="Received by cutoff" value={detailRow.receivedValue} /><Metric label="Result" value={detailResult?.label || detailRow.score_status || 'Not assessed'} /></div>{detailResult?.nextDeadline && <p role="status" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">Received — not yet due · Next deadline: <time dateTime={detailResult.nextDeadline}>{detailResult.nextDeadline}</time></p>}</div>}>
            {detailRow && <div className="space-y-3">
                <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-700 dark:bg-gray-950 dark:text-gray-200">{activeOfficial ? 'This result covers the full official unit assessment and its all-required-obligations denominator.' : 'This result covers supported live report identities only. It does not assert that missing obligations or events did not occur.'}{assessmentUnit === 'conservation' ? ' Proposed status is a directory fact; registration eligibility is separate.' : ''}</p>
                {(detailRow.obligation_details || []).map((item, index) => <article key={detailRow.participant_key + '-' + index} className="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <h3 className="text-xs font-extrabold text-gray-900 dark:text-white">{item.label || 'Source activity'}</h3>
                    <p className={`mt-1 text-[11px] font-semibold ${item.status === 'Submitted late' ? 'text-amber-900 dark:text-amber-200' : 'text-emerald-800 dark:text-emerald-200'}`}>{item.status || 'Source detail'}</p>
                    <dl className="mt-3 grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                        <DataPoint label="Source start" value={item.start_date || 'Incomplete'} />
                        <DataPoint label="Source deadline" value={item.deadline || 'Incomplete'} />
                        <DataPoint label="Accepted PENRO receipt" value={item.received || 'No accepted receipt by cutoff'} />
                        <DataPoint label="Allowed / elapsed" value={(item.allowed_days == null ? 'Unavailable' : item.allowed_days + ' days') + ' / ' + (item.days_used == null ? 'Unavailable' : item.days_used + ' days')} />
                    </dl>
                    {item.source_rows_matched > 1 && <p className="mt-2 text-[11px] text-gray-600 dark:text-gray-300">{item.source_rows_matched} source rows matched this identity. The earliest accepted receipt is counted once.</p>}
                    {item.issue && <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[11px] leading-4 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">{item.issue}</p>}
                </article>)}
                {(detailRow.obligation_details || []).length === 0 && <p className="text-xs text-gray-600 dark:text-gray-300">No due obligation detail is available. Activity and future-deadline counts remain visible in the summary.</p>}
            </div>}
        </CrudDetailsModal>

        {canConfigure && complianceAssessments && <Modal open={settingsOpen} onClose={() => setSettingsOpen(false)} title="Assessment settings" size="lg" footer={<Button type="button" variant="cancel" onClick={() => setSettingsOpen(false)} className="rounded-lg px-4 py-2">Close settings</Button>}>
            <div className="max-h-[70vh] overflow-y-auto">
            <OfficeTatAssessmentSettings data={complianceAssessments} recordedAssessment={data} selectedUnit={assessmentUnit} canConfigure={canConfigure} periodLabel={periodLabel} periodKey={periodKey} asOfDate={asOfDate} saved={saved} />
            </div>
        </Modal>}
    </section>;
}

function Summary({ label, value, detail }) {
    return <article className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900"><p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p><p className="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{value}</p><p className="mt-1 text-[10px] leading-4 text-gray-600 dark:text-gray-300">{detail}</p></article>;
}

function Metric({ label, value }) {
    return <article className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-950"><p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p><p className="mt-1 text-sm font-extrabold text-gray-900 dark:text-white">{value}</p></article>;
}

function DataPoint({ label, value }) {
    return <div><dt className="font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-0.5 font-semibold text-gray-900 dark:text-gray-100">{value}</dd></div>;
}

function displayCount(value) {
    return value == null ? '0' : Number(value).toLocaleString();
}

function formatPercent(value) {
    return value == null ? 'N/A' : Number(value).toFixed(2) + '%';
}

function rankStatus(row) {
    if (row.rank_status === 'Awaiting technical classification') return 'Tie needs classification';
    if (row.rank_status === 'Awaiting tie resolution') return 'Tie needs review';
    if (row.rank_status === 'Comparison limited by organizational access') return 'No unit rank';
    if (row.score_status === 'No due reports' || row.score_status === 'No assessable due reports') return 'N/A · no score';
    if (row.score_status === 'Coverage incomplete') return 'Coverage incomplete';
    return 'Not ranked';
}
