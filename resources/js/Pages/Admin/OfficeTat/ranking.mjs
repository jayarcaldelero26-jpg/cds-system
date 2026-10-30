const LIVE = {
    average: 'tat_average_elapsed_seconds',
    count: 'event_derived_tat_completed_documents',
    total: 'tat_elapsed_seconds_total',
    pending: 'pending',
    missing: 'tat_missing_evidence',
};

const PAMO_PREPARATION = {
    average: 'average_elapsed_seconds',
    count: 'completed_documents',
    total: 'elapsed_seconds_total',
    pending: 'pending_documents',
    missing: 'missing_evidence',
};

const HISTORICAL_GAPS = {
    preparation: {
        average: 'manual_historical_preparation_average_elapsed_calendar_days',
        count: 'manual_historical_preparation_completed_documents',
        total: 'manual_historical_preparation_elapsed_calendar_days_total',
        missing: 'manual_historical_preparation_missing_evidence',
        invalid: 'manual_historical_preparation_invalid_evidence',
        unsupported: 'manual_historical_preparation_unsupported',
        notApplicable: 'manual_historical_preparation_not_applicable',
    },
    processing: {
        average: 'manual_historical_processing_average_elapsed_calendar_days',
        count: 'manual_historical_processing_completed_documents',
        total: 'manual_historical_processing_elapsed_calendar_days_total',
        missing: 'manual_historical_processing_missing_evidence',
        invalid: 'manual_historical_processing_invalid_evidence',
        unsupported: 'manual_historical_processing_unsupported',
        notApplicable: 'manual_historical_processing_not_applicable',
    },
    handoff: {
        average: 'manual_historical_handoff_average_elapsed_calendar_days',
        count: 'manual_historical_handoff_completed_documents',
        total: 'manual_historical_handoff_elapsed_calendar_days_total',
        missing: 'manual_historical_handoff_missing_evidence',
        invalid: 'manual_historical_handoff_invalid_evidence',
        unsupported: 'manual_historical_handoff_unsupported',
        notApplicable: 'manual_historical_handoff_not_applicable',
    },
};

export function rankTatRows(rows = [], evidence = 'live', historicalBasis = 'handoff') {
    const fields = evidence === 'historical'
        ? (HISTORICAL_GAPS[historicalBasis] || HISTORICAL_GAPS.handoff)
        : evidence === 'pamo_preparation' ? PAMO_PREPARATION : LIVE;
    const collator = new Intl.Collator('en', { sensitivity: 'base', numeric: true });
    const prepared = (Array.isArray(rows) ? rows : []).map(row => {
        const completed = Number(row[fields.count] || 0);
        const unitFactor = evidence === 'historical' ? 86400 : 1;
        const averageSeconds = row[fields.average] == null ? null : Number(row[fields.average]) * unitFactor;
        const totalSeconds = row[fields.total] == null
            ? (averageSeconds == null ? null : averageSeconds * completed)
            : Number(row[fields.total]) * unitFactor;
        const valid = completed > 0 && Number.isFinite(averageSeconds) && averageSeconds >= 0;

        return {
            ...row,
            evidence,
            completedDocuments: completed,
            historicalReports: Number(row.historical_report_count || 0),
            averageSeconds: valid ? averageSeconds : null,
            totalSeconds: valid && Number.isFinite(totalSeconds) ? totalSeconds : null,
            pendingDocuments: fields.pending ? Number(row[fields.pending] || 0) : 0,
            missingDocuments: Number(row[fields.missing] || 0),
            invalidDocuments: fields.invalid ? Number(row[fields.invalid] || 0) : 0,
            unsupportedDocuments: fields.unsupported ? Number(row[fields.unsupported] || 0) : 0,
            notApplicableDocuments: fields.notApplicable ? Number(row[fields.notApplicable] || 0) : 0,
            rank: null,
        };
    }).sort((left, right) => {
        if (left.averageSeconds == null && right.averageSeconds != null) return 1;
        if (left.averageSeconds != null && right.averageSeconds == null) return -1;
        if (left.averageSeconds != null && right.averageSeconds != null && left.averageSeconds !== right.averageSeconds) {
            return left.averageSeconds - right.averageSeconds;
        }
        return collator.compare(String(left.office || ''), String(right.office || ''));
    });

    let previousSeconds = null;
    let previousRank = null;
    prepared.forEach((row, index) => {
        if (row.averageSeconds == null) return;
        row.rank = previousSeconds != null && row.averageSeconds === previousSeconds
            ? previousRank
            : index + 1;
        previousSeconds = row.averageSeconds;
        previousRank = row.rank;
    });

    return prepared;
}

export function weightedAverageSeconds(rows = []) {
    let totalSeconds = 0;
    let completedDocuments = 0;
    for (const row of rows) {
        if (row.averageSeconds == null || row.completedDocuments <= 0) continue;
        const rowTotal = Number.isFinite(row.totalSeconds)
            ? row.totalSeconds
            : row.averageSeconds * row.completedDocuments;
        totalSeconds += rowTotal;
        completedDocuments += row.completedDocuments;
    }

    return completedDocuments > 0 ? totalSeconds / completedDocuments : null;
}

export function formatDuration(seconds) {
    if (seconds == null || seconds === '') return 'No completed office handoffs';
    const numeric = Number(seconds);
    if (!Number.isFinite(numeric) || numeric < 0) return 'No data';

    let remainingCentiseconds = Math.round(numeric * 100);
    const dayCentiseconds = 86400 * 100;
    const hourCentiseconds = 3600 * 100;
    const minuteCentiseconds = 60 * 100;
    const days = Math.floor(remainingCentiseconds / dayCentiseconds);
    remainingCentiseconds %= dayCentiseconds;
    const hours = Math.floor(remainingCentiseconds / hourCentiseconds);
    remainingCentiseconds %= hourCentiseconds;
    const minutes = Math.floor(remainingCentiseconds / minuteCentiseconds);
    remainingCentiseconds %= minuteCentiseconds;
    const secs = remainingCentiseconds / 100;
    const parts = [];

    if (days) parts.push(`${days} ${days === 1 ? 'day' : 'days'}`);
    if (hours) parts.push(`${hours} ${hours === 1 ? 'hour' : 'hours'}`);
    if (minutes) parts.push(`${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`);
    if (secs || parts.length === 0) {
        const displayedSeconds = Number.isInteger(secs) ? String(secs) : secs.toFixed(2).replace(/0+$/, '').replace(/[.]$/, '');
        parts.push(`${displayedSeconds} ${secs === 1 ? 'second' : 'seconds'}`);
    }

    return parts.join(' ');
}
