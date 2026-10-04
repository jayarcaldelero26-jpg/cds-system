import { lazy, Suspense, useEffect, useState } from 'react';
import { inlinePreviewType, startProtectedPreview } from '@/Utils/protectedDocumentPreview.mjs';

const PdfDocumentViewer = lazy(() => import('@/Components/SubmissionTracking/PdfDocumentViewer'));

export default function DocumentPreviewDialog({ open, row, accountKey = null, onClose }) {
    const [preview, setPreview] = useState(null);
    const [retry, setRetry] = useState(0);
    const attachment = row?.current_document;
    const canPreview = attachment?.can_preview === true;
    const url = attachment?.preview_url || attachment?.url;
    const downloadUrl = attachment?.download_url || attachment?.url || url;
    const mimeType = String(attachment?.mime_type || attachment?.type || '').toLowerCase();
    const inline = Boolean(inlinePreviewType(mimeType));
    const requestKey = JSON.stringify([accountKey, row?.source, row?.id, url, attachment?.name, attachment?.size, retry]);

    useEffect(() => {
        if (!open || !url || !inline || !canPreview) {
            setPreview(null);
            return undefined;
        }
        const task = startProtectedPreview(url, {
            onState: state => setPreview({ ...state, requestKey }),
        });
        return task.dispose;
    }, [open, url, inline, requestKey, canPreview]);

    useEffect(() => {
        if (!open) return undefined;
        const closeOnEscape = event => event.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [open, onClose]);

    if (!open) return null;

    const current = preview?.requestKey === requestKey ? preview : null;
    const previewReady = current?.status === 'ready';
    const previewError = current?.status === 'error';

    return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-gray-950/70 p-4 backdrop-blur-xs" role="presentation">
        <div role="dialog" aria-modal="true" aria-label="Document Preview" className="flex h-[92vh] max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div className="min-w-0"><h2 className="text-base font-bold text-gray-900 dark:text-white">Document Preview</h2><p className="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{attachment?.name || 'MOV / report attachment'}</p></div>
            </div>
            <div className="flex min-h-0 flex-1 flex-col p-3">
                <dl className="mb-2 grid gap-2 text-[11px] sm:grid-cols-4">
                    {[['Workflow', row?.module], ['Protected Area', row?.protected_area], ['Reporting Period', row?.reporting_period], ['Document Type', row?.document_type]].map(([label, value]) => <div key={label} className="min-w-0"><dt className="font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-1 truncate font-semibold text-gray-900 dark:text-white">{value || '—'}</dd></div>)}
                </dl>
                {!canPreview ? <p role="alert" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">Your current account is not authorized to access this document.</p>
                    : !url ? <p className="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">No MOV/report attachment is available for this submission.</p>
                    : inline ? previewReady ? <div className="flex min-h-0 flex-1 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950/40">{current.type === 'application/pdf' ? <Suspense fallback={<p role="status" className="m-auto text-sm text-gray-500 dark:text-gray-400">Preparing PDF viewer…</p>}><PdfDocumentViewer blob={current.blob} title={attachment.name || 'MOV/report'} /></Suspense> : <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto p-4"><img src={current.url} alt={attachment.name || 'MOV/report'} className="max-h-full max-w-full object-contain" onError={() => setPreview({ ...current, status: 'error', error: { message: 'The image could not be displayed. Retry the preview.' } })} /></div>}</div>
                        : previewError ? <div role="alert" className="rounded-xl border border-gray-200 px-4 py-10 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300"><p>{current.error.message}</p><button type="button" onClick={() => setRetry(value => value + 1)} className="mt-3 rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Retry Preview</button></div>
                        : <p role="status" className="rounded-xl border border-gray-200 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Loading document preview…</p>
                    : <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><p>{mimeType ? "This document type cannot be previewed in the browser." : "Preview is unavailable because the file type could not be verified."}</p><p className="mt-1 font-semibold">Open or download the file to inspect it.</p></div>}
            </div>
            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700"><button type="button" onClick={onClose} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant="primary">Close Preview</button>{canPreview && downloadUrl && (!inline || previewReady) && <a href={downloadUrl} download={attachment?.name || undefined} data-cds-action="true" data-cds-action-variant="primary" className="cds-button-interaction rounded-xl px-4 py-2.5 text-xs font-bold">Download Current Copy</a>}</div>
        </div>
    </div>;
}
