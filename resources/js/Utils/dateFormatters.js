const REPORT_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/;

/** Parse a persisted YYYY-MM-DD value without invoking UTC date parsing. */
export function parseDateOnly(value) {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : new Date(value.getTime());
    }

    const match = String(value ?? '').match(REPORT_DATE_PATTERN);
    if (!match) return null;

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(0);
    date.setHours(12, 0, 0, 0);
    date.setFullYear(year, month - 1, day);

    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
        ? date
        : null;
}

export function dateOnlyTimestamp(value) {
    return parseDateOnly(value)?.getTime() ?? Number.NaN;
}

export function formatReportDate(value, fallback = '—') {
    if (!value) return fallback;

    const date = parseDateOnly(value);
    if (!date) return fallback;

    return new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' }).format(date);
}

export function formatReportValue(value, fallback = '—') {
    if (value === null || value === undefined || value === '') return fallback;
    return REPORT_DATE_PATTERN.test(String(value)) ? formatReportDate(value, fallback) : value;
}

export function formatReportDateTime(value, fallback = 'â€”') {
    if (!value) return fallback;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;

    const datePart = new Intl.DateTimeFormat('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'Asia/Manila',
    }).format(date);
    const timePart = new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        timeZone: 'Asia/Manila',
    }).format(date);

    return datePart + ', ' + timePart;
}
