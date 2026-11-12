import { useRef, useState } from "react";
import Button from "@/Components/Button";
import { movActionAvailability } from "@/Utils/submissionDetailContext";
import { createKeyedActionFlight } from "@/Utils/keyedActionFlight.mjs";

/** The same existing MOV controls are used by the sidebar and Full Details. */
export default function PambMovActions({ row, context = {}, onSubmit, onReview, onRelease, hideReleaseAction = false, hideSubmitAction = false, showContextLabel = false }) {
    const inFlight = useRef(createKeyedActionFlight());
    const [pendingActions, setPendingActions] = useState(() => new Set());
    const actions = movActionAvailability(row, context, hideReleaseAction);
    if (hideSubmitAction) actions.submit = false;
    const correction = row?.mov_processing?.status_key === "needs_correction";
    const actionKey = `${row?.source || row?.source_type || "report"}:${row?.source_id ?? row?.id ?? "unknown"}:mov-submit`;
    const submitting = pendingActions.has(actionKey);
    const submitReview = () => {
        if (!actions.submit || !onSubmit || !inFlight.current.begin(actionKey)) return;
        setPendingActions(new Set(inFlight.current.values()));
        const finish = (...args) => {
            inFlight.current.finish(actionKey);
            setPendingActions(new Set(inFlight.current.values()));
        };
        try {
            const result = onSubmit(row, { onFinish: finish });
            if (result === false) finish();
        } catch (error) {
            finish();
            throw error;
        }
    };

    if (!actions.submit && !actions.review && !actions.release) return null;

    return <div className="space-y-2" role="group" aria-label="MOV review and submission actions">
        {showContextLabel && <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">MOV requirements</p>}
        <div className="flex flex-wrap gap-2">
            {actions.submit && <Button type="button" size="compact" variant="primary" onClick={submitReview} disabled={submitting} aria-busy={submitting} className="rounded-lg px-3 py-2 text-xs">{submitting ? 'Submitting…' : correction ? 'Resubmit for Review' : 'Submit for Review'}</Button>}
            {actions.review && <><Button type="button" size="compact" variant="primary" onClick={() => onReview?.(row, 'ready_for_release')} className="rounded-lg px-3 py-2 text-xs">Ready for Release</Button><Button type="button" size="compact" variant="warning" onClick={() => onReview?.(row, 'needs_correction')} className="rounded-lg px-3 py-2 text-xs">Return MOV to CENRO Focal for Correction</Button></>}
            {actions.release && <Button type="button" size="compact" variant="primary" onClick={() => onRelease?.(row)} className="rounded-lg px-3 py-2 text-xs">Release</Button>}
        </div>
    </div>;
}
