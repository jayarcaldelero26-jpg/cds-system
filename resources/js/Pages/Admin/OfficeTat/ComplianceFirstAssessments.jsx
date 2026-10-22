import { Icon } from '@iconify/react';
import { useState } from 'react';
import ComplianceTabList from '@/Components/Compliance/ComplianceTabList';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudTable from '@/Components/Crud/CrudTable';
import { officeTatDisplayUnit } from './unitPresentation.mjs';

const unitTabs = [['conservation', 'Conservation'], ['development', 'Development']];

export default function ComplianceFirstAssessments({
    data = null, periodLabel = '', asOfDate = '', selectedUnit = 'conservation',
    authorizedUnits = [], onUnitChange = () => {},
}) {
    const [detailRow, setDetailRow] = useState(null);
    const assessmentUnit = officeTatDisplayUnit(data, selectedUnit);
    const rows = data?.rows || [];
    const moduleCoverage = data?.module_coverage || [];
    const tableRows = rows.map(row => ({ ...row, dueValue: row.due_count == null ? null : Number(row.due_count) }));
    const unavailableReason = scoreUnavailableReason(data);
    const noDueReason = noAssessableDueDetail(asOfDate);
    const scoreReason = data?.due_count != null && Number(data.due_count) === 0 ? noDueReason : unavailableReason;

    const columns = [
        { key: 'rank', label: 'Rank', render: row => row.rank || rankStatus(row) },
        {
            key: 'participant',
            label: assessmentUnit === 'conservation' ? 'Protected Area' : 'CENRO',
            headerClassName: 'min-w-52',
            render: row => <div>
                <p className="font-bold text-gray-950 dark:text-white">{row.name}</p>
                {assessmentUnit === 'conservation' && <p className="mt-1 text-[11px] text-gray-600 dark:text-gray-300">
                    Directory status: {row.legal_status || 'Unknown'}{row.office_binding ? ' · PAMO ' + row.office_binding : ''}
                </p>}
            </div>,
        },
        { key: 'eligible', label: 'Eligible reports', render: row => displayCount(row.activity_count) },
        { key: 'received', label: 'Received by selected date', headerClassName: 'min-w-40', render: row => displayCount(row.received_activity_count) },
        { key: 'due', label: 'Assessed reports (N)', headerClassName: 'min-w-36', render: row => row.dueValue == null ? scoreUnavailableReason(row) : displayCount(row.dueValue) },
        {
            key: 'on_time', label: 'On time / N', headerClassName: 'min-w-36',
            render: row => row.dueValue > 0 ? displayCount(row.on_time_count) + ' / ' + displayCount(row.dueValue) : row.dueValue === 0 ? noAssessableDueDetail(asOfDate) : scoreUnavailableReason(row),
        },
        { key: 'tc', label: 'Timeliness Compliance', headerClassName: 'min-w-40', render: row => formatPercent(row.tc_percent, row.dueValue === 0 ? noAssessableDueDetail(asOfDate) : scoreUnavailableReason(row)) },
        { key: 'se', label: 'Submission Efficiency', headerClassName: 'min-w-40', render: row => formatPercent(row.se_percent, row.dueValue === 0 ? noAssessableDueDetail(asOfDate) : scoreUnavailableReason(row)) },
        { key: 'otps', label: 'Overall Score', render: row => row.otps == null ? row.dueValue === 0 ? 'Not applicable' : scoreUnavailableReason(row) : Number(row.otps).toFixed(2) },
    ];

    return <section className="mt-4 space-y-4" aria-labelledby="report-performance-heading">
        <header>
            <p className="text-[10px] font-extrabold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">{periodLabel} · As of {asOfDate}</p>
            <h2 id="report-performance-heading" className="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">Report Performance</h2>
            <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Shows whether each eligible live report reached PENRO by its source deadline.</p>
        </header>

        <div className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <ComplianceTabList tabs={unitTabs.filter(([key]) => authorizedUnits.includes(key))} value={assessmentUnit} onChange={onUnitChange} label="Report performance unit" />
        </div>

        <div role="status" className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
            <p className="font-extrabold">Based on recorded reports · full-period coverage incomplete</p>
            <p>Only supported live source identities enter the recorded counts and score. Records available does not prove that every required report or event is represented.</p>
            {assessmentUnit === 'conservation' && <p className="mt-1">Protected Area reports stay attributed to their Protected Area, regardless of office custody.</p>}
        </div>

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Summary label="Eligible reports" value={displayCount(data?.activity_count)} detail="Supported live report identities in the selected period." />
            <Summary label="Received by selected date" value={displayCount(data?.received_activity_count)} detail={'Accepted PENRO receipts recorded by ' + asOfDate + '.'} />
            <Summary label="Assessed reports (N)" value={data?.due_count == null ? unavailableReason : displayCount(data.due_count)} detail={Number(data?.due_count || 0) > 0 ? 'Eligible reports received by the selected date and applicable obligations already due; future, unreceived obligations stay outside N.' : data?.due_count == null ? unavailableReason : noDueReason} />
            <Summary label="Overall Score" value={data?.otps == null ? Number(data?.due_count || 0) === 0 ? 'Not applicable' : 'Not scored' : Number(data.otps).toFixed(2)} detail={data?.otps == null ? scoreReason : '80% Timeliness Compliance + 20% Submission Efficiency.'} />
        </div>

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Summary label="Timeliness Compliance" value={formatPercent(data?.tc_percent, scoreReason)} detail={Number(data?.due_count || 0) > 0 ? displayCount(data?.on_time_count) + ' on time / ' + displayCount(data?.due_count) + ' assessed N.' : data?.due_count == null ? unavailableReason : noDueReason} />
            <Summary label="Submission Efficiency" value={formatPercent(data?.se_percent, scoreReason)} detail={Number(data?.due_count || 0) > 0 ? 'Mean efficiency across the same assessed N.' : data?.due_count == null ? unavailableReason : noDueReason} />
            <Summary label="Future deadlines / incomplete" value={displayCount(data?.not_yet_due_count)} detail="Future, unreceived obligations stay outside N; eligible reports received by the selected date are assessed before their deadline." />
            <Summary label="Missing dates / unsupported identities" value={displayCount(data?.incomplete_date_count) + ' / ' + displayCount(data?.incomplete_evidence_count)} detail="Open participant details for the reason and source reference." />
        </div>

        <section className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" aria-labelledby="module-coverage-heading">
            <header className="px-4 py-3">
                <h3 id="module-coverage-heading" className="text-sm font-extrabold text-gray-950 dark:text-white">Module coverage</h3>
                <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">Source records, accepted receipts and archive completions are counted once per source identity. Completed office handoff intervals follow the current office filters.</p>
            </header>
            <div className="overflow-x-auto">
                <table className="min-w-[1120px] w-full border-collapse text-left text-[11px]">
                    <caption className="sr-only">Live report module coverage for the selected period and authorized participant scope</caption>
                    <thead className="bg-gray-50 text-[10px] font-extrabold uppercase tracking-wide text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                        <tr>
                            <th scope="col" className="px-3 py-2">Module / source</th>
                            <th scope="col" className="px-3 py-2">Participant scope</th>
                            <th scope="col" className="px-3 py-2">Live records</th>
                            <th scope="col" className="px-3 py-2">Archived</th>
                            <th scope="col" className="px-3 py-2">Eligible recorded</th>
                            <th scope="col" className="px-3 py-2">Received</th>
                            <th scope="col" className="px-3 py-2">Assessed N</th>
                            <th scope="col" className="px-3 py-2">Completed office handoffs</th>
                            <th scope="col" className="px-3 py-2">Excluded / reason</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 text-gray-800 dark:divide-gray-800 dark:text-gray-100">
                        {moduleCoverage.map(item => {
                            const reasons = Object.entries(item.exclusion_reasons || {});
                            return <tr key={item.source_key + ':' + (item.workflow_key || item.module_code || '')}>
                                <th scope="row" className="px-3 py-2 align-top font-bold">
                                    {item.module || item.source_key}
                                    <span className="mt-0.5 block font-mono text-[10px] font-medium text-gray-500 dark:text-gray-400">{item.source_key}{item.workflow_key ? ':' + item.workflow_key : ''}</span>
                                </th>
                                <td className="px-3 py-2 align-top">{item.participant_scope || 'Authorized scope'}</td>
                                <td className="px-3 py-2 align-top tabular-nums">{displayCount(item.live_record_count)}</td>
                                <td className="px-3 py-2 align-top tabular-nums">{displayCount(item.completed_archived_count)}</td>
                                <td className="px-3 py-2 align-top tabular-nums">{displayCount(item.eligible_recorded_count)}</td>
                                <td className="px-3 py-2 align-top tabular-nums">{displayCount(item.received_count)}</td>
                                <td className="px-3 py-2 align-top tabular-nums">{displayCount(item.due_count)}</td>
                                <td className="px-3 py-2 align-top tabular-nums">{displayCount(item.live_tat_samples)}</td>
                                <td className="max-w-[300px] px-3 py-2 align-top">
                                    {Number(item.excluded_count || 0) === 0 ? '' : <span>
                                        <strong className="tabular-nums">{displayCount(item.excluded_count)} </strong>
                                        {reasons.map(([reason, count]) => <span key={reason} className="block text-gray-600 dark:text-gray-300">{displayCount(count)} � {reason}</span>)}
                                    </span>}
                                </td>
                            </tr>;
                        })}
                        {moduleCoverage.length === 0 && <tr><td colSpan={9} className="px-3 py-4 text-center text-gray-600 dark:text-gray-300">No active report source is available in this participant scope.</td></tr>}
                    </tbody>
                </table>
            </div>
        </section>

        <details className="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <summary className="cursor-pointer px-4 py-3 text-xs font-bold text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:text-gray-100">How scores are calculated</summary>
            <p className="border-t border-gray-100 px-4 py-3 text-xs leading-5 text-gray-700 dark:border-gray-800 dark:text-gray-200">
                Timeliness Compliance is on-time reports divided by assessed N; Submission Efficiency uses source dates under each workflow’s day convention; Overall Score is 80% Timeliness Compliance plus 20% Submission Efficiency.
            </p>
        </details>

        <CrudTable
            compact
            title={(data?.label || 'Unit') + ' report performance'}
            subtitle={periodLabel}
            helperText="Open a participant for source dates, receipt status and excluded report details."
            rows={tableRows}
            rowKey={row => row.participant_key}
            onRowClick={setDetailRow}
            rowFocusVisibleOnly
            caption={'Live report performance for ' + (data?.label || 'unit') + ', ' + periodLabel}
            emptyTitle="No eligible live reports"
            emptyDescription="No supported live source identity is available for this unit and period."
            columns={columns}
        />

        <CrudDetailsModal
            open={Boolean(detailRow)}
            title="Participant performance details"
            subtitle={detailRow ? detailRow.name + ' · ' + periodLabel : ''}
            icon={<Icon icon="lucide:clipboard-check" width="18" height="18" aria-hidden="true" />}
            onClose={() => setDetailRow(null)}
            compact
            maxWidth="max-w-4xl"
            summary={detailRow && <div className="grid gap-2 sm:grid-cols-4">
                <Metric label="Assessed reports (N)" value={displayCount(detailRow.dueValue)} />
                <Metric label="Eligible reports" value={displayCount(detailRow.activity_count)} />
                <Metric label="Received by selected date" value={displayCount(detailRow.received_activity_count)} />
                <Metric label="Result" value={detailRow.score_status || 'Not scored'} />
            </div>}
        >
            {detailRow && <div className="space-y-3">
                <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-700 dark:bg-gray-950 dark:text-gray-200">
                    Live source identities are scored when their required source dates and report identity are supported.
                    {assessmentUnit === 'conservation' ? ' Reports are attributed to the Protected Area.' : ''}
                </p>
                {(detailRow.obligation_details || []).map((item, index) => <article key={detailRow.participant_key + '-' + index} className="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <h3 className="text-xs font-extrabold text-gray-900 dark:text-white">{item.label || 'Source report'}</h3>
                    <p className="mt-1 text-[11px] font-semibold text-emerald-800 dark:text-emerald-200">{detailStatusLabel(item)}</p>
                    <dl className="mt-3 grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                        <DataPoint label="Source start" value={item.start_date || 'Incomplete'} />
                        <DataPoint label="Deadline" value={item.deadline || 'Incomplete'} />
                        <DataPoint label="Selected-date due status" value={dueStatusLabel(item, asOfDate)} />
                        <DataPoint label="Accepted PENRO receipt" value={item.received || ('Not recorded by selected date ' + asOfDate)} />
                        {item.day_convention && <DataPoint label="Day convention" value={item.day_convention} />}
                        <DataPoint label="Time allowed" value={timeAllowed(item)} />
                        <DataPoint label="Time used" value={timeUsed(item, asOfDate)} />
                        <DataPoint label="Source reference" value={detailSourceReference(item) || 'Unavailable'} />
                    </dl>
                    {sourceIdentityEntries(item).length > 0 && <div className="mt-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-950">
                        <p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-600 dark:text-gray-300">Source identity</p>
                        <dl className="mt-2 grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                            {sourceIdentityEntries(item).map(([label, value]) => <DataPoint key={label} label={label} value={value} />)}
                        </dl>
                    </div>}
                    {item.source_rows_matched > 1 && <p className="mt-2 text-[11px] text-gray-600 dark:text-gray-300">{item.source_rows_matched} live source rows match this identity. The earliest accepted receipt is counted once.</p>}
                    {item.issue && <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[11px] leading-4 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"><strong>{isUnsupportedIdentity(item) ? 'Why it is not matched: ' : ''}</strong>{item.issue}</p>}
                </article>)}
                {(detailRow.obligation_details || []).length === 0 && <p className="text-xs text-gray-600 dark:text-gray-300">{scoreUnavailableReason(detailRow)}. No due report detail is available.</p>}
                <p className="text-[11px] leading-4 text-gray-500 dark:text-gray-400">Time used ends at accepted PENRO receipt; it is not a days-late value.</p>
            </div>}
        </CrudDetailsModal>
    </section>;
}

function timeAllowed(item) {
    if (item.status === 'Unsupported report identity') return 'Not calculated · unsupported report identity';
    if (!item.start_date || !item.deadline) return 'Unavailable · missing required source dates';
    if (item.allowed_days == null) return 'Unavailable · source day convention did not produce a duration';
    return item.allowed_days + ' ' + (item.day_convention || 'days') + ' · source start ' + item.start_date + ' → source deadline ' + item.deadline;
}

function timeUsed(item, asOfDate) {
    if (item.status === 'Unsupported report identity') return 'Not calculated · unsupported report identity';
    if (!item.start_date) return 'Unavailable · source start date is missing';
    if (!item.received) {
        const futureDeadline = item.deadline && item.deadline > asOfDate;
        return futureDeadline
            ? 'Not recorded · deadline ' + item.deadline + ' is after selected date ' + asOfDate
            : 'Not recorded · no accepted PENRO receipt by selected date ' + asOfDate;
    }
    if (item.received > asOfDate) return 'Not counted · accepted PENRO receipt ' + item.received + ' is after selected date ' + asOfDate;
    if (item.days_used == null) return 'Unavailable · source day convention did not produce a duration';
    return item.days_used + ' ' + (item.day_convention || 'days') + ' · source start ' + item.start_date + ' → accepted PENRO receipt ' + item.received;
}

function scoreUnavailableReason(row) {
    if (Number(row?.incomplete_date_count || 0) > 0) return 'Missing required source dates';
    if (Number(row?.incomplete_evidence_count || 0) > 0) return 'Unsupported report identity';
    return 'Not assessed';
}

function noAssessableDueDetail(asOfDate) {
    return 'No eligible reports could be assessed for the selected filters and date (as of ' + asOfDate + '). Future, unreceived obligations stay outside N; source gaps are listed separately.';
}

function isUnsupportedIdentity(item) {
    return ['Unsupported source identity', 'Unsupported report identity'].includes(item?.status);
}

function detailStatusLabel(item) {
    if (isUnsupportedIdentity(item)) return 'Not matched to a registered report';
    if (item?.status === 'No accepted receipt endpoint by cutoff') return 'Awaiting PENRO Records receipt';
    if (item?.status === 'Not yet due; excluded from N') return 'Not yet due';
    return item?.status || 'Source detail';
}

function dueStatusLabel(item, asOfDate) {
    const selectedDate = item?.due_as_of_date || asOfDate;
    if (item?.due_status === 'received_before_deadline') return 'Received by selected date and assessed before deadline ' + item.deadline;
    if (item?.due_status === 'not_yet_due' || (!item?.due_status && item?.deadline && item.deadline > selectedDate)) {
        return 'Not yet due at selected date ' + selectedDate;
    }
    if (item?.due_status === 'due' || (!item?.due_status && item?.deadline && item.deadline <= selectedDate)) {
        return 'Due by selected date ' + selectedDate;
    }
    return 'Unavailable';
}

function detailSourceReference(item) {
    if (item?.source_reference) return item.source_reference;
    const identity = item?.source_identity || {};
    return identity.source_key && identity.source_id
        ? String(identity.source_key).toUpperCase() + ' report #' + identity.source_id
        : null;
}

function displaySourceType(sourceKey) {
    return String(sourceKey || '').toLowerCase() === 'engp' ? 'ENGP' : sourceKey;
}

function sourceIdentityEntries(item) {
    const identity = item?.source_identity || {};
    const recordedActivity = identity.submitted_activity_name || identity.activity_name;
    const registeredActivity = identity.registered_activity_name
        ?? (isUnsupportedIdentity(item) ? 'No exact registered activity match' : (identity.activity_name !== recordedActivity ? identity.activity_name : null));
    const entries = [
        ['Source type', displaySourceType(identity.source_key)],
        ['Workflow', identity.workflow_label || identity.workflow_key],
        ['Reporting period', identity.reporting_period || identity.period_key],
        ['Reporting year', identity.reporting_year],
        ['Recorded activity', recordedActivity],
        ['Registered activity', registeredActivity],
        ['Document', identity.document_type],
        ['Module', identity.module_code],
    ];
    return entries.filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '');
}

function Summary({ label, value, detail }) {
    return <article className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-600 dark:text-gray-300">{detail}</p>
    </article>;
}

function Metric({ label, value }) {
    return <article className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-950">
        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 text-sm font-extrabold text-gray-900 dark:text-white">{value}</p>
    </article>;
}

function DataPoint({ label, value }) {
    return <div>
        <dt className="font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt>
        <dd className="mt-0.5 font-semibold text-gray-900 dark:text-gray-100">{value}</dd>
    </div>;
}

function displayCount(value) {
    return value == null ? '0' : Number(value).toLocaleString();
}

function formatPercent(value, reason) {
    return value == null ? reason : Number(value).toFixed(2) + '%';
}

function rankStatus(row) {
    if (row.rank_status === 'Awaiting technical classification') return 'Tie needs classification';
    if (row.rank_status === 'Awaiting tie resolution') return 'Tie needs review';
    if (row.rank_status === 'Comparison limited by organizational access') return 'No unit rank';
    if (row.score_status === 'No due reports' || row.score_status === 'No assessable due reports') return scoreUnavailableReason(row);
    if (row.score_status === 'Coverage incomplete') return scoreUnavailableReason(row);
    return 'Not ranked';
}
