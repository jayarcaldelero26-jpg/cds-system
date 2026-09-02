import { useEffect } from 'react';

function isInline(attachment) {
    return ['application/pdf', 'image/jpeg', 'image/png'].includes(String(attachment?.mime_type || '').toLowerCase());
}

export default function DocumentPreviewDialog({ open, row, onClose }) {
    useEffect(() => {
        if (!open) return undefined;
        const closeOnEscape = event => event.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [open, onClose]);

    if (!open) return null;

    const attachment = row?.mov_attachment;
    const url = attachment?.url || row?.mov_url;

    return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-gray-950/70 p-4 backdrop-blur-xs" role="presentation">
        <div role="dialog" aria-modal="true" aria-label="Document Preview" className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <div className="min-w-0"><h2 className="text-base font-bold text-gray-900 dark:text-white">Document Preview</h2><p className="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{attachment?.name || 'MOV / report attachment'}</p></div>
                <button type="button" onClick={onClose} className="rounded-lg px-2 py-1 text-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Close preview">×</button>
            </div>
            <div className="min-h-0 flex-1 overflow-auto p-5">
                <dl className="mb-4 grid gap-3 text-xs sm:grid-cols-4">
                    {[['Workflow', row?.module], ['Protected Area', row?.protected_area], ['Reporting Period', row?.reporting_period], ['Document Type', row?.document_type]].map(([label, value]) => <div key={label} className="min-w-0"><dt className="font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-1 truncate font-semibold text-gray-900 dark:text-white">{value || '—'}</dd></div>)}
                </dl>
                {!url ? <p className="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">No MOV/report attachment is available for this submission.</p> : isInline(attachment) ? <div className="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950/40">{String(attachment.mime_type).toLowerCase() === 'application/pdf' ? <iframe title="MOV/report preview" src={url} className="h-[55vh] w-full" /> : <img src={url} alt={attachment.name || 'MOV/report'} className="mx-auto max-h-[55vh] max-w-full object-contain" />}</div> : <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><p>This document type cannot be previewed in the browser.</p><p className="mt-1 font-semibold">Open or download the file to inspect it.</p></div>}
            </div>
            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-5 py-4 dark:border-gray-700"><button type="button" onClick={onClose} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Close Preview</button>{url && <a href={url} target="_blank" rel="noreferrer" className="rounded-xl bg-green-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-green-800">Open / Download</a>}</div>
        </div>
    </div>;
}
