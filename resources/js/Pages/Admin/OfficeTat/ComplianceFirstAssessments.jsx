import { useId, useState } from 'react';
import { Icon } from '@iconify/react';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudTable from '@/Components/Crud/CrudTable';
import { FloatingSelect } from '@/Components/Form';
import { formatWorkflowLabel, reportingPeriodLabel } from './reportPresentation.mjs';

const unitLabels = { conservation: 'Conservation', development: 'Development' };

export default function ComplianceFirstAssessments({ data = null, selectedUnit = 'conservation', periodLabel = '' }) {
    const assessment = data?.office_tat_assessment || null;
    const rows = assessment?.rows || [];
    const unit = data?.unit || selectedUnit;
    const [detailRow, setDetailRow] = useState(null);
    const date = data?.as_of_date || 'Not available';
    const period = periodLabel || data?.period_key || 'Selected period';
    const columns = [
        { key: 'participant', label: 'Office / PA', headerClassName: 'min-w-52', render: row => <span className="font-bold">{row.name}</span> },
        { key: 'processed', label: 'Reports processed', render: row => count(row.processed_count ?? row.activity_count) },
        { key: 'received', label: 'Received by PENRO', render: row => count(row.received_by_penro_count ?? row.received_activity_count) },
        { key: 'on_time', label: 'On time', render: row => onTimeReports(row) },
        { key: 'tc', label: <abbr title="On-time completion">TC</abbr>, render: row => scorePercent(row.tc_percent, row) },
        { key: 'se', label: <abbr title="Submission efficiency">SE</abbr>, render: row => scorePercent(row.se_percent, row) },
        { key: 'otps', label: 'Overall score', render: row => overallScore(row) },
        { key: 'rank', label: 'Rank', render: row => rankLabel(row) },
    ];

    return <section className="mt-5 space-y-3" aria-labelledby="office-tat-ranking-heading">
        <header>
            <p className="text-[10px] font-extrabold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">{period} | As of {date}</p>
            <h2 id="office-tat-ranking-heading" className="mt-1 text-lg font-extrabold text-gray-950 dark:text-white">Submission performance</h2>
            <p className="mt-1 max-w-4xl text-xs leading-4 text-gray-600 dark:text-gray-300">Deadline performance for actual processed reports. Future unreceived reports are still pending.</p>
            <p className="mt-1 text-[10px] text-gray-600 dark:text-gray-300">{unit === 'conservation' ? 'Grouped by originating PA / PAMO' : 'Grouped by originating CENRO'}</p>
        </header>

        <section className="grid gap-2 sm:grid-cols-2 xl:grid-cols-6" aria-label="Submission performance summary">
            <Summary label="Reports assessed" value={groupCount(assessment?.assessed_count ?? assessment?.due_count)} detail="Received by cutoff or already due" />
            <Summary label="Received by PENRO" value={groupCount(assessment?.received_by_penro_count ?? assessment?.received_activity_count)} detail="Accepted PENRO receipts by the selected date" />
            <Summary label="On time" value={groupCount(assessment?.on_time_count)} detail="Accepted by the due date" />
            <Summary label="TC" value={scorePercent(assessment?.tc_percent, assessment)} detail="On time ÷ assessed reports" />
            <Summary label="SE" value={scorePercent(assessment?.se_percent, assessment)} detail="Average report efficiency" />
            <Summary label="Overall score" value={overallScore(assessment)} detail="80% TC + 20% SE" />
        </section>

        <CrudTable
            rows={rows}
            columns={columns}
            rowKey={row => row.participant_key}
            onRowClick={row => setDetailRow(row)}
            rowAriaLabel={row => 'Open report details for ' + row.name}
            focusRowOnClick
            rowFocusVisibleOnly
            compact
            caption={unitLabels[unit] + ' submission performance for ' + period}
            emptyTitle="No participants are available for this group."
            compactEmpty
            className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
        />

        <CrudDetailsModal
            open={Boolean(detailRow)}
            title="Submission performance details"
            subtitle={detailRow ? (unitLabels[unit] || 'Office') + ' | ' + detailRow.name + ' | ' + period : ''}
            icon={<Icon icon="lucide:clipboard-check" width="18" height="18" aria-hidden="true" />}
            onClose={() => setDetailRow(null)}
            summary={detailRow && <div className="grid gap-2 sm:grid-cols-4">
                <Summary label="Reports assessed" value={groupCount(detailRow.assessed_count ?? detailRow.due_count)} detail="Received by cutoff or already due" />
                <Summary label="Received by PENRO" value={count(detailRow.received_by_penro_count ?? detailRow.received_activity_count)} detail="Accepted receipts by the selected date" />
                <Summary label="On time" value={onTimeReports(detailRow)} detail="Accepted by the due date" />
                <Summary label="Overall score" value={overallScore(detailRow)} detail="Shown when the count and dates are known" />
            </div>}
            maxWidth="max-w-5xl"
            compact
            avoidBottomDebugbar
        >
            {detailRow && <div className="space-y-4">
                {rows.length > 1 && <FloatingSelect label="Office / PA" size="sm" value={String(detailRow.participant_key)} onChange={event => {
                    const nextRow = rows.find(row => String(row.participant_key) === event.target.value);
                    if (nextRow) setDetailRow(nextRow);
                }}>
                    {rows.map(row => <option key={row.participant_key} value={String(row.participant_key)}>{row.name}</option>)}
                </FloatingSelect>}
                <ReportDetailAccordionList key={String(detailRow.participant_key)} participantKey={detailRow.participant_key} items={mergeReportDetails(detailRow)} />
                {mergeReportDetails(detailRow).length === 0 && <p className="text-xs text-gray-500">{Number(detailRow.processed_count ?? detailRow.activity_count ?? 0) === 0 ? 'No processed reports are recorded for this participant.' : 'No additional report details are available for this participant.'}</p>}
            </div>}
        </CrudDetailsModal>
    </section>;
}

function mergeReportDetails(row) {
    const byIdentity = new Map();
    for (const item of [...(row?.obligation_details || []), ...(row?.recorded_obligation_details || [])]) {
        const key = reportDetailKey(item, row?.participant_key);
        const current = byIdentity.get(key);
        if (!current || (!current.source_reference && item.source_reference)) byIdentity.set(key, item);
    }
    return [...byIdentity.values()];
}

function reportDetailKey(item, participantKey) {
    const identity = item?.source_identity || {};
    const ids = [...new Set([...(identity.source_ids || []), ...(identity.source_id != null ? [identity.source_id] : [])].map(String))].sort();
    if (ids.length) return (identity.source_key || '') + ':' + ids.join(',');
    if (item?.source_reference) return String(item.source_reference);
    if (item?.key != null && String(item.key) !== '') return String(item.key);
    return JSON.stringify([participantKey, identity.workflow_key || '', identity.period_key || identity.reporting_period || '', identity.reporting_year || '', identity.submitted_activity_name || identity.activity_name || '', identity.document_type || '', item?.start_date || '', item?.deadline || '', item?.label || '']);
}

function ReportDetailAccordionList({ participantKey, items }) {
    const [expandedKey, setExpandedKey] = useState(null);
    return <div className="space-y-2">{items.map(item => {
        const key = reportDetailKey(item, participantKey);
        return <ReportDetail key={key} item={item} expanded={expandedKey === key} onToggle={() => setExpandedKey(current => current === key ? null : key)} />;
    })}</div>;
}

export function ReportDetail({ item, expanded, onToggle }) {
    const panelId = 'office-tat-report-panel-' + useId();
    const identity = item.source_identity || {};
    const sourceStatus = item.status || 'Report detail';
    const noReceiptRecorded = sourceStatus === 'No accepted PENRO receipt by cutoff' || sourceStatus.startsWith('Non-receipt evidence');
    const statusText = noReceiptRecorded ? 'Receipt not recorded' : sourceStatus;
    const label = identity.workflow_label || formatWorkflowLabel(identity.workflow_key) || item.label || 'Report';
    const period = reportingPeriodLabel(identity);
    const document = identity.document_type || 'Document not available';
    const activity = identity.submitted_activity_name || identity.activity_name;
    const reportReference = item.source_reference || (identity.source_id != null ? 'Report #' + identity.source_id : '');

    return <article data-obligation-key={item.key == null ? '' : String(item.key)} className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <h4 className="m-0"><button type="button" aria-expanded={expanded} aria-controls={panelId} data-cds-modal-disclosure onClick={onToggle} className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:hover:bg-gray-800">
            <span className="min-w-0 flex-1">
                <span className="block text-xs font-extrabold text-gray-900 dark:text-white">{label}</span>
                <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] font-semibold text-gray-600 dark:text-gray-300">
                    <span>{period}</span><span aria-hidden="true">|</span><span>{document}</span>
                    {reportReference && <><span aria-hidden="true">|</span><span>{reportReference}</span></>}
                    <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200">{statusText}</span>
                </span>
            </span>
            <span className="shrink-0 text-[10px] font-bold text-emerald-800 dark:text-emerald-200">{expanded ? 'Hide details' : 'Show details'}</span>
        </button></h4>
        <div id={panelId} hidden={!expanded} className="border-t border-gray-200 px-3 py-3 dark:border-gray-700">
            <dl className="mt-3 grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                <DataPoint label="Report start" value={item.start_date || 'Not available'} />
                <DataPoint label="Due date" value={item.deadline || 'Not available'} />
                <DataPoint label="Received by PENRO Records" value={item.received || 'No accepted receipt recorded by cutoff'} />
                <DataPoint label="Allowed time (T)" value={item.allowed_days == null ? 'Not available' : String(item.allowed_days) + ' days | ' + (item.day_convention || 'source calendar')} />
                <DataPoint label="Time used (D)" value={item.days_used == null ? 'Not available' : String(item.days_used) + ' days'} />
                <DataPoint label="Efficiency for this report" value={item.individual_efficiency_percent == null ? 'Not calculated' : Number(item.individual_efficiency_percent).toFixed(2) + '%'} />
                <DataPoint label="Reporting period" value={period} />
                <DataPoint label="Document type" value={document} />
                {activity && activity !== label && <DataPoint label="Activity" value={activity} />}
            </dl>
            {item.issue && <p role="status" className="mt-3 rounded-md bg-gray-50 px-3 py-2 text-[11px] leading-4 text-gray-700 dark:bg-gray-950 dark:text-gray-200"><strong>Report note:</strong> {item.issue}</p>}
        </div>
    </article>;
}

function Summary({ label, value, detail }) {
    return <article className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 text-lg font-extrabold text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-600 dark:text-gray-300">{detail}</p>
    </article>;
}

function DataPoint({ label, value }) {
    return <div><dt className="font-bold text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-0.5 break-words text-gray-900 dark:text-gray-100">{value}</dd></div>;
}

function count(value) {
    return value == null || !Number.isFinite(Number(value)) ? 'Not available yet' : Number(value).toLocaleString();
}

function hasNumber(value) {
    return value !== null && value !== undefined && Number.isFinite(Number(value));
}

function onTimeReports(row) {
    const denominator = row?.assessed_count ?? row?.due_count;
    if (!hasNumber(denominator)) return 'Not available yet';
    if (Number(denominator) === 0) return 'No assessed reports';
    return count(row?.on_time_count) + ' / ' + count(denominator);
}

function scorePercent(value, row) {
    if (hasNumber(value)) return Number(value).toFixed(2) + '%';
    if (!row) return 'Not available';
    return Number(row?.assessed_count ?? row?.due_count ?? 0) === 0 ? 'No assessed reports' : 'Score unavailable';
}

function overallScore(row) {
    if (hasNumber(row?.otps)) return Number(row.otps).toFixed(2) + '%';
    if (!row) return 'Not available';
    return Number(row?.assessed_count ?? row?.due_count ?? 0) === 0 ? 'No assessed reports' : 'Score unavailable';
}

function rankLabel(row) {
    if (row.rank == null) {
        if (row.rank_status === 'Awaiting tie resolution') return 'Tie resolution pending';
        if (row.rank_status === 'Awaiting technical classification') return 'Classification pending';
        if (row.rank_status === 'Rank available with all modules selected') return 'Select all modules';
        if (row.rank_status === 'Comparison limited by organizational access') return 'Not available';
        return 'Not ranked yet';
    }
    const rank = Number(row.rank);
    const suffix = rank % 100 >= 11 && rank % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[rank % 10] || 'th');
    return String(rank) + suffix;
}

function groupCount(value) {
    return hasNumber(value) ? count(value) : 'Not available yet';
}
