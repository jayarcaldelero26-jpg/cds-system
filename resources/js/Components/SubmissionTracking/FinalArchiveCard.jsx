import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import { formatManila } from '@/Utils/dateFormatters';
import { archiveFileKind, archiveFiles, archiveHasReadableFiles, archivePendingStatus, archiveReportTitle, archiveRowContext, archiveRowDate } from '@/Utils/finalArchivePresentation.mjs';
import { archiveThumbnailIdentity } from '@/Utils/archiveThumbnailLoader.mjs';
import { inlinePreviewType } from '@/Utils/protectedDocumentPreview.mjs';
import ArchiveThumbnail from './ArchiveThumbnail';

export default function FinalArchiveCard({ row, tab = 'archives', selected = false, onOpen, thumbnailScopeKey = '' }) {
    const [menuOpen, setMenuOpen] = useState(false);
    const cardRef = useRef(null);
    const menuTriggerRef = useRef(null);
    const files = archiveFiles(row);
    const primaryFile = files[0] || null;
    const title = archiveReportTitle(row);
    const originalFilename = primaryFile?.name?.trim() || '';
    const kind = archiveFileKind(primaryFile);
    const thumbnailType = inlinePreviewType(primaryFile?.mime_type);
    const thumbnailIdentity = archiveThumbnailIdentity(row, primaryFile, thumbnailScopeKey);
    const context = archiveRowContext(row);
    const rawDate = archiveRowDate(row, tab);
    const date = rawDate ? formatManila(rawDate) : null;
    const pendingStatus = tab === 'archives' ? null : archivePendingStatus(row, tab);
    const earlierDispatchCopy = Boolean(row?.earlier_dispatch_copy || row?.manifest?.status === 'EARLIER_DISPATCH_COPY');
    const statusTone = pendingStatus?.state === 'processing'
        ? 'border-sky-200 bg-sky-50 text-sky-800'
        : pendingStatus?.state === 'failed'
            ? 'border-rose-200 bg-rose-50 text-rose-800'
            : 'border-amber-200 bg-amber-50 text-amber-800';
    const readableFiles = archiveHasReadableFiles(row);
    const previewAvailable = readableFiles && files.some(file => Boolean(file?.preview_url));
    const singleDownload = readableFiles && files.length === 1 && files[0]?.download_url ? files[0] : null;

    useEffect(() => {
        if (!menuOpen) return undefined;
        const closeOnEscape = event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopPropagation();
            setMenuOpen(false);
            requestAnimationFrame(() => menuTriggerRef.current?.focus());
        };
        const closeOutside = event => {
            if (!cardRef.current?.contains(event.target)) setMenuOpen(false);
        };
        document.addEventListener('keydown', closeOnEscape);
        document.addEventListener('pointerdown', closeOutside, true);
        return () => {
            document.removeEventListener('keydown', closeOnEscape);
            document.removeEventListener('pointerdown', closeOutside, true);
        };
    }, [menuOpen]);

    const open = (view = 'document') => {
        setMenuOpen(false);
        if (menuOpen) menuTriggerRef.current?.focus();
        onOpen?.(row, view);
    };

    return <article ref={cardRef} data-final-archive-card={row.id} aria-current={selected ? 'true' : undefined} className={`group relative w-full min-w-0 rounded-2xl border p-2 transition-colors sm:max-w-[280px] ${selected
        ? 'border-emerald-500 bg-emerald-50/60 ring-2 ring-emerald-600/20 dark:border-emerald-500 dark:bg-emerald-950/30'
        : 'border-slate-200 bg-slate-50/70 hover:border-emerald-300 hover:bg-emerald-50/30 dark:border-slate-700 dark:bg-slate-900/70 dark:hover:border-emerald-700'}`}>
        <button type="button" onClick={() => open('document')} aria-label={`Open ${title}${context ? `, ${context}` : ''}${files.length > 1 ? `, ${files.length} files` : ''}${earlierDispatchCopy ? ', earlier Dispatch copy' : ''}${pendingStatus ? `, ${pendingStatus.label}` : ''}`} className="block w-full rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900">
            <span className="flex min-h-10 items-center gap-2 px-2 pr-11">
                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ${kind.tone}`} aria-hidden="true"><Icon icon={kind.icon} width="16" height="16" /></span>
                <span className="min-w-0 flex-1 truncate text-xs font-semibold text-slate-800 dark:text-slate-100" title={title}>{title}</span>
                <span className="sr-only">{kind.label}</span>
            </span>
            <ArchiveThumbnail identity={thumbnailIdentity} scopeKey={thumbnailScopeKey} url={primaryFile?.preview_url} type={thumbnailType} filename={originalFilename} kind={kind} />
            <span className="block min-h-[66px] px-2 pb-1 pt-3">
                {originalFilename && <span className="block truncate text-[11px] font-medium text-slate-700 dark:text-slate-200" title={originalFilename}>File: {originalFilename}</span>}
                {context && <span className="mt-1 block truncate text-[11px] text-slate-500 dark:text-slate-400" title={context}>{context}</span>}
                <span className="mt-1 flex min-h-5 flex-wrap items-center gap-1.5">
                    {date && <span className="text-[11px] text-slate-500 dark:text-slate-400">{tab === 'archives' ? 'Archived' : tab === 'receipt' ? 'Released' : 'Received'} {date}</span>}
                    {files.length > 1 && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{files.length} files</span>}
                    {earlierDispatchCopy && <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">Earlier Dispatch copy</span>}
                    {pendingStatus && <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold ${statusTone}`}>{pendingStatus.label}</span>}
                </span>
            </span>
        </button>

        <button ref={menuTriggerRef} type="button" aria-label={`Actions for ${title}`} aria-haspopup="menu" aria-expanded={menuOpen} onClick={event => { event.stopPropagation(); setMenuOpen(value => !value); }} className="absolute right-3 top-3 z-10 inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-white hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
            <Icon icon="lucide:ellipsis-vertical" width="17" height="17" aria-hidden="true" />
        </button>
        {menuOpen && <div role="menu" aria-label={`Actions for ${title}`} className="absolute right-3 top-12 z-30 min-w-40 overflow-hidden rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900">
            <button type="button" role="menuitem" onClick={() => open('details')} className="block w-full rounded-lg px-3 py-2 text-left text-xs font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-600 dark:text-slate-200 dark:hover:bg-slate-800">Details</button>
            {previewAvailable && <button type="button" role="menuitem" onClick={() => open('document')} className="block w-full rounded-lg px-3 py-2 text-left text-xs font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-600 dark:text-slate-200 dark:hover:bg-slate-800">{earlierDispatchCopy ? 'Open earlier copy' : 'Preview'}</button>}
            {singleDownload && <a role="menuitem" href={singleDownload.download_url} download={singleDownload.name || undefined} onClick={() => { setMenuOpen(false); requestAnimationFrame(() => menuTriggerRef.current?.focus()); }} className="block rounded-lg px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-600 dark:text-slate-200 dark:hover:bg-slate-800">Download</a>}
        </div>}
    </article>;
}
