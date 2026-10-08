export default function PambMovCorrectionNotice({ row }) {
    const progress = row?.mov_processing;
    if (!progress?.applicable || progress.status_key !== "needs_correction") return null;

    const routing = row?.routing || row?.routing_summary || {};
    const custodyReturned = progress?.cenro_review?.custody_return_recorded === true;
    const stage = String(routing.current_stage || "");
    const nextCustodyAction = stage === "cenro_chief" || stage.startsWith("transit_")
        ? routing.next_expected_action
        : null;

    return <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100" role="note" aria-label="MOV review and custody status">
        <p>{custodyReturned
            ? "Needs Correction is the MOV review verdict, and the custody return has been recorded."
            : "Needs Correction is an MOV review verdict only; no custody return has been recorded yet."}</p>
        {nextCustodyAction && <p className="mt-1 font-semibold">Next custody action: {nextCustodyAction}</p>}
    </div>;
}
