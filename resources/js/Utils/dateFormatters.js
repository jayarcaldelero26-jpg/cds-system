const REPORT_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/;

export function formatReportDate(value, fallback = '—') {
    if (!value) return fallback;

    const match = String(value).match(REPORT_DATE_PATTERN);
    if (!match) return fallback;

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(year, month - 1, day);

    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return fallback;

    return new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric' }).format(date);
}

export function formatReportValue(value, fallback = '—') {
    if (value === null || value === undefined || value === '') return fallback;
    return REPORT_DATE_PATTERN.test(String(value)) ? formatReportDate(value, fallback) : value;
}
