import { useId, useState } from 'react';
import { Icon } from '@iconify/react';
import Button from '@/Components/Button';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import { FloatingSelect } from '@/Components/Form';
const unitLabels = { conservation: 'Conservation', development: 'Development' };

export default function ComplianceFirstAssessments({ data = null, selectedUnit = 'conservation' }) {
    const rows = data?.rows || [];
    const coverageReasons = data?.coverage_reasons || [];
    const final = Boolean(data?.ranking_is_final);
    const unit = data?.unit || selectedUnit;
    const [detailRow, setDetailRow] = useState(null);
    const date = data?.as_of_date || 'the selected date';

    return <section className="mt-4 space-y-4" aria-labelledby="office-tat-ranking-heading">
        <header>
            <p className="text-[10px] font-extrabold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">{data?.period_key || 'Selected period'} · As of {date}</p>
            <h2 id="office-tat-ranking-heading" className="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">{unitLabels[unit] || 'Office'} recognition ranking</h2>
            <p className="mt-1 max-w-4xl text-sm leading-5 text-gray-600 dark:text-gray-300">
                {unit === 'conservation'
                    ? 'Six Protected Areas are compared in the Conservation group. Each PA report stays with its originating PA, including reports handled by a CENRO.'
                    : 'Four CENROs are compared in the Development group using ENGP reports. Conservation reports handled by a CENRO do not enter this score.'}
            </p>
        </header>

        <div role="status" className={`rounded-xl border px-4 py-3 text-xs leading-5 ${final ? 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100' : 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100'}`}>
            <p className="font-extrabold">{final ? 'Final full-period recognition ranking' : 'Current as-of ranking · provisional'}</p>
            <p>{final
                ? 'The full reporting period, participant roster and comparable source evidence are covered.'
                : 'A final ranking requires the reporting period to end, the complete group roster and comparable source evidence.'}</p>
            {data?.ranking_scope === 'authorized_participants_only' && <p className="mt-1 font-semibold">This account sees a scoped participant subset; its rank is not the full group ranking.</p>}
            {coverageReasons.length > 0 && <ul className="mt-1 list-disc pl-5">{coverageReasons.map(reason => <li key={reason}>{reason}</li>)}</ul>}
        </div>

        <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
            <p><strong>Assessed N</strong> includes applicable obligations already due by the selected cutoff plus valid reports with an accepted PENRO Records receipt by that date, including early receipts. Future, unreceived obligations stay outside N. Due but unreceived reports contribute zero to TC and SE.</p>
            <p className="mt-1"><strong>TC</strong> = on-time reports ÷ N × 100. <strong>SE</strong> = average of max(0, (T − D) ÷ T) × 100 across the same N. <strong>OTPS</strong> = 80% TC + 20% SE.</p>
            <p className="mt-1">The displayed ranking orders TC descending, then SE descending. Identical TC and SE share a competition rank, such as 1, 1, 3. OTPS is displayed as the 80/20 composite.</p>
        </div>

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Summary label="Eligible reports" value={count(data?.activity_count)} detail="Unique recognized report identities in this view" />
            <Summary label="Received by cutoff" value={count(data?.received_activity_count)} detail="Accepted PENRO Records receipts counted once" />
            <Summary label="Assessed reports (N)" value={count(data?.due_count)} detail="Due obligations plus valid receipts received by cutoff" />
            <Summary label="Coverage gaps" value={count((data?.incomplete_date_count || 0) + (data?.incomplete_evidence_count || 0))} detail="Missing dates, applicability or source identity evidence" />
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
            <Summary label="On time / N · TC" value={data?.due_count > 0 ? `${count(data?.on_time_count)} / ${count(data?.due_count)} · ${percent(data?.tc_percent)}` : 'Not applicable'} detail="Accepted PENRO receipt on or before the authoritative deadline" />
            <Summary label="SE" value={percent(data?.se_percent)} detail="Mean per-report efficiency across the same assessed N" />
            <Summary label="OTPS" value={data?.otps == null ? 'Not applicable' : Number(data.otps).toFixed(2)} detail="80% TC + 20% SE" />
        </div>

        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <table className="min-w-full divide-y divide-gray-200 text-left text-xs dark:divide-gray-800">
                <caption className="sr-only">{unitLabels[unit]} recognition ranking for {data?.period_key || 'the selected period'}</caption>
                <thead className="bg-gray-50 text-[10px] font-extrabold uppercase tracking-wide text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                    <tr>
                        <th scope="col" className="px-3 py-3">Rank</th>
                        <th scope="col" className="min-w-52 px-3 py-3">Participant</th>
                        <th scope="col" className="px-3 py-3">Eligible / received</th>
                        <th scope="col" className="px-3 py-3">Assessed N</th>
                        <th scope="col" className="px-3 py-3">On time</th>
                        <th scope="col" className="px-3 py-3">TC</th>
                        <th scope="col" className="px-3 py-3">SE</th>
                        <th scope="col" className="px-3 py-3">OTPS</th>
                        <th scope="col" className="px-3 py-3">Details</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {rows.map(row => <tr key={row.participant_key} className="align-top text-gray-800 dark:text-gray-100">
                        <td className="whitespace-nowrap px-3 py-3 font-extrabold">{rankLabel(row)}</td>
                        <th scope="row" className="px-3 py-3 font-bold">
                            {row.name}
                            <span className="mt-1 block text-[10px] font-medium text-gray-500 dark:text-gray-400">{row.rank_status || row.score_status}</span>
                        </th>
                        <td className="whitespace-nowrap px-3 py-3">{count(row.activity_count)} / {count(row.received_activity_count)}</td>
                        <td className="px-3 py-3">{count(row.due_count)}</td>
                        <td className="whitespace-nowrap px-3 py-3">{row.due_count > 0 ? `${count(row.on_time_count)} / ${count(row.due_count)}` : '—'}</td>
                        <td className="px-3 py-3">{percent(row.tc_percent)}</td>
                        <td className="px-3 py-3">{percent(row.se_percent)}</td>
                        <td className="px-3 py-3 font-bold">{row.otps == null ? '—' : Number(row.otps).toFixed(2)}</td>
                        <td className="px-3 py-3"><Button type="button" size="compact" variant="primary" onClick={() => setDetailRow(row)} aria-label={'View evidence details for ' + row.name} className="rounded-lg px-3 py-1.5 text-[11px]">View</Button></td>
                    </tr>)}
                    {rows.length === 0 && <tr><td colSpan="9" className="px-4 py-8 text-center text-gray-500">No authorized participants are available for this group.</td></tr>}
                </tbody>
            </table>
        </div>

        <CrudDetailsModal
            open={Boolean(detailRow)}
            title="Participant performance details"
            subtitle={detailRow ? (unitLabels[unit] || 'Office') + ' · ' + detailRow.name + ' · ' + (data?.period_key || 'Selected period') : ''}
            icon={<Icon icon="lucide:clipboard-check" width="18" height="18" aria-hidden="true" />}
            onClose={() => setDetailRow(null)}
            summary={detailRow && <div className="grid gap-2 sm:grid-cols-4">
                <Summary label="Eligible reports" value={count(detailRow.activity_count)} detail="Unique recognized report identities" />
                <Summary label="Received by cutoff" value={count(detailRow.received_activity_count)} detail="Accepted PENRO Records receipts counted once" />
                <Summary label="Assessed N" value={count(detailRow.due_count)} detail="Due obligations plus valid receipts by cutoff" />
                <Summary label="Evidence gaps" value={count((detailRow.incomplete_date_count || 0) + (detailRow.incomplete_evidence_count || 0))} detail="Date, applicability or identity gaps" />
            </div>}
            maxWidth="max-w-5xl"
            compact
        >
            {detailRow && <div className="space-y-4">
                {rows.length > 1 && <FloatingSelect
                    label="Participant"
                    size="sm"
                    value={String(detailRow.participant_key)}
                    onChange={event => {
                        const nextRow = rows.find(row => String(row.participant_key) === event.target.value);
                        if (nextRow) setDetailRow(nextRow);
                    }}
                >
                    {rows.map(row => <option key={row.participant_key} value={String(row.participant_key)}>{row.name}</option>)}
                </FloatingSelect>}
                <p className="text-xs text-gray-600 dark:text-gray-300">{count(detailRow.activity_count)} eligible source reports · {count(detailRow.received_activity_count)} accepted receipts by cutoff · {count(detailRow.incomplete_date_count)} date gaps · {count(detailRow.incomplete_evidence_count)} identity/applicability gaps.</p>
                {detailRow.score_issues?.length > 0 && <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"><p className="font-extrabold">Evidence or coverage issues</p><ul className="mt-1 list-inside list-disc">{detailRow.score_issues.map(issue => <li key={issue}>{issue}</li>)}</ul></div>}
                <ReportDetailAccordionList key={String(detailRow.participant_key)} participantKey={detailRow.participant_key} items={detailRow.obligation_details || []} />
                {(detailRow.obligation_details || []).length === 0 && <p className="text-xs text-gray-500">No report or obligation details are available for this selection.</p>}
            </div>}
        </CrudDetailsModal>
    </section>;
}

function reportDetailKey(item, participantKey) {
    if (item?.key !== undefined && item?.key !== null && String(item.key) !== '') return String(item.key);
    const identity = item?.source_identity || {};
    return JSON.stringify([
        participantKey,
        item?.source_reference || '',
        identity.workflow_key || '',
        identity.period_key || identity.reporting_period || '',
        identity.reporting_year || '',
        identity.activity_name || '',
        identity.document_type || '',
        item?.start_date || '',
        item?.deadline || '',
        item?.label || '',
    ]);
}

function ReportDetailAccordionList({ participantKey, items }) {
    const [expandedKey, setExpandedKey] = useState(null);

    return <div className="space-y-2">
        {items.map(item => {
            const key = reportDetailKey(item, participantKey);
            return <ReportDetail
                key={key}
                item={item}
                expanded={expandedKey === key}
                onToggle={() => setExpandedKey(current => current === key ? null : key)}
            />;
        })}
    </div>;
}

function ReportDetail({ item, expanded, onToggle }) {
    const panelId = 'office-tat-report-panel-' + useId();
    const identity = item.source_identity || {};
    const sourceIdentity = [
        item.source_reference,
        identity.source_key && identity.source_id ? identity.source_key + ' #' + identity.source_id : null,
    ].filter((value, index, values) => value && values.indexOf(value) === index).join(' | ') || 'Registry occurrence; no submitted source record';
    const statusText = item.status || 'Source detail';
    const label = item.label || identity.workflow_label || 'Report occurrence';
    const period = [identity.reporting_year, identity.period_key || identity.reporting_period].filter(Boolean).join(' ') || 'Period not recorded';
    const document = identity.document_type || 'Document not recorded';
    const reason = item.issue || (statusText === 'Not yet due; excluded from N'
        ? 'Future obligation; it remains outside the current as-of denominator.'
        : statusText === 'No accepted PENRO receipt by cutoff'
            ? 'Due by cutoff without an accepted PENRO Records receipt; counted as zero for TC and SE.'
            : 'Included or excluded according to the source dates and the selected cutoff.');

    return <article data-obligation-key={item.key == null ? '' : String(item.key)} className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <h4 className="m-0">
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={panelId}
                data-cds-modal-disclosure
                onClick={onToggle}
                className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:hover:bg-gray-800"
            >
                <span className="min-w-0 flex-1">
                    <span className="block text-xs font-extrabold text-gray-900 dark:text-white">{label}</span>
                    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] font-semibold text-gray-600 dark:text-gray-300">
                        <span>{period}</span>
                        <span aria-hidden="true">|</span>
                        <span>{document}</span>
                        {item.source_reference && <><span aria-hidden="true">|</span><span>{item.source_reference}</span></>}
                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200">{statusText}</span>
                    </span>
                </span>
                <span className="shrink-0 text-[10px] font-bold text-emerald-800 dark:text-emerald-200">{expanded ? 'Hide details' : 'Show details'}</span>
            </button>
        </h4>
        <div id={panelId} hidden={!expanded} className="border-t border-gray-200 px-3 py-3 dark:border-gray-700">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-extrabold text-gray-900 dark:text-white">{label}</p>
                    <p className="mt-1 text-[11px] font-semibold text-emerald-800 dark:text-emerald-200">{statusText}</p>
                </div>
                <p className="text-[10px] font-medium text-gray-500 dark:text-gray-400">{sourceIdentity}</p>
            </div>
            <dl className="mt-3 grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                <DataPoint label="Workflow start" value={item.start_date || 'Missing'} />
                <DataPoint label="Authoritative deadline" value={item.deadline || 'Missing'} />
                <DataPoint label="Accepted PENRO receipt" value={item.received || 'None by cutoff'} />
                <DataPoint label="T allowed" value={item.allowed_days == null ? 'Unavailable' : String(item.allowed_days) + ' days | ' + (item.day_convention || 'source convention')} />
                <DataPoint label="D used" value={item.days_used == null ? 'Unavailable' : String(item.days_used) + ' days'} />
                <DataPoint label="Individual SE" value={item.individual_efficiency_percent == null ? 'Not calculated' : Number(item.individual_efficiency_percent).toFixed(2) + '%'} />
                <DataPoint label="Identity / period" value={[identity.workflow_key, identity.period_key || identity.reporting_period, identity.reporting_year].filter(Boolean).join(' | ') || 'Unavailable'} />
                <DataPoint label="Activity / document" value={[identity.submitted_activity_name || identity.activity_name, identity.document_type].filter(Boolean).join(' | ') || 'Unavailable'} />
            </dl>
            <p className="mt-3 rounded-md bg-gray-50 px-3 py-2 text-[11px] leading-4 text-gray-700 dark:bg-gray-950 dark:text-gray-200"><strong>Assessment:</strong> {reason}</p>
        </div>
    </article>;
}
function Summary({ label, value, detail }) {
    return <article className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
        <p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{value}</p>
        <p className="mt-1 text-[10px] leading-4 text-gray-600 dark:text-gray-300">{detail}</p>
    </article>;
}

function DataPoint({ label, value }) {
    return <div><dt className="font-bold text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-0.5 break-words text-gray-900 dark:text-gray-100">{value}</dd></div>;
}

function count(value) {
    return value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString();
}

function percent(value) {
    return value == null || !Number.isFinite(Number(value)) ? 'Not applicable' : `${Number(value).toFixed(2)}%`;
}

function rankLabel(row) {
    if (row.rank == null) return row.rank_status || row.score_status || 'Not ranked';
    const rank = Number(row.rank);
    const suffix = rank % 100 >= 11 && rank % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[rank % 10] || 'th');
    return `${rank}${suffix} place`;
}
