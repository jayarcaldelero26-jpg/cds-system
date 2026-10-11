const UNRATED_PENDING_VALUES = new Set([
    'pending submission by cenro',
    'pending receipt by penro',
]);

export function presentTimeliness(value, { pendingAsUnrated = false } = {}) {
    const normalized = String(value ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
    const unratedPending = pendingAsUnrated && UNRATED_PENDING_VALUES.has(normalized);

    return {
        label: unratedPending
            ? 'Not yet rated'
            : value === null || value === undefined || String(value).trim() === ''
                ? 'No Rating'
                : value,
        unratedPending,
    };
}
