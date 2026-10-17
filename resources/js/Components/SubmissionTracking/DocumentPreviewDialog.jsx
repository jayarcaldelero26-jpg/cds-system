import { lazy, Suspense, useEffect, useState } from 'react';
import { inlinePreviewType, startProtectedPreview } from '@/Utils/protectedDocumentPreview.mjs';
import SubmissionTrackingReaderDialog from '@/Components/SubmissionTracking/SubmissionTrackingReaderDialog';
import { formatManila } from '@/Utils/dateFormatters';

const PdfDocumentViewer = lazy(() => import('@/Components/SubmissionTracking/PdfDocumentViewer'));
const documentTab = [{ value: 'document', label: 'Document' }];

function meaningfulFields(fields) {
    return fields.filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '');
}

function historyLabel(event) {
    return event?.label || event?.event_label || event?.action_label || event?.event || '';
}

function historyTime(event) {
    const value = event?.occurred_at || event?.recorded_at || event?.created_at;
    if (!value) return '';
    try { return formatManila(value); } catch { return String(value); }
}

export default function DocumentPreviewDialog({
    open,
    row,
    accountKey = null,
    onClose,
    attachment: attachmentOverride = null,
    previewAllowed = null,
    downloadAllowed = null,
    contextLabel = null,
    contextDetails = [],
    presentation = 'contextual',
}) {
    const [preview, setPreview] = useState(null);
    const [retry, setRetry] = useState(0);
    const [activeTab, setActiveTab] = useState('document');
    const [expanded, setExpanded] = useState(false);
    const attachment = attachmentOverride || row?.current_document;
    const canPreview = previewAllowed === null
        ? attachment?.can_preview === true
        : Boolean(previewAllowed);
    const url = attachment?.preview_url || attachment?.url;
    const downloadUrl = attachment?.download_url || attachment?.url || url;
    const canDownload = downloadAllowed === null
        ? canPreview && Boolean(downloadUrl)
        : Boolean(downloadAllowed && downloadUrl);
    const mimeType = String(attachment?.mime_type || attachment?.type || '').toLowerCase();
    const inline = Boolean(inlinePreviewType(mimeType));
    const showContextTabs = presentation !== 'document-only';
    const requestKey = JSON.stringify([
        accountKey,
        row?.source,
        row?.source_id ?? row?.id,
        attachment?.source,
        attachment?.source_id,
        attachment?.id,
        url,
        downloadUrl,
        attachment?.name,
        attachment?.size,
        mimeType,
        attachment?.uploaded_at,
        retry,
    ]);

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
        setActiveTab('document');
        setExpanded(false);
    }, [requestKey, presentation]);

    const current = preview?.requestKey === requestKey ? preview : null;
    const previewReady = current?.status === 'ready';
    const previewError = current?.status === 'error';
    const title = attachment?.name || 'MOV / report attachment';
    const context = contextLabel === null
        ? [row?.module, row?.protected_area, row?.reporting_period].filter(Boolean).join(' · ')
        : contextLabel;
    const details = showContextTabs ? meaningfulFields([
        ['Workflow', row?.module],
        ['Protected Area', row?.protected_area],
        ['Reporting Period', row?.reporting_period],
        ['Document Type', row?.document_type],
        ['Source', attachment?.source],
        ['Uploaded', attachment?.uploaded_at ? historyTime({ occurred_at: attachment.uploaded_at }) : null],
        ...contextDetails,
    ]) : [];
    const historyEvents = showContextTabs && row?.routing?.routing_history?.length
        ? row.routing.routing_history
        : showContextTabs && row?.routing_timeline?.length
            ? row.routing_timeline
            : showContextTabs ? row?.mov_processing?.review_history || [] : [];
    const tabs = [
        ...documentTab,
        ...(details.length ? [{ value: 'details', label: 'Details' }] : []),
        ...(historyEvents.length ? [{ value: 'history', label: 'History' }] : []),
    ];

    const documentPanel = <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        {!canPreview ? <p role="alert" className="m-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">Your current account is not authorized to access this document.</p>
            : !url ? <p className="m-4 rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">No MOV/report attachment is available for this submission.</p>
                : inline ? previewReady ? current.type === 'application/pdf'
                    ? <Suspense fallback={<p role="status" className="m-auto text-sm text-gray-500 dark:text-gray-400">Preparing PDF viewer…</p>}>
                        <PdfDocumentViewer
                            blob={current.blob}
                            title={title}
                            className="h-full w-full"
                            archiveReader
                            initialFitMode="page"
                            downloadUrl={canDownload ? downloadUrl : null}
                            downloadName={title}
                            layoutKey={`${requestKey}:${showContextTabs ? activeTab : 'document'}:${expanded}`}
                        />
                    </Suspense>
                    : <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-slate-50 dark:bg-slate-950">
                        {canDownload && <div className="flex shrink-0 justify-end border-b border-slate-200 bg-white px-3 py-1.5 dark:border-slate-700 dark:bg-slate-900"><a href={downloadUrl} download={title} className="rounded-md border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Download</a></div>}
                        <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto p-4"><img src={current.url} alt={title} className="max-h-full max-w-full object-contain" onError={() => setPreview({ ...current, status: 'error', error: { message: 'The image could not be displayed. Retry the preview.' } })} /></div>
                    </div>
                    : previewError ? <div role="alert" className="m-4 rounded-xl border border-gray-200 px-4 py-10 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300"><p>{current.error.message}</p><button type="button" onClick={() => setRetry(value => value + 1)} className="mt-3 rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Retry Preview</button></div>
                        : <p role="status" className="m-4 rounded-xl border border-gray-200 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Loading document preview…</p>
                    : <div className="m-4 flex flex-col items-center justify-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-8 text-center text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"><p>{mimeType ? 'This document type cannot be previewed in the browser.' : 'Preview is unavailable because the file type could not be verified.'}</p><p className="font-semibold">Open or download the file to inspect it.</p>{canDownload && <a href={downloadUrl} download={title} className="mt-1 inline-flex rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-amber-800 dark:bg-slate-900 dark:text-amber-200">Download current copy</a>}</div>}
    </div>;

    const detailsPanel = <dl className="grid min-h-0 flex-1 grid-cols-1 content-start divide-y divide-slate-100 overflow-y-auto px-4 py-3 sm:grid-cols-2 sm:gap-x-8 dark:divide-slate-800">
        {details.map(([label, value], index) => <div key={`${label}-${index}`} className="min-w-0 py-2.5"><dt className="text-[11px] font-medium text-slate-500 dark:text-slate-400">{label}</dt><dd className="mt-0.5 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{value}</dd></div>)}
    </dl>;
    const historyPanel = <ol className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6">
        {historyEvents.map((event, index) => {
            const label = historyLabel(event);
            const actor = event?.actor || event?.recorded_by;
            const office = event?.office || event?.actor_office || event?.recorded_office;
            const detailsText = event?.details || event?.remarks || event?.correction_detail;
            return <li key={`${event?.key || event?.id || label || 'history'}-${index}`} className="border-l-2 border-emerald-300 pl-3">
                {label && <p className="text-sm font-semibold text-slate-900 dark:text-white">{label}</p>}
                <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">{[historyTime(event), actor, office].filter(Boolean).join(' · ')}</p>
                {detailsText && <p className="mt-1 whitespace-pre-wrap text-xs leading-5 text-slate-500 dark:text-slate-400">{detailsText}</p>}
            </li>;
        })}
    </ol>;

    return <SubmissionTrackingReaderDialog
        open={open}
        title={title}
        context={context}
        tabs={tabs}
        activeTab={tabs.some(tab => tab.value === activeTab) ? activeTab : 'document'}
        onTabChange={setActiveTab}
        panels={{ document: documentPanel, details: detailsPanel, history: historyPanel }}
        expanded={expanded}
        onExpandedChange={setExpanded}
        onClose={onClose}
        idPrefix="tracking-reader"
        initialFocusSelector="[data-preview-close]"
    />;
}
