const extensionOf = file => file?.name?.split('.').pop()?.toLowerCase() || '';

export default function FilePreviewPanel({ file, title = 'Document Preview', emptyText = 'No file available', className = '' }) {
    const extension = extensionOf(file);
    const isImage = file?.type?.startsWith?.('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension);
    const isPdf = file?.type === 'application/pdf' || extension === 'pdf';
    return <aside className={`overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950/30 ${className}`}>
        <div className="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700"><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">{title}</h3>{file?.url && <a href={file.url} target="_blank" rel="noreferrer" className="text-xs font-bold text-green-700 hover:underline dark:text-green-400">Open / Fullscreen ↗</a>}</div>
        <div className="flex min-h-64 items-center justify-center overflow-hidden bg-white dark:bg-gray-950">{!file ? <div className="p-8 text-center text-sm text-gray-500"><div className="mb-3 text-4xl" aria-hidden="true">📁</div>{emptyText}</div> : isImage ? <img src={file.url} alt={file.name || 'Attachment preview'} className="max-h-[32rem] w-full object-contain" /> : isPdf ? <iframe src={file.url} title={file.name || 'PDF preview'} className="h-[32rem] w-full" /> : <div className="p-8 text-center"><div className="mb-3 text-5xl" aria-hidden="true">📄</div><p className="break-all text-sm font-semibold text-gray-800 dark:text-gray-200">{file.name}</p><p className="mt-2 text-xs text-gray-500">Inline preview is unavailable for this file type.</p></div>}</div>
        {file && <div className="border-t border-gray-200 px-4 py-3 text-xs font-semibold text-gray-700 dark:border-gray-700 dark:text-gray-200">{file.name || 'Attachment'}</div>}
    </aside>;
}
