import { lazy, Suspense, useEffect, useState } from 'react';
import Tooltip from '@/Components/Tooltip';
import { inlinePreviewType, startProtectedPreview } from '@/Utils/protectedDocumentPreview.mjs';
import { useReportDetails } from './ReportDetailsContext';
import { useContext } from 'react';
import CrudModalCardContext from './CrudModalCardContext';

const PdfDocumentViewer = lazy(() => import('@/Components/SubmissionTracking/PdfDocumentViewer'));

const extensionOf = file => file?.name?.split('.').pop()?.toLowerCase() || '';

export default function FilePreviewPanel({ file, title = 'Live Document Preview', emptyText = 'No file selected for preview', className = '', heightClass = 'h-[650px]', hideHeader = false }) {
    const reportDetails = useReportDetails();
    const insideReportDetailsCard = useContext(CrudModalCardContext) && Boolean(reportDetails);
    if (reportDetails) {
        title = 'MOV / Attachment';
        hideHeader = true;
    }
    const extension = extensionOf(file);
    const fileUrl = file?.url || null;
    const isImage = Boolean(fileUrl) && (file?.type?.startsWith?.('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension));
    const isPdf = Boolean(fileUrl) && (file?.type === 'application/pdf' || extension === 'pdf');
    const [localPdfBlob, setLocalPdfBlob] = useState(null);
    useEffect(() => {
        let active = true;
        setLocalPdfBlob(null);
        if (!isPdf || !fileUrl?.startsWith('blob:')) return () => { active = false; };
        fetch(fileUrl).then(response => response.blob()).then(blob => { if (active) setLocalPdfBlob(blob); }).catch(() => {});
        return () => { active = false; };
    }, [fileUrl, isPdf]);

    if (file?.protected) {
        return <ProtectedAttachmentPreview file={file} title={title} className={className} heightClass={heightClass} hideHeader={hideHeader} />;
    }

    return <aside className={`${insideReportDetailsCard ? '' : 'cds-card-surface '}overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 ${className}`}>
        {!hideHeader && <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div className="min-w-0"><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">{title}</h3>{file && <Tooltip content={file.name} className="block max-w-full"><p className="mt-1 truncate text-xs font-medium text-gray-600 dark:text-gray-300">{file.name}</p></Tooltip>}</div>{fileUrl && <a href={fileUrl} target="_blank" rel="noopener noreferrer" className="whitespace-nowrap text-xs font-semibold text-green-700 hover:underline dark:text-green-400">Fullscreen</a>}</div>}
        <div className={`flex w-full items-center justify-center overflow-hidden bg-gray-100 dark:bg-gray-950 ${heightClass}`}>{!file ? <div className="p-8 text-center"><h4 className="text-sm font-semibold text-gray-800 dark:text-white">{emptyText}</h4><p className="mt-1 max-w-xs text-xs text-gray-500 dark:text-gray-400">Choose a PDF, image, or document attachment to view it here.</p></div> : isImage ? <img src={fileUrl} alt={file.name || 'Attachment preview'} className="max-h-full w-full object-contain" /> : isPdf && fileUrl.startsWith('blob:') ? localPdfBlob ? <Suspense fallback={<p role="status" className="m-auto text-sm text-gray-500 dark:text-gray-400">Preparing PDF viewer…</p>}><PdfDocumentViewer blob={localPdfBlob} title={file.name || 'PDF document'} className="h-full w-full" /></Suspense> : <p role="status" className="text-sm text-gray-500 dark:text-gray-400">Preparing PDF preview…</p> : isPdf ? <iframe src={fileUrl} title={file.name || 'PDF preview'} className="h-full w-full bg-white" /> : <div className="p-8 text-center"><p className="break-all text-sm font-semibold text-gray-800 dark:text-gray-200">{file.name}</p><p className="mt-2 text-xs text-gray-500">{fileUrl ? 'Preview is not available for this file type.' : 'No preview URL is available for this attachment.'}</p>{fileUrl && <a href={fileUrl} target="_blank" rel="noopener noreferrer" data-cds-action="true" data-cds-action-variant="primary" className="mt-4 inline-flex rounded-xl px-4 py-2 text-xs font-bold">Open Document</a>}</div>}</div>
    </aside>;
}

function ProtectedAttachmentPreview({ file, title, className, heightClass, hideHeader }) {
    const [state, setState] = useState(null);
    const [imageUrl, setImageUrl] = useState(null);
    const url = file?.preview_url || file?.url;
    const mime = file?.mime_type || file?.type;
    useEffect(() => {
        if (!url || !inlinePreviewType(mime)) { setState({ status: 'unsupported', error: { message: 'This document type cannot be previewed in the browser.' } }); return undefined; }
        const task = startProtectedPreview(url, { onState: setState });
        return task.dispose;
    }, [url, mime]);
    useEffect(() => {
        if (state?.status !== 'ready' || state.type === 'application/pdf') { setImageUrl(null); return undefined; }
        const objectUrl = URL.createObjectURL(state.blob);
        setImageUrl(objectUrl);
        return () => URL.revokeObjectURL(objectUrl);
    }, [state]);
    const embedded = state?.status === 'ready' && state.type === 'application/pdf'
        ? <Suspense fallback={<p role="status" className="m-auto text-sm text-gray-500">Preparing PDF preview…</p>}><PdfDocumentViewer blob={state.blob} title={file.name || 'PDF document'} className="h-full w-full" /></Suspense>
        : state?.status === 'ready' && imageUrl
            ? <img src={imageUrl} alt={file.name || 'Attachment preview'} className="max-h-full w-full object-contain" />
            : state?.status === 'error'
                ? <p role="alert" className="p-8 text-center text-sm text-gray-600 dark:text-gray-300">{state.error.message}</p>
                : state?.status === 'unsupported'
                    ? <div className="p-8 text-center"><p className="text-sm text-gray-600 dark:text-gray-300">{state.error.message}</p>{file.download_url && <a href={file.download_url} className="mt-3 inline-flex rounded-xl px-4 py-2.5 text-xs font-bold text-green-700 hover:underline" data-cds-action="true" data-cds-action-variant="primary">Open Document</a>}</div>
                    : <p role="status" className="text-sm text-gray-500 dark:text-gray-400">Loading protected preview…</p>;
    return <aside className={`${className} overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900`}>
        {!hideHeader && <div className="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">{title}</h3><p className="mt-1 truncate text-xs font-medium text-gray-600 dark:text-gray-300">{file.name}</p></div>}
        <div className={`flex w-full items-center justify-center overflow-hidden bg-gray-100 dark:bg-gray-950 ${heightClass}`}>{embedded}</div>
    </aside>;
}
