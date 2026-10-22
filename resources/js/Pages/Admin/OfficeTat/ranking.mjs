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

export function rankTatRows(rows = [], evidence = 'live') {
    const fields = evidence === 'pamo_preparation' ? PAMO_PREPARATION : LIVE;
    const collator = new Intl.Collator('en', { sensitivity: 'base', numeric: true });
    const prepared = (Array.isArray(rows) ? rows : []).map(row => {
        const completed = Number(row[fields.count] || 0);
        const averageSeconds = row[fields.average] == null ? null : Number(row[fields.average]);
        const totalSeconds = row[fields.total] == null
            ? (averageSeconds == null ? null : averageSeconds * completed)
            : Number(row[fields.total]);
        const valid = completed > 0 && Number.isFinite(averageSeconds) && averageSeconds >= 0;

        return {
            ...row,
            evidence,
            completedDocuments: completed,
            averageSeconds: valid ? averageSeconds : null,
            totalSeconds: valid && Number.isFinite(totalSeconds) ? totalSeconds : null,
            pendingDocuments: fields.pending ? Number(row[fields.pending] || 0) : 0,
            missingDocuments: Number(row[fields.missing] || 0),
            invalidDocuments: fields.invalid ? Number(row[fields.invalid] || 0) : 0,
            unsupportedDocuments: fields.unsupported ? Number(row[fields.unsupported] || 0) : 0,
            notApplicableDocuments: fields.notApplicable ? Number(row[fields.notApplicable] || 0) : 0,
            tatIntervalDetails: Array.isArray(row.tat_interval_details) ? row.tat_interval_details : [],
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
