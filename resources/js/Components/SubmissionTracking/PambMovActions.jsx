import { useState } from "react";
import Button from "@/Components/Button";
import { movActionAvailability } from "@/Utils/submissionDetailContext";

/** The same existing MOV controls are used by the sidebar and Full Details. */
export default function PambMovActions({ row, context = {}, onSubmit, onReview, onRelease, hideReleaseAction = false, showContextLabel = false }) {
    const [submitting, setSubmitting] = useState(false);
    const actions = movActionAvailability(row, context, hideReleaseAction);
    const correction = row?.mov_processing?.status_key === "needs_correction";
    const submitReview = () => {
        if (submitting || !actions.submit || !onSubmit) return;
        setSubmitting(true);
        onSubmit(row, { onFinish: () => setSubmitting(false) });
    };

    if (!actions.submit && !actions.review && !actions.release) return null;

    return <div className="space-y-2" role="group" aria-label="MOV review and submission actions">
        {showContextLabel && <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">MOV review and submission</p>}
        <div className="flex flex-wrap gap-2">
            {actions.submit && <Button type="button" size="compact" variant="primary" onClick={submitReview} disabled={submitting} aria-busy={submitting} className="rounded-lg px-2.5 py-1.5 text-xs">{submitting ? 'Submitting...' : correction ? 'Resubmit for Review' : 'Submit for Review'}</Button>}
            {actions.review && <><Button type="button" size="compact" variant="primary" onClick={() => onReview?.(row, 'ready_for_release')} className="rounded-lg px-2.5 py-1.5 text-xs">Ready for Release</Button><Button type="button" size="compact" variant="warning" onClick={() => onReview?.(row, 'needs_correction')} className="rounded-lg px-2.5 py-1.5 text-xs">Needs Correction</Button></>}
            {actions.release && <Button type="button" size="compact" variant="primary" onClick={() => onRelease?.(row)} className="rounded-lg px-2.5 py-1.5 text-xs">Release</Button>}
        </div>
    </div>;
}
