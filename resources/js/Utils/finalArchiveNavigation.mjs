const FINAL_ARCHIVE_SOURCES = new Set([
    'conservation', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws',
    'ipaf-management', 'revenue', 'engp',
]);

/** Focus one supported Submission Tracking record in the global Archives page. */
export function finalArchiveRecordHref(row) {
    const source = String(row?.source || '');
    const recordId = Number(row?.source_id);
    if (!FINAL_ARCHIVE_SOURCES.has(source) || !Number.isSafeInteger(recordId) || recordId < 1) return null;

    const query = new URLSearchParams({ source, record: String(recordId) });
    return `/submission-tracking/archives?${query.toString()}`;
}
