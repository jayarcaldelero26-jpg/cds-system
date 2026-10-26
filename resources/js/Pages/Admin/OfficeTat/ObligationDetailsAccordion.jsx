import { useId, useState } from 'react';

function stableObligationKey(item, participantKey) {
    if (item?.key !== undefined && item?.key !== null && String(item.key) !== '') {
        return String(item.key);
    }
    const identity = item?.source_identity || {};
    return JSON.stringify([
        participantKey,
        identity.workflow_key || '',
        identity.activity_name || '',
        identity.document_type || '',
        identity.reporting_year || '',
        identity.period_key || '',
        item?.occurrence_key || identity.occurrence_key || '',
        item?.start_date || '',
        item?.deadline || '',
        item?.label || '',
    ]);
}

function EvidencePoint({ label, value }) {
    return <div className="min-w-0 rounded-lg bg-gray-50 p-2 dark:bg-gray-950">
        <dt className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt>
        <dd className="mt-0.5 break-words text-[11px] leading-4 text-gray-800 dark:text-gray-200">{value || '?'}</dd>
    </div>;
}

function ObligationRow({ item, expanded, onToggle }) {
    const panelId = 'office-tat-obligation-panel-' + useId();
    const identity = item.source_identity || {};
    const period = [identity.reporting_year, identity.period_key].filter(Boolean).join(' ') || item.period_label || item.period_key || 'Period not recorded';
    const document = identity.document_type || item.document_type || 'Document not recorded';
    const key = String(item.key ?? '');

    return <article data-obligation-key={key} className="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <h3 className="m-0">
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={panelId}
                data-cds-modal-disclosure
                onClick={onToggle}
                className="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-left transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:hover:bg-gray-800"
            >
                <span className="min-w-0 flex-1">
                    <span className="block text-xs font-extrabold text-gray-900 dark:text-white">{item.label}</span>
                    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] font-semibold text-gray-600 dark:text-gray-300">
                        <span>{period}</span>
                        <span aria-hidden="true">|</span>
                        <span>{document}</span>
                        {item.source_reference && <><span aria-hidden="true">|</span><span>{item.source_reference}</span></>}
                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200">{item.status}</span>
                    </span>
                </span>
                <span className="shrink-0 text-[10px] font-bold text-emerald-800 dark:text-emerald-200">{expanded ? 'Hide details' : 'Show details'}</span>
            </button>
        </h3>
        <div id={panelId} hidden={!expanded} className="border-t border-gray-200 px-3 py-3 dark:border-gray-700">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-extrabold text-gray-900 dark:text-white">{item.label}</p>
                    <p className="mt-1 text-[11px] font-semibold text-emerald-800 dark:text-emerald-200">{item.status}</p>
                </div>
                <span className={'rounded-full px-2 py-1 text-[10px] font-bold ' + (item.technical == null ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' : item.technical ? 'bg-violet-100 text-violet-900 dark:bg-violet-950 dark:text-violet-200' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200')}>{item.technical == null ? 'Technical classification unknown' : item.technical ? 'Highly technical' : 'Not highly technical'}</span>
            </div>
            <dl className="mt-3 grid gap-2 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
                <EvidencePoint label="Approved start" value={item.start_date} />
                <EvidencePoint label="Deadline" value={item.deadline} />
                <EvidencePoint label={item.receipt_endpoint} value={item.received || 'No accepted receipt'} />
                <EvidencePoint label="Allowed T / used D" value={[(item.allowed_days == null ? 'T unavailable' : 'T ' + item.allowed_days), (item.days_used == null ? 'D unavailable' : 'D ' + item.days_used), item.day_convention].filter(Boolean).join(' / ')} />
                {item.historical_receipt_evidence && <EvidencePoint label="Original historical receipt (separate)" value={item.historical_receipt_evidence} />}
                {item.approving_authority && <EvidencePoint label="Schedule authority / reference" value={[item.approving_authority, item.approval_reference || 'No reference'].join(' | ')} />}
            </dl>
            {item.source_identity && <p className="mt-2 text-[11px] leading-4 text-gray-600 dark:text-gray-300">Source identity: {item.source_identity.workflow_key} | {item.source_identity.activity_name} | {item.source_identity.document_type} | {item.source_identity.reporting_year} {item.source_identity.period_key}{item.occurrence_key ? ' | occurrence ' + item.occurrence_key : ''}.</p>}
            {item.approval_evidence_reference && <a className="mt-1 inline-block text-[11px] font-bold text-emerald-800 underline dark:text-emerald-200" href={'/admin/office-tat/approval-evidence/' + item.approval_evidence_reference}>Open approved schedule source</a>}
            {item.nonreceipt_verified && <p className="mt-2 rounded-lg bg-emerald-50 px-2.5 py-2 text-[11px] leading-4 text-emerald-950 dark:bg-emerald-950/30 dark:text-emerald-100">Approved historical non-receipt | {item.nonreceipt_approving_authority} / {item.nonreceipt_approval_reference}{item.nonreceipt_evidence_reference && <> | <a className="font-bold underline" href={'/admin/office-tat/approval-evidence/' + item.nonreceipt_evidence_reference}>Open register source</a></>}</p>}
            {item.source_rows_matched > 1 && <p className="mt-2 rounded-lg bg-sky-50 px-2.5 py-2 text-[11px] leading-4 text-sky-950 dark:bg-sky-950/30 dark:text-sky-100">{item.source_rows_matched} live source rows matched this one obligation. Only the earliest accepted receipt is counted; duplicates do not add volume or reset the start.</p>}
            {item.issue && <p className="mt-2 rounded-lg bg-amber-50 px-2.5 py-2 text-[11px] leading-4 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">{item.issue}</p>}
        </div>
    </article>;
}

export default function ObligationDetailsAccordion({ participantKey, items = [] }) {
    const [expandedKey, setExpandedKey] = useState(null);

    return <div className="space-y-2" aria-label="Participant obligation details">
        {items.map(item => {
            const key = stableObligationKey(item, participantKey);
            return <ObligationRow
                key={key}
                item={item}
                expanded={expandedKey === key}
                onToggle={() => setExpandedKey(current => current === key ? null : key)}
            />;
        })}
        {items.length === 0 && <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">No verified obligation inventory is available for this participant and period.</p>}
    </div>;
}
