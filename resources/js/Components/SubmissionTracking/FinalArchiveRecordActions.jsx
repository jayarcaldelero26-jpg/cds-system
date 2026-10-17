export default function FinalArchiveRecordActions({ selected, retrying = false, onReceive, onAddArchive, onRetry, hideUnavailableReason = false }) {
    if (!selected) return null;

    const retryable = selected.latest_manifest?.retry_available === true;

    return (
        <div className="mt-4">
            <div className="flex flex-wrap gap-2">
                {selected.can_receive_final && <button type="button" onClick={onReceive} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-emerald-800" data-cds-action="true" data-cds-action-variant="primary">Receive Final MOVs from Regional Office</button>}
                {selected.can_archive_final && !selected.latest_manifest && <button type="button" onClick={onAddArchive} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-emerald-800" data-cds-action="true" data-cds-action-variant="primary">Attach Signed Set and Archive</button>}
                {selected.can_archive_final && retryable && <button type="button" onClick={onRetry} disabled={retrying} className="rounded-xl bg-amber-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-amber-700 disabled:opacity-60" data-cds-action="true" data-cds-action-variant="primary">{retrying ? 'Retrying…' : 'Retry Archive'}</button>}
            </div>
            {!hideUnavailableReason && selected.final_action_unavailable_reason && <p role="status" className="mt-3 text-xs leading-5 text-slate-600 dark:text-slate-300">{selected.final_action_unavailable_reason}</p>}
        </div>
    );
}
