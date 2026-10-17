export function archiveFiles(row) {
    return Array.isArray(row?.manifest?.files) ? row.manifest.files : [];
}

export function archiveHasVerifiedFiles(row) {
    const files = archiveFiles(row);
    return row?.manifest?.status === 'ARCHIVED'
        && files.length > 0
        && files.every(file => file.status === 'ARCHIVED' && file.preview_url && file.download_url);
}

export function archiveHasReadableFiles(row) {
    if (archiveHasVerifiedFiles(row)) return true;
    if (!row?.earlier_dispatch_copy && row?.manifest?.status !== 'EARLIER_DISPATCH_COPY') return false;
    const files = archiveFiles(row);
    return files.length > 0 && files.every(file => file.status === 'ARCHIVED' && file.preview_url && file.download_url);
}

export function archiveFileTitle(row) {
    return archiveFiles(row)[0]?.name?.trim() || row?.title?.trim() || row?.module || 'Report document';
}

export function archiveRowReport(row) {
    const module = String(row?.module || '').trim();
    const title = String(row?.title || '').trim();
    const distinctTitle = title && title.toLocaleLowerCase() !== module.toLocaleLowerCase() ? title : null;
    return [module, distinctTitle].filter(Boolean).join(' · ');
}

export function archiveReportTitle(row) {
    const report = archiveRowReport(row);
    if (report) return report;

    const source = String(row?.source || '').trim().replace(/[-_]+/g, ' ');
    const sourceLabel = source ? source.charAt(0).toLocaleUpperCase() + source.slice(1) : '';
    const sourceId = row?.source_id;
    if (sourceLabel && sourceId !== null && sourceId !== undefined && String(sourceId).trim() !== '') {
        return sourceLabel + ' report #' + sourceId;
    }

    return sourceLabel ? sourceLabel + ' report' : 'Report document';
}

export function archiveRowContext(row) {
    return [row?.protected_area || row?.office].filter(Boolean).join(' · ');
}

export function archiveFileKind(file) {
    const mime = String(file?.mime_type || '').toLowerCase();
    const extension = String(file?.name || '').split('.').pop()?.toLowerCase();
    if (mime === 'application/pdf' || extension === 'pdf') return { label: 'PDF', icon: 'lucide:file-text', tone: 'text-rose-700 bg-rose-50' };
    if (mime.includes('word') || ['doc', 'docx', 'odt'].includes(extension)) return { label: 'Word', icon: 'lucide:file-type-2', tone: 'text-blue-700 bg-blue-50' };
    if (mime.startsWith('image/')) return { label: 'Image', icon: 'lucide:image', tone: 'text-violet-700 bg-violet-50' };
    return { label: 'File', icon: 'lucide:file', tone: 'text-emerald-800 bg-emerald-50' };
}

export function archivePendingStatus(row, tab) {
    if (tab === 'receipt') return { label: 'Awaiting Final Receipt', state: 'pending' };
    const state = String(row?.manifest?.status || '').toUpperCase();
    if (state === 'PROCESSING') return row.manifest?.retry_available
        ? { label: 'Ready to retry', state: 'retry' }
        : { label: 'Processing', state: 'processing' };
    if (state === 'FAILED') return { label: 'Failed', state: 'failed' };
    if (state === 'PENDING') return { label: 'Awaiting Archive', state: 'pending' };
    if (state === 'ARCHIVED') return { label: 'Verification pending', state: 'pending' };
    return { label: 'Awaiting Archive', state: 'pending' };
}

export function archiveRowDate(row, tab) {
    if (tab === 'receipt') return row?.release_occurred_at || null;
    if (tab === 'awaiting') return row?.receipt?.physically_received_at || null;
    return row?.manifest?.finalized_at || row?.manifest?.archived_at || null;
}

export function archiveRowIdentity(row) {
    return row ? `${row.source}:${row.source_id}` : '';
}
