const pad = value => String(value).padStart(2, '0');
const APPLICATION_TIMEZONE = 'Asia/Manila';

export const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
export const MINUTES = Array.from({ length: 60 }, (_, index) => index);
export const PERIODS = ['AM', 'PM'];

function applicationTimeParts(date) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: APPLICATION_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));

    return { date: `${parts.year}-${parts.month}-${parts.day}`, hour24: Number(parts.hour), minute: Number(parts.minute) };
}

function hour12(hour24) {
    return hour24 % 12 || 12;
}

/** Parse HH:mm, datetime-local, SQL datetime, or an ISO timestamp for the local UI. */
export function parseTimeValue(value) {
    const raw = String(value ?? '').trim();
    const hasDate = /^\d{4}-\d{2}-\d{2}[T ]/.test(raw);
    if (!raw) return { date: '', hasDate: false, hour24: 0, minute: 0 };

    if (/Z$|[+-]\d{2}:?\d{2}$/.test(raw)) {
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) return { ...applicationTimeParts(parsed), hasDate: true };
    }

    const match = raw.match(/^(?:(\d{4}-\d{2}-\d{2})[T ]?)?(\d{1,2}):(\d{2})/);
    if (!match) return { date: '', hasDate: false, hour24: 0, minute: 0 };
    return {
        date: match[1] || '',
        hasDate: Boolean(match[1]) || hasDate,
        hour24: Math.min(23, Math.max(0, Number(match[2]))),
        minute: Math.min(59, Math.max(0, Number(match[3]))),
    };
}

export function formatTimeDisplay(value) {
    if (typeof value === 'object' && value !== null && Object.prototype.hasOwnProperty.call(value, 'hour')) {
        return `${pad(value.hour)}:${pad(value.minute)} ${value.period}`;
    }
    const parsed = typeof value === 'object' ? value : parseTimeValue(value);
    if (!String(value ?? '').trim() && typeof value !== 'object') return '';
    return `${pad(hour12(parsed.hour24))}:${pad(parsed.minute)} ${parsed.hour24 >= 12 ? 'PM' : 'AM'}`;
}

export function serializeTimeValue({ date = '', hasDate = false, hour = 12, minute = 0, period = 'AM' }) {
    const hour12Value = Math.min(12, Math.max(1, Number(hour)));
    const hour24 = (hour12Value % 12) + (period === 'PM' ? 12 : 0);
    const time = `${pad(hour24)}:${pad(minute)}`;
    return hasDate && date ? `${date}T${time}` : time;
}

export function draftFromValue(value) {
    const parsed = parseTimeValue(value);
    return { date: parsed.date, hasDate: parsed.hasDate, hour: hour12(parsed.hour24), minute: parsed.minute, period: parsed.hour24 >= 12 ? 'PM' : 'AM' };
}

/** Initialize a temporary picker draft without changing the committed value. */
export function initialDraft(value, now = new Date()) {
    return String(value ?? '').trim() ? draftFromValue(value) : currentDraft(value, now);
}

export function currentDraft(value, now = new Date()) {
    const current = applicationTimeParts(now);
    const original = parseTimeValue(value);
    return { date: original.hasDate ? original.date : '', hasDate: original.hasDate, hour: hour12(current.hour24), minute: current.minute, period: current.hour24 >= 12 ? 'PM' : 'AM' };
}

export function localDateTimeInputValue(value) {
    const parsed = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(parsed.getTime())) return '';
    const current = applicationTimeParts(parsed);
    return `${current.date}T${pad(current.hour24)}:${pad(current.minute)}`;
}
