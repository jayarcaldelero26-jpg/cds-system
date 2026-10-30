export function formatPeriodLabel(value) {
    if (value == null || String(value).trim() === '') return '';
    const raw = String(value).trim().toLowerCase();
    if (/^\d{4}-(0[1-9]|1[0-2])$/.test(raw)) return monthName(Number(raw.slice(-2)));
    const normalized = raw.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
    let match = normalized.match(/^(?:quarter|q)\s*(\d+)$/);
    if (match) return 'Quarter ' + match[1];
    match = normalized.match(/^(\d+)(?:st|nd|rd|th)\s+quarter$/);
    if (match) return 'Quarter ' + match[1];
    match = normalized.match(/^(?:semester|sem|s)\s*(\d+)$/);
    if (match) return 'Semester ' + match[1];
    match = normalized.match(/^(\d+)(?:st|nd|rd|th)\s+semester$/);
    if (match) return 'Semester ' + match[1];
    match = normalized.match(/^(?:month|monthly)\s*(\d{1,2})$/);
    if (match) return monthName(Number(match[1]));
    if (/^(annual|annually|year|yearly)$/.test(normalized)) return 'Annual';
    return normalized.replace(/\b\w/g, character => character.toUpperCase());
}

function monthName(month) {
    if (!Number.isInteger(month) || month < 1 || month > 12) return '';
    return new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' })
        .format(new Date(Date.UTC(2020, month - 1, 1)));
}

export function reportingPeriodLabel(identity = {}) {
    const year = identity.reporting_year;
    const rawPeriod = identity.period_label || identity.period_key || identity.reporting_period;
    const label = formatPeriodLabel(rawPeriod) || 'Period not available';
    if (year && new RegExp('\\b' + String(year) + '\\b').test(label)) return label;
    return [year, label].filter(Boolean).join(' ') || 'Period not available';
}

export function formatWorkflowLabel(value) {
    if (value == null || String(value).trim() === '') return '';
    const key = String(value).trim().toLowerCase();
    if (key === 'bdfap') return 'BDFAPs in PAs';
    return key.replace(/[_-]+/g, ' ').replace(/\b\w/g, character => character.toUpperCase());
}
