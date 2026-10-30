export function isValidLiveAnalytics(data, filters = {}) {
    if (!data || typeof data !== 'object' || data.category !== 'CENRO' || data.evidence_type !== 'live' || !Array.isArray(data.rows)) return false;
    if (data.processing_period !== filters.processing_period || data.as_of_date !== filters.as_of_date) return false;
    return data.rows.every(row => row && typeof row.office === 'string'
        && ['documents_received', 'tat_completed_documents', 'tat_elapsed_seconds_total', 'pending', 'tat_missing_evidence'].every(key => isNumeric(row[key]))
        && (row.tat_average_elapsed_seconds === null || isNumeric(row.tat_average_elapsed_seconds)));
}

function isNumeric(value) {
    return (typeof value === 'number' || (typeof value === 'string' && value.trim() !== '')) && Number.isFinite(Number(value));
}
