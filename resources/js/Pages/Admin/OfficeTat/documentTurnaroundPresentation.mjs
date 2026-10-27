const TZ = 'Asia/Manila';

export function formatWorkingDays(value, { average = false } = {}) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return 'Timing unavailable';
    const precision = average ? 100 : 1;
    const rounded = Math.round(Math.max(0, Number(value)) * precision) / precision;
    const label = average && !Number.isInteger(rounded) ? rounded.toFixed(2) : String(rounded);
    return `${label} working day${rounded === 1 ? '' : 's'}`;
}

export function formatManilaTimestamp(value) {
    if (!value) return 'Timing unavailable';
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return 'Timing unavailable';
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: TZ,
        year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true,
    }).format(date);
}

export function formatManilaDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Not available';
    const date = new Date(`${value}T12:00:00+08:00`);
    if (!Number.isFinite(date.getTime())) return 'Not available';
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: TZ,
        year: 'numeric', month: 'short', day: 'numeric',
    }).format(date);
}

export function documentAverageLabel(summary) {
    const reports = Number(summary?.reports || 0);
    const received = Number(summary?.received_count || 0);
    const samples = Number(summary?.average_supporting_documents || 0);
    if (reports === 0) return 'No processed reports';
    if (samples > 0) return formatWorkingDays(summary.average_working_days, { average: true });
    return received > 0 ? 'Timing unavailable' : 'No completed turnaround yet';
}
