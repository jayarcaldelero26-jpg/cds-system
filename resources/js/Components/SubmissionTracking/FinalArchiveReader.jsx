import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import { formatManila } from '@/Utils/dateFormatters';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import FinalArchiveRecordActions from './FinalArchiveRecordActions';
import SubmissionTrackingReaderDialog from './SubmissionTrackingReaderDialog';
import PamoReportPendingAnimation from '@/Components/PamoReportPendingAnimation';
import { inlinePreviewType } from '@/Utils/protectedDocumentPreview.mjs';
import { archiveHasVerifiedFiles, archiveReportTitle, archiveRowContext } from '@/Utils/finalArchivePresentation.mjs';

const tabs = [
    { value: 'document', label: 'Document' },
    { value: 'details', label: 'Details' },
    { value: 'history', label: 'History' },
];

function Field({ label, value }) {
    if (!value) return null;
    return <div className="min-w-0 py-2.5">
        <dt className="text-[11px] font-medium text-slate-500 dark:text-slate-400">{label}</dt>
        <dd className="mt-0.5 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{value}</dd>
    </div>;
}

function manifestMessage(selected, statusPollTimedOut) {
    if (selected?.earlier_dispatch_copy) {
        return 'This is an earlier Dispatch archive copy. Availability is rechecked when it is opened; it does not confirm a Regional receipt or final signed return.';
    }
    const manifest = selected?.latest_manifest;
    if (!manifest) return selected?.can_receive_final ? 'The final signed documents have not been received yet.' : 'No final archive is available for this report yet.';
    if (manifest.status === 'PROCESSING' && manifest.processing_claim_active) return 'Archive verification is processing.';
    if (manifest.status === 'PROCESSING' && manifest.retry_available) return 'Archive verification stopped before completion. The receipt and signed files are retained for retry.';
    if (manifest.status === 'PROCESSING') return statusPollTimedOut
        ? 'Archive verification is still processing. Status checks have paused.'
        : 'Archive verification is still processing.';
    if (manifest.status === 'FAILED') return 'Archive verification failed. The receipt and signed files are retained for retry.';
    if (manifest.status === 'PENDING') return 'The signed files are awaiting archive verification.';
    if (manifest.status === 'ARCHIVED') {
        const files = manifest.files || [];
        const verified = files.length > 0 && files.every(file => file.status === 'ARCHIVED' && file.preview_url && file.download_url);
        return verified ? null : 'Archive verification has not confirmed every signed file.';
    }
    return null;
}

export default function FinalArchiveReader({
    open,
    selected,
    initialTab = 'document',
    onClose,
    onReceive,
    onAddArchive,
    onRetry,
    retrying = false,
    statusPollTimedOut = false,
    requestError = '',
}) {
    const busyRef = useRef(null);
    const retryFocusRef = useRef(null);
    const recordKey = selected ? `${selected.source}:${selected.source_id}` : '';
    const earlierDispatchCopy = selected?.earlier_dispatch_copy || null;
    const files = selected?.latest_manifest?.files || (earlierDispatchCopy?.file ? [earlierDispatchCopy.file] : []);
    const isEarlierDispatchCopy = !selected?.latest_manifest && Boolean(earlierDispatchCopy?.file);
    const hasDocument = files.length > 0;
    const initialSection = hasDocument && ['document', 'details', 'history'].includes(initialTab)
        ? initialTab
        : initialTab === 'document' ? 'details' : initialTab;
    const [activeTab, setActiveTab] = useState(initialSection);
    const [activeFileId, setActiveFileId] = useState(files[0]?.id ?? '');
    const [expanded, setExpanded] = useState(false);
    const [documentRequested, setDocumentRequested] = useState(initialTab === 'document' && hasDocument);
    const activeFile = files.find(file => String(file.id) === String(activeFileId)) || files[0] || null;
    const mime = String(activeFile?.mime_type || '').toLowerCase();
    const archiveIsVerified = archiveHasVerifiedFiles({ manifest: selected?.latest_manifest });
    const hasProtectedPreview = (archiveIsVerified || isEarlierDispatchCopy) && Boolean(activeFile?.preview_url);
    const canInlinePreview = hasProtectedPreview && inlinePreviewType(mime);
    const context = archiveRowContext(selected) || selected?.originating_office || '';
    const headerTitle = archiveReportTitle(selected);
    const message = manifestMessage(selected, statusPollTimedOut);
    const explanation = archiveIsVerified
        ? (hasDocument && !canInlinePreview ? 'This file type cannot be previewed here.' : null)
        : message;
    const history = selected?.routing_timeline || [];

    useEffect(() => {
        if (!selected) return;
        setActiveTab(initialSection);
        setActiveFileId(files[0]?.id ?? '');
        setDocumentRequested(initialTab === 'document' && hasDocument);
        setExpanded(false);
    // Reset display state only when the selected record/manifest changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recordKey, selected?.latest_manifest?.id, earlierDispatchCopy?.archive_id]);

    useEffect(() => {
        if (!open) return undefined;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = previousOverflow; };
    }, [open]);

    useEffect(() => {
        if (retrying) {
            retryFocusRef.current = document.activeElement;
            const frame = window.requestAnimationFrame(() => busyRef.current?.querySelector('[data-cds-busy-status]')?.focus());
            return () => window.cancelAnimationFrame(frame);
        }
        if (retryFocusRef.current?.isConnected) {
            const target = retryFocusRef.current;
            retryFocusRef.current = null;
            const frame = window.requestAnimationFrame(() => target.focus?.());
            return () => window.cancelAnimationFrame(frame);
        }
        return undefined;
    }, [retrying]);

    const documentPanel = <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        {hasDocument && <div className="flex min-w-0 shrink-0 items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900">
            <label htmlFor={files.length > 1 ? 'archive-reader-file' : undefined} className="shrink-0 text-[11px] font-medium text-slate-500 dark:text-slate-400">File</label>
            {files.length > 1
                ? <select id="archive-reader-file" value={activeFile?.id ?? ''} onChange={event => setActiveFileId(event.target.value)} className="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                    {files.map(file => <option key={file.id} value={file.id}>{file.name || 'Archived file'}</option>)}
                </select>
                : <span data-archive-original-filename className="min-w-0 truncate text-xs font-medium text-slate-700 dark:text-slate-200" title={activeFile?.name || 'Archived file'}>{activeFile?.name || 'Archived file'}</span>}
        </div>}
        {!hasDocument ? <div className="flex min-h-0 flex-1 items-center justify-center p-6 text-center">
            <div><Icon icon="lucide:file-text" width="40" height="40" className="mx-auto text-slate-300 dark:text-slate-600" aria-hidden="true" /><p className="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">No final document is available yet.</p></div>
        </div> : !hasProtectedPreview ? <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 bg-slate-50 p-6 text-center dark:bg-slate-950">
            <Icon icon="lucide:file-lock-2" width="44" height="44" className="text-slate-400" aria-hidden="true" />
            <p className="text-sm text-slate-600 dark:text-slate-300">The signed files are not available until verification finishes.</p>
        </div> : !canInlinePreview ? <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 bg-slate-50 p-6 text-center dark:bg-slate-950">
            <Icon icon="lucide:file" width="44" height="44" className="text-slate-400" aria-hidden="true" />
            <p className="text-sm text-slate-600 dark:text-slate-300">This file type cannot be previewed here.</p>
            {activeFile?.download_url && <a href={activeFile.download_url} download={activeFile.name || undefined} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-emerald-300">Download file</a>}
        </div> : documentRequested && <>
            {mime !== 'application/pdf' && activeFile?.download_url && <div className="flex shrink-0 justify-end border-b border-slate-200 bg-white px-3 py-1.5 dark:border-slate-700 dark:bg-slate-900"><a href={activeFile.download_url} download={activeFile.name || undefined} className="rounded-md border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Download</a></div>}
            <FilePreviewPanel
                key={activeFile?.id}
                file={{ ...activeFile, type: activeFile?.mime_type, protected: true }}
                title=""
                hideHeader
                bare
                heightClass="h-full w-full"
                className="h-full w-full"
                viewerOptions={{
                    archiveReader: true,
                    initialFitMode: 'page',
                    downloadUrl: activeFile?.download_url,
                    downloadName: activeFile?.name,
                    layoutKey: `${activeTab}:${expanded}:${activeFile?.id ?? ''}`,
                }}
            />
        </>}
        {hasDocument && !documentRequested && <button type="button" onClick={() => setDocumentRequested(true)} className="m-auto rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Load document preview</button>}
    </div>;

    const detailsPanel = <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-4 sm:px-6">
        {requestError && <p role="alert" className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-100">{requestError}</p>}
        {explanation && <p role="status" className={`mb-4 rounded-lg border px-3 py-2.5 text-sm ${archiveIsVerified ? 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200' : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100'}`}>{explanation}</p>}
        {selected?.latest_manifest?.status === 'PROCESSING' && statusPollTimedOut && <p role="status" className="mb-4 text-xs text-slate-500 dark:text-slate-400">Status checks paused. Reopen this record to check again.</p>}
        <dl className="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:gap-x-8 dark:divide-slate-800">
            <Field label={selected?.origin_kind === 'pamo_pamb' ? 'Originating PAMO' : 'Originating office'} value={selected?.originating_office} />
            <Field label="Supervising / receiving CENRO" value={selected?.supervising_office} />
            <Field label="Receiving PENRO" value={selected?.receiving_office} />
            <Field label="Regional release" value={selected?.release_occurred_at ? formatManila(selected.release_occurred_at) : null} />
            <Field label="Physical receipt" value={selected?.receipt?.physically_received_at ? `${formatManila(selected.receipt.physically_received_at)}${selected.receipt.receiving_office ? ` · ${selected.receipt.receiving_office}` : ''}` : null} />
            <Field label="Original filename" value={activeFile?.name} />
            <Field label="Archived" value={archiveIsVerified && selected?.latest_manifest?.finalized_at ? formatManila(selected.latest_manifest.finalized_at) : null} />
            <Field label="Earlier Dispatch copy" value={isEarlierDispatchCopy ? `${earlierDispatchCopy.availability_note}${earlierDispatchCopy.archived_at ? ` · saved ${formatManila(earlierDispatchCopy.archived_at)}` : ''}` : null} />
            <Field label={isEarlierDispatchCopy ? 'Archived files' : 'Signed documents'} value={files.length ? `${files.length} ${files.length === 1 ? 'file' : 'files'}` : null} />
        </dl>
        <FinalArchiveRecordActions
            selected={selected}
            retrying={retrying}
            onReceive={onReceive}
            onAddArchive={onAddArchive}
            onRetry={onRetry}
            hideUnavailableReason={archiveIsVerified
                || selected?.latest_manifest?.status === 'ARCHIVED'
                || Boolean(selected?.can_receive_final)
                || Boolean(selected?.can_archive_final && selected?.latest_manifest?.retry_available)
                || Boolean(selected?.latest_manifest?.processing_claim_active)}
        />
    </div>;

    const historyPanel = <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-4 sm:px-6">
        {history.length ? <ol className="space-y-4">
            {history.map((event, index) => <li key={`${event.type || 'route'}-${event.id || index}`} className="border-l-2 border-emerald-300 pl-3">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">{event.label}</p>
                <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">{event.occurred_at ? formatManila(event.occurred_at) : 'Time not recorded'}{event.actor ? ` · ${event.actor}` : ''}{event.office ? ` · ${event.office}` : ''}</p>
                {event.details && <p className="mt-1 whitespace-pre-wrap text-xs leading-5 text-slate-500 dark:text-slate-400">{event.details}</p>}
            </li>)}
        </ol> : <p className="text-sm text-slate-500 dark:text-slate-400">No history is available for this report.</p>}
    </div>;
    const busyOverlay = retrying && <div ref={busyRef} className="absolute inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" data-cds-busy-presentation="success-sized">
        <div className="flex min-h-[320px] w-full max-w-[390px] flex-col justify-center rounded-lg bg-white px-7 py-6 text-center shadow-xl dark:bg-slate-900"><PamoReportPendingAnimation intent="retry" /></div>
    </div>;

    return <SubmissionTrackingReaderDialog
        open={open}
        title={headerTitle}
        context={context}
        tabs={tabs}
        activeTab={activeTab}
        onTabChange={nextTab => {
            if (nextTab === 'document' && hasDocument) setDocumentRequested(true);
            setActiveTab(nextTab);
        }}
        panels={{ document: documentPanel, details: detailsPanel, history: historyPanel }}
        expanded={expanded}
        onExpandedChange={setExpanded}
        onClose={() => { if (!retrying) onClose?.(); }}
        closeDisabled={retrying}
        contentInert={retrying}
        busyOverlay={busyOverlay}
        closeOnEscape={!retrying}
        idPrefix="archive"
        initialFocusSelector="[data-archive-reader-close]"
    />;
}
