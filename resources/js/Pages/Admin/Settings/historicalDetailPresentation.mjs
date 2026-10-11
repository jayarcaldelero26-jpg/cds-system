export function historicalSourceModuleHref(record) {
    const source = String(record?.source || '').trim();
    const sourceId = Number(record?.source_id);
    const href = record?.module_url;
    if (!source || !Number.isSafeInteger(sourceId) || sourceId <= 0 || typeof href !== 'string' || href.trim() === '') return null;
    return href;
}

export function historicalGapCard(gap = {}) {
    const startLabel = gap.start_label || 'Start date';
    const endLabel = gap.end_label || 'End date';
    const days = Number(gap.elapsed_calendar_days);
    let value = 'Missing evidence';

    if (gap.status === 'complete' && Number.isFinite(days) && days >= 0) {
        value = `${days} calendar day${days === 1 ? '' : 's'}`;
    } else if (gap.status === 'unsupported') {
        value = 'Not supported for this source';
    } else if (gap.status === 'not_applicable') {
        value = 'Not applicable';
    } else if (gap.status === 'invalid') {
        value = 'Invalid evidence';
    }

    return {
        title: `${startLabel} → ${endLabel}`,
        value,
        startDate: gap.start_date || null,
        endDate: gap.end_date || null,
        reason: typeof gap.reason === 'string' ? gap.reason : '',
    };
}
