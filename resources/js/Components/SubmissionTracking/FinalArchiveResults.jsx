import { useEffect } from 'react';
import CrudTable from '@/Components/Crud/CrudTable';
import FinalArchiveCard from './FinalArchiveCard';
import { archiveThumbnailLoader } from '@/Utils/archiveThumbnailRuntime.mjs';
import { archiveReportTitle, archiveRowContext, archiveRowIdentity, archivePendingStatus } from '@/Utils/finalArchivePresentation.mjs';

function rowAriaLabel(row, tab, selectedIdentity) {
    const status = tab === 'archives'
        ? row.earlier_dispatch_copy ? 'earlier Dispatch copy' : 'final signed Regional return'
        : archivePendingStatus(row, tab).label;
    return [archiveReportTitle(row), archiveRowContext(row) || 'report', status,
        selectedIdentity === archiveRowIdentity(row) ? 'selected record' : null].filter(Boolean).join(', ');
}

export default function FinalArchiveResults({
    rows = [],
    displayMode = 'grid',
    tab = 'archives',
    selectedIdentity = '',
    thumbnailScopeKey = '',
    columns = [],
    onOpen,
}) {
    const safeRows = Array.isArray(rows) ? rows : [];
    useEffect(() => archiveThumbnailLoader.activateScope(thumbnailScopeKey), [thumbnailScopeKey]);
    if (displayMode === 'grid') return <div data-archive-display="grid" data-archive-result-count={safeRows.length} className="grid grid-cols-1 justify-items-center gap-4 sm:grid-cols-[repeat(auto-fill,minmax(230px,1fr))] sm:justify-items-start" aria-label={tab === 'archives' ? 'Archives' : tab === 'awaiting' ? 'Documents awaiting archive' : 'Reports awaiting final receipt'}>
        {safeRows.map(row => <FinalArchiveCard key={row.id} row={row} tab={tab} selected={selectedIdentity === archiveRowIdentity(row)} onOpen={onOpen} thumbnailScopeKey={thumbnailScopeKey} />)}
    </div>;

    return <div data-archive-display="list" data-archive-result-count={safeRows.length}>
        <CrudTable
            compact
            className="cds-card-surface"
            caption="Final archives and earlier Dispatch copies"
            columns={columns}
            rows={safeRows}
            rowKey="id"
            rowAriaLabel={row => rowAriaLabel(row, tab, selectedIdentity)}
            rowFocusVisibleOnly
            selectedKeys={safeRows.filter(row => selectedIdentity === archiveRowIdentity(row)).map(row => row.id)}
            onRowClick={row => onOpen?.(row, 'document')}
        />
    </div>;
}
