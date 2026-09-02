import { formatReportDate, formatReportDateTime } from '@/Utils/dateFormatters';
import { useState } from 'react';

const FALLBACK = '\u2014';

function MilestoneMarker({ item }) {
    if (item.current) {
        return <span className="edats-current-stage-marker relative z-0 flex h-8 w-8 shrink-0 items-center justify-center" aria-hidden="true">
            <span className="edats-current-stage-marker__ripple edats-current-stage-marker__ripple--first absolute left-1/2 top-1/2 z-0 h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border" />
            <span className="edats-current-stage-marker__ripple edats-current-stage-marker__ripple--second absolute left-1/2 top-1/2 z-0 h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full border" />
            <span className="edats-current-stage-marker__halo absolute left-1/2 top-1/2 z-10 h-[18px] w-[18px] -translate-x-1/2 -translate-y-1/2 rounded-full" />
            <span className="relative z-20 h-2 w-2 rounded-full bg-green-700 shadow-[0_0_0_2px_rgba(22,163,74,0.12)] dark:bg-green-400" />
        </span>;
    }

    if (item.complete) return <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-green-700 text-[10px] text-white" aria-hidden="true">✓</span>;
    return <span className="flex h-3 w-3 shrink-0 rounded-full border border-gray-400" aria-hidden="true" />;
}

export default function PambMovProgress({ row, context = {}, onSubmit, onReview, onRelease }) {
    const progress = row?.mov_processing;
    const [submitting, setSubmitting] = useState(false);
    if (!progress?.applicable) return null;

    const status = progress.status_key;
    const reviewable = status === 'submitted_for_review';
    const correction = status === 'needs_correction';
    const releasable = status === 'ready_for_release';
    const canSubmit = context.can_submit_mov && Boolean(row.mov_url) && (status === 'activity_conducted' || correction);
    const awaitingChiefReview = reviewable && !context.can_review_mov;
    const submitReview = () => {
        if (submitting || !canSubmit) return;
        setSubmitting(true);
        onSubmit?.(row, { onFinish: () => setSubmitting(false) });
    };

    return <section className="space-y-3 rounded-xl border border-green-100 bg-green-50/60 p-3 dark:border-green-900/60 dark:bg-green-950/20" aria-label="MOV processing progress">
        <div className="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h3 className="text-xs font-bold uppercase tracking-wider text-green-900 dark:text-green-200">MOV Processing Progress</h3>
                <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{progress.workflow_status || progress.status_label}</p>
                {progress.workflow_status && progress.workflow_status !== progress.status_label && <p className="mt-0.5 text-[11px] text-gray-600 dark:text-gray-300">MOV milestone: {progress.status_label}</p>}
            </div>
            <span className="text-2xl font-black text-green-800 dark:text-green-200">{progress.percent}%</span>
        </div>
        <div className="h-2 overflow-hidden rounded-full bg-green-100 dark:bg-green-900/60"><div className="h-full rounded-full bg-green-700 transition-all" style={{ width: `${progress.percent}%` }} /></div>
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            {(progress.milestones || []).map(item => <div key={item.key} className="flex min-w-0 items-center gap-1.5 text-[11px] text-gray-600 dark:text-gray-300"><MilestoneMarker item={item} /><span className="min-w-0">{item.label}</span></div>)}
        </div>
        {awaitingChiefReview && <div className="rounded-lg border border-green-200 bg-white/70 px-3 py-2 text-xs dark:border-green-900/70 dark:bg-gray-900/40">
            <p className="font-bold uppercase tracking-wide text-green-800 dark:text-green-200">Review Status</p>
            <p className="mt-1 font-semibold text-gray-900 dark:text-white">✓ Submitted for Review{progress.submitted_at ? ` · ${formatReportDateTime(progress.submitted_at, FALLBACK)}` : ''}</p>
            <p className="mt-1 text-gray-600 dark:text-gray-300">◎ Awaiting Review by CENRO CDS Chief</p>
            <p className="mt-1 text-gray-600 dark:text-gray-300">Next Action: CENRO CDS Chief must review this MOV/report.</p>
        </div>}
        {correction && <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <p className="font-bold uppercase tracking-wide">Correction Required</p>
            <p className="mt-1 whitespace-pre-wrap">{progress.review_remarks || 'Please review the Chief remarks before resubmitting.'}</p>
            <p className="mt-1 text-amber-800/80 dark:text-amber-200/80">Returned by: {progress.reviewed_by || 'CENRO CDS Chief'}{progress.reviewed_at ? ` · ${formatReportDateTime(progress.reviewed_at, FALLBACK)}` : ''}</p>
        </div>}
        <div className="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><span className="font-bold uppercase tracking-wide text-gray-500">Turnaround</span><span className="font-semibold text-gray-900 dark:text-white">{progress.turnaround?.label || FALLBACK}</span><span>Deadline: {formatReportDate(progress.turnaround?.deadline, FALLBACK)}</span></div>
        <div className="flex flex-wrap gap-2">
            {row.mov_url && <a href={row.mov_url} target="_blank" rel="noreferrer" className="inline-flex rounded-lg border border-green-700 px-2.5 py-1.5 text-xs font-bold text-green-800 hover:bg-green-100 dark:text-green-200 dark:hover:bg-green-900/40">View MOV / Report</a>}
            {correction && row.source_url && <a href={row.source_url} className="inline-flex rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Edit / Correct Submission</a>}
        </div>
        <div className="flex flex-wrap gap-2">
            {canSubmit && <button type="button" onClick={submitReview} disabled={submitting} aria-busy={submitting} className="rounded-lg bg-green-700 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-60">{submitting ? 'Submitting...' : correction ? 'Resubmit for Review' : 'Submit for Review'}</button>}
            {context.can_review_mov && reviewable && <><button type="button" onClick={() => onReview?.(row, 'ready_for_release')} className="rounded-lg bg-green-700 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-green-800">Ready for Release</button><button type="button" onClick={() => onReview?.(row, 'needs_correction')} className="rounded-lg border border-amber-300 bg-white px-2.5 py-1.5 text-xs font-bold text-amber-800 hover:bg-amber-50">Needs Correction</button></>}
            {context.can_release_mov && row.cenro_release_applicable !== false && releasable && <button type="button" onClick={() => onRelease?.(row)} className="rounded-lg bg-green-800 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-green-900">Record CENRO Release</button>}
        </div>
        {progress.chief_verdict_label && <div className="border-t border-green-100 pt-2 text-[11px] text-gray-600 dark:border-green-900/60 dark:text-gray-300"><span className="font-bold uppercase tracking-wide text-gray-500">Final Chief Verdict:</span> {progress.chief_verdict_label}</div>}
        {(progress.review_history || []).length > 0 && <div id="pamb-cenro-review-history" className="border-t border-green-100 pt-2 dark:border-green-900/60"><p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">CENRO Processing History</p>{progress.review_history.map((item, index) => <p key={`${item.event_key}-${index}`} className="mt-1 text-[11px] text-gray-600 dark:text-gray-300">{item.event_label} · {item.recorded_by || 'Recorded user'} · {item.recorded_at ? formatReportDateTime(item.recorded_at, FALLBACK) : FALLBACK}{item.remarks ? ` · ${item.remarks}` : ''}</p>)}</div>}
    </section>;
}
