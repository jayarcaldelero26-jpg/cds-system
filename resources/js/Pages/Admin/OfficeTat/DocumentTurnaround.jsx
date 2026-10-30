import { useId, useState } from 'react';
import { Icon } from '@iconify/react';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudTable from '@/Components/Crud/CrudTable';
import { FloatingSelect } from '@/Components/Form';
import { formatWorkflowLabel, reportingPeriodLabel } from './reportPresentation.mjs';
import { documentAverageLabel, formatWorkingDays, formatManilaDate, formatManilaTimestamp } from './documentTurnaroundPresentation.mjs';

const unitLabels = { conservation: 'Conservation', development: 'Development' };

export default function DocumentTurnaround({ data = null, selectedUnit = 'conservation', periodLabel = '' }) {
    const unit = data?.unit || selectedUnit;
    const rows = data?.rows || [];
    const [detailRow, setDetailRow] = useState(null);
    const period = periodLabel || data?.period_key || 'Selected period';
    const columns = [
        {
            key: 'participant', label: 'PA / Office', headerClassName: 'min-w-52',
            render: row => <span className="block min-w-0">
                <span className="block font-bold text-gray-900 dark:text-white">{row.name}</span>
                {unit === 'conservation' && row.office_binding && <span className="mt-0.5 block text-[10px] font-medium text-gray-500 dark:text-gray-400">{row.office_binding}</span>}
            </span>,
        },
        { key: 'reports', label: 'Reports', render: row => count(row.reports) },
        { key: 'received', label: 'Received', render: row => count(row.received_count) },
        {
            key: 'in_progress', label: 'In progress', render: row => <span className="inline-flex flex-wrap items-center gap-1.5">
                <span>{count(row.in_progress_count)}</span>
                {row.overdue_count > 0 && <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">{count(row.overdue_count)} overdue</span>}
            </span>,
        },
        {
            key: 'average', label: 'Average turnaround', headerClassName: 'min-w-40',
            render: row => <span className="block">
                <span className="font-bold">{documentAverageLabel(row)}</span>
                {row.average_supporting_documents > 0 && <span className="mt-0.5 block text-[10px] font-medium text-gray-500 dark:text-gray-400">{count(row.average_supporting_documents)} received with timestamps</span>}
            </span>,
        },
    ];

    return <section className="mt-4 space-y-3" aria-labelledby="document-turnaround-heading">
        <header className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p className="text-[10px] font-extrabold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">{period} | As of {data?.as_of_date || 'Not available'}</p>
                <h2 id="document-turnaround-heading" className="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">Document turnaround</h2>
                <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-600 dark:text-gray-300">Working days from first submission until PENRO Records receives the report.</p>
                <p className="mt-1 text-[11px] font-medium text-gray-500 dark:text-gray-400">Mon–Thu, excluding calendar holidays.</p>
            </div>
            {Number(data?.overdue_count || 0) > 0 && <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-bold text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                <Icon icon="lucide:clock-3" width="13" height="13" aria-hidden="true" />
                {count(data.overdue_count)} overdue
            </span>}
        </header>

        <section className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4" aria-label="Document turnaround summary">
            <Summary label="Reports" value={emptyCount(data)} detail={unit === 'conservation' ? 'Processed PA reports' : 'Processed ENGP reports'} />
            <Summary label="Received at PENRO" value={emptyMetric(data, data?.received_count)} detail="Accepted receipt by the selected date" />
            <Summary label="In progress" value={emptyMetric(data, data?.in_progress_count)} detail={Number(data?.overdue_count || 0) > 0 ? `${count(data.overdue_count)} overdue by the selected date` : 'No accepted receipt by the selected date'} />
            <Summary
                label="Average turnaround"
                value={documentAverageLabel(data)}
                detail={Number(data?.average_supporting_documents || 0) > 0
                    ? `Based on ${count(data.average_supporting_documents)} received reports with timestamps`
                    : (Number(data?.received_count || 0) > 0 ? `${count(data?.timing_unavailable_count || 0)} received reports have unavailable timing` : 'Completed reports only')}
            />
        </section>

        <CrudTable
            rows={rows}
            columns={columns}
            rowKey={row => row.participant_key}
            onRowClick={row => setDetailRow(row)}
            rowAriaLabel={row => 'Open document turnaround details for ' + row.name}
            focusRowOnClick
            rowFocusVisibleOnly
            compact
            caption={unitLabels[unit] + ' document turnaround for ' + period}
            emptyTitle="No processed reports"
            emptyDescription="Submitted or forwarded reports with route evidence will appear here."
            compactEmpty
            className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
            tableClassName="min-w-[720px]"
        />

        <CrudDetailsModal
            open={Boolean(detailRow)}
            title="Document turnaround details"
            subtitle={detailRow ? [detailRow.name, unit === 'conservation' ? detailRow.office_binding : null, period].filter(Boolean).join(' | ') : ''}
            icon={<Icon icon="lucide:timer" width="18" height="18" aria-hidden="true" />}
            onClose={() => setDetailRow(null)}
            summary={detailRow && <div className="grid gap-2 sm:grid-cols-4">
                <Summary label="Reports" value={count(detailRow.reports)} detail="Processed reports in this view" />
                <Summary label="Received at PENRO" value={count(detailRow.received_count)} detail="Accepted by the selected date" />
                <Summary label="In progress" value={count(detailRow.in_progress_count)} detail={detailRow.overdue_count > 0 ? `${count(detailRow.overdue_count)} overdue` : 'Awaiting first receipt'} />
                <Summary label="Average turnaround" value={documentAverageLabel(detailRow)} detail={detailRow.average_supporting_documents > 0 ? `Based on ${count(detailRow.average_supporting_documents)} reports` : 'Completed reports with valid timestamps'} />
            </div>}
            maxWidth="max-w-5xl"
            compact
            avoidBottomDebugbar
        >
            {detailRow && <ParticipantReports key={String(detailRow.participant_key)} row={detailRow} rows={rows} unit={unit} setDetailRow={setDetailRow} />}
        </CrudDetailsModal>
    </section>;
}

function ParticipantReports({ row, rows, unit, setDetailRow }) {
    const [expandedKey, setExpandedKey] = useState(null);
    return <div className="space-y-3">
        {rows.length > 1 && <FloatingSelect label={unit === 'conservation' ? 'PA / Office' : 'CENRO'} size="sm" value={String(row.participant_key)} onChange={event => {
            const next = rows.find(item => String(item.participant_key) === event.target.value);
            if (next) setDetailRow(next);
        }}>
            {rows.map(item => <option key={item.participant_key} value={String(item.participant_key)}>{item.name}</option>)}
        </FloatingSelect>}
        {row.documents.map(report => {
            const key = report.key;
            return <ReportCard key={key} report={report} expanded={expandedKey === key} onToggle={() => setExpandedKey(current => current === key ? null : key)} />;
        })}
        {row.documents.length === 0 && <p className="text-xs text-gray-500 dark:text-gray-400">No processed reports.</p>}
    </div>;
}

function ReportCard({ report, expanded, onToggle }) {
    const panelId = 'document-turnaround-panel-' + useId();
    const period = reportingPeriodLabel({
        reporting_year: report.reporting_year,
        period_key: report.period_label,
        period_label: report.period_label,
    });
    const workflowLabel = report.workflow_label || formatWorkflowLabel(report.name) || report.name;
    const statusStyle = report.status === 'Overdue'
        ? 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200'
        : 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200';

    return <article className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <h3 className="m-0"><button type="button" aria-expanded={expanded} aria-controls={panelId} data-cds-modal-disclosure onClick={onToggle} className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:hover:bg-gray-800">
            <span className="min-w-0 flex-1">
                <span className="block text-xs font-extrabold text-gray-900 dark:text-white">{workflowLabel}</span>
                <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] font-semibold text-gray-600 dark:text-gray-300">
                    {period && <span>{period}</span>}
                    {report.document_type && <><span aria-hidden="true">|</span><span>{report.document_type}</span></>}
                    <span aria-hidden="true">|</span><span>{report.reference}</span>
                    <span className={`rounded-full px-2 py-0.5 ${statusStyle}`}>{report.status}</span>
                </span>
            </span>
            <span className="shrink-0 text-[10px] font-bold text-emerald-800 dark:text-emerald-200">{expanded ? 'Hide details' : 'Show details'}</span>
        </button></h3>
        <div id={panelId} hidden={!expanded} className="border-t border-gray-200 px-3 py-3 dark:border-gray-700">
            <dl className="grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-3">
                <DataPoint label="First submission" value={report.submission_at ? formatManilaTimestamp(report.submission_at) : 'Timing unavailable'} />
                <DataPoint label="PENRO Records receipt" value={formatReceivedAt(report.received_at)} />
                <DataPoint label="Deadline" value={formatManilaDate(report.deadline)} />
                <DataPoint label="Turnaround" value={turnaroundDetail(report)} />
                {report.activity_name && report.activity_name !== workflowLabel && <DataPoint label="Report name" value={report.activity_name} />}
            </dl>
            <section className="mt-4" aria-label="Route timeline">
                <h4 className="text-[11px] font-extrabold text-gray-800 dark:text-gray-100">Route timeline</h4>
                {report.route_timeline?.length > 0
                    ? <ol className="mt-2 space-y-2 border-l border-gray-200 pl-3 dark:border-gray-700">{report.route_timeline.map((event, index) => <li key={`${event.label}-${event.occurred_at || 'unknown'}-${index}`} className="relative">
                        <span className="absolute -left-[17px] top-1.5 h-1.5 w-1.5 rounded-full bg-emerald-600" aria-hidden="true" />
                        <p className="text-[11px] font-semibold text-gray-800 dark:text-gray-100">{event.label}</p>
                        <p className="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">{event.occurred_at ? formatManilaTimestamp(event.occurred_at) : 'Timestamp unavailable'}</p>
                    </li>)}</ol>
                    : <p className="mt-1 text-[10px] text-gray-500 dark:text-gray-400">No route timeline is available in the existing record.</p>}
            </section>
        </div>
    </article>;
}

function Summary({ label, value, detail }) {
    return <article className="rounded-xl border border-gray-200 bg-white px-3 py-2.5 dark:border-gray-800 dark:bg-gray-900">
        <p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 text-base font-extrabold leading-5 text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-600 dark:text-gray-300">{detail}</p>
    </article>;
}

function DataPoint({ label, value }) {
    return <div><dt className="font-bold text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-0.5 break-words text-gray-900 dark:text-gray-100">{value}</dd></div>;
}

function formatReceivedAt(value) {
    if (!value) return 'No accepted receipt by the selected date';
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatManilaDate(value) : formatManilaTimestamp(value);
}

function turnaroundDetail(report) {
    if (report.timing_status === 'completed') return formatWorkingDays(report.elapsed_working_days);
    if (report.timing_status === 'in_progress') return `${formatWorkingDays(report.elapsed_working_days)} elapsed through ${formatManilaTimestamp(report.elapsed_as_of)}`;
    return 'Timing unavailable';
}

function count(value) {
    return Number.isFinite(Number(value)) ? Number(value).toLocaleString() : '0';
}

function emptyCount(data) {
    return Number(data?.reports || 0) === 0 ? 'No processed reports' : count(data.reports);
}

function emptyMetric(data, value) {
    return Number(data?.reports || 0) === 0 ? 'No processed reports' : count(value);
}
