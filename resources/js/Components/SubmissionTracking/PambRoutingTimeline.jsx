import { formatReportDate, formatReportDateTime } from '@/Utils/dateFormatters';

const FALLBACK = '\u2014';

function eventDate(value) {
    if (!value) return FALLBACK;
    return String(value).length <= 10 ? formatReportDate(value, FALLBACK) : formatReportDateTime(value, FALLBACK);
}

function delayLabel(stage) {
    const count = stage.elapsed_working_days;
    if (count === null || count === undefined) return null;
    return `${stage.delay_type === 'receipt' ? 'Receipt delay' : 'Processing'}: ${count} working day${count === 1 ? '' : 's'}`;
}

function pendingLabel(stage) {
    const count = stage.pending_working_days;
    if (count === null || count === undefined) return null;
    return `${count} working day${count === 1 ? '' : 's'} ${stage.delay_type === 'receipt' ? 'pending receipt' : `pending at ${stage.held_at}`}`;
}

function Marker({ status }) {
    if (status === 'completed') return <span className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-green-700 text-[10px] font-black text-white" aria-hidden="true">✓</span>;
    if (status === 'current') return <span className="edats-current-stage-marker relative z-0 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center" aria-hidden="true">
        <span className="edats-current-stage-marker__ripple edats-current-stage-marker__ripple--first absolute left-1/2 top-1/2 z-0 h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border" />
        <span className="edats-current-stage-marker__ripple edats-current-stage-marker__ripple--second absolute left-1/2 top-1/2 z-0 h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border" />
        <span className="edats-current-stage-marker__halo absolute left-1/2 top-1/2 z-10 h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full" />
        <span className="relative z-20 h-2 w-2 rounded-full bg-green-700 dark:bg-green-400" />
    </span>;
    if (status === 'not_applicable') return <span className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] text-gray-500 dark:bg-gray-700 dark:text-gray-300" aria-hidden="true">—</span>;
    return <span className="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600" aria-hidden="true" />;
}

function SummaryItem({ label, value, date = false }) {
    return <div className="min-w-0 rounded-lg border border-gray-100 bg-gray-50/80 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/50">
        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 break-words text-sm font-semibold text-gray-900 dark:text-white">{date ? eventDate(value) : value || FALLBACK}</p>
    </div>;
}

export default function PambRoutingTimeline({ row, onRecord, onCanonicalAction }) {
    if (!row?.pamb_routing_applicable) return null;
    const metrics = row.routing_summary_metrics || {};
    const summary = row.routing_summary || {};
    const lastAction = summary.last_action || {};
    const review = row.mov_processing?.cenro_review;
    const metric = key => {
        const item = metrics[key];
        if (!item) return FALLBACK;
        if (item.status === 'not_applicable') return item.value || 'N/A';
        return item.value === null || item.value === undefined ? 'Pending' : `${item.value} working days`;
    };

    return <div className="space-y-4">
        <section className="space-y-3">
            <div>
                <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">Current Processing</h3>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">A concise view of the document's current owner, delay, and next expected action.</p>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <SummaryItem label="Current Location" value={summary.current_location || row.current_document_location} />
                <SummaryItem label="Current Status" value={summary.current_status || row.current_processing_status} />
                <SummaryItem label="Responsible Office" value={summary.responsible_office} />
                <SummaryItem label="Pending Since" value={summary.pending_since} date />
                <SummaryItem label="Working Days Pending" value={summary.working_days_pending === null || summary.working_days_pending === undefined ? null : `${summary.working_days_pending} working day${summary.working_days_pending === 1 ? '' : 's'}`} />
                <SummaryItem label="Next Expected Action" value={summary.next_expected_action} />
                <SummaryItem label="Last Updated" value={summary.last_updated} date />
                <SummaryItem label="Recorded By" value={lastAction.recorded_by} />
            </div>
            {lastAction.label && <div className="rounded-lg border border-green-100 bg-green-50/60 px-3 py-2 text-xs dark:border-green-900/60 dark:bg-green-950/20">
                <p className="font-bold uppercase tracking-wide text-green-800 dark:text-green-300">Last Action</p>
                <p className="mt-1 font-semibold text-gray-900 dark:text-white">{lastAction.label} · {eventDate(lastAction.occurred_at)}</p>
                {lastAction.recorded_by && <p className="mt-0.5 text-gray-600 dark:text-gray-300">Recorded by: {lastAction.recorded_by}</p>}
                {lastAction.remarks && <p className="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-300">Remarks: {lastAction.remarks}</p>}
            </div>}
            {review?.applicable && <section className="rounded-lg border border-blue-100 bg-blue-50/60 px-3 py-2 text-xs dark:border-blue-900/60 dark:bg-blue-950/20" aria-label="CENRO review verdict">
                <div className="flex flex-wrap items-center justify-between gap-2"><p className="font-bold uppercase tracking-wide text-blue-800 dark:text-blue-300">CENRO Review Verdict</p><span className="rounded-full bg-blue-100 px-2 py-1 font-bold text-blue-800 dark:bg-blue-900/60 dark:text-blue-100">{review.verdict}</span></div>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    <SummaryItem label="Reviewed By" value={review.reviewed_by} />
                    <SummaryItem label="User Category" value={review.reviewed_user_category || (review.verdict_key ? null : 'CENRO CDS Chief')} />
                    <SummaryItem label="Originating Office" value={review.originating_office} />
                    <SummaryItem label="Reviewed On" value={review.reviewed_at} date />
                </div>
                {review.remarks && <p className="mt-2 whitespace-pre-wrap text-gray-700 dark:text-gray-200"><span className="font-semibold">Remarks / Review Notes:</span> {review.remarks}</p>}
                {review.correction_reason && <p className="mt-2 whitespace-pre-wrap text-amber-800 dark:text-amber-200"><span className="font-semibold">Correction Reason:</span> {review.correction_reason}</p>}
                {review.previous_correction_cycles > 0 && <p className="mt-2 text-gray-600 dark:text-gray-300"><span className="font-semibold">Previous Correction Cycles:</span> {review.previous_correction_cycles}</p>}
                {review.previous_correction && <p className="mt-2 text-gray-600 dark:text-gray-300"><span className="font-semibold">Previous Correction:</span> Needs Correction — {review.previous_correction.reason || 'Reason recorded in history.'}</p>}
                <a href="#pamb-cenro-review-history" className="mt-2 inline-flex font-bold text-blue-800 hover:underline dark:text-blue-200">View Review History</a>
            </section>}
        </section>

        <section className="space-y-3">
            <div><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">Routing Timeline</h3><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Detailed internal routing records real-world events; eDATS does not transmit documents.</p></div>
            {row.cenro_release_applicable === false && <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">CENRO Release: N/A — PENRO-managed protected area. The timeline begins at the first legitimate PENRO stage.</p>}
            <div className="rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800"><ol className="divide-y divide-gray-100 dark:divide-gray-700">{(row.routing_timeline || []).map(stage => {
                const current = stage.status === 'current';
                const notApplicable = stage.status === 'not_applicable';
                const delay = delayLabel(stage);
                const pending = pendingLabel(stage);
                const canonicalAction = current && !stage.is_internal && stage.key !== 'cenro_release' && stage.action_label && row.can_transition && onCanonicalAction;
                return <li key={stage.key} className="flex items-start gap-3 py-2 first:pt-1 last:pb-1"><Marker status={stage.status} /><div className="min-w-0 flex-1"><div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1"><p className={`text-sm ${stage.status === 'completed' || current ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-500 dark:text-gray-400'}`}>{stage.label}</p>{(stage.can_record || canonicalAction) && <button type="button" onClick={() => (canonicalAction ? onCanonicalAction(stage) : onRecord?.(stage))} className="rounded-lg bg-green-700 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-green-800">{stage.action_label || 'Record Event'}</button>}</div><p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{notApplicable ? 'Not applicable' : stage.occurred_at ? eventDate(stage.occurred_at) : stage.status === 'not_recorded' ? 'Not recorded' : current ? 'Awaiting action' : 'Not yet reached'}</p>{delay && <p className="mt-0.5 text-[11px] font-medium text-green-700 dark:text-green-300">{delay}</p>}{pending && <p className="mt-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-300">{pending}</p>}{stage.recorded_by && <p className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">Recorded by {stage.recorded_by}</p>}{stage.remarks && <p className="mt-1 whitespace-pre-wrap text-[11px] italic text-gray-500 dark:text-gray-400">{stage.remarks}</p>}</div></li>;
            })}</ol></div>
        </section>

        <section className="space-y-3"><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">Informational Turnaround Metrics</h3><div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{[['CENRO → PENRO', 'cenro_to_penro'], ['PENRO → Regional', 'penro_to_regional'], ['CENRO → Regional', 'cenro_to_regional'], ['Total Pending at PENRO', 'total_working_days_pending_at_penro']].map(([label, key]) => <div key={key} className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/70"><p className="text-[10px] font-bold uppercase leading-4 text-gray-500 dark:text-gray-400">{label}</p><p className="mt-1 text-sm font-bold text-gray-900 dark:text-white">{metric(key)}</p></div>)}</div><p className="text-[11px] text-gray-500 dark:text-gray-400">Turnaround metrics use the PAMB business calendar and do not affect compliance status, deadline, timeliness, or alert eligibility.</p></section>
    </div>;
}
