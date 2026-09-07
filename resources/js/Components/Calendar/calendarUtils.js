const DATE_ONLY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})/;

export function createDateOnly(year, monthIndex, day = 1) {
    const date = new Date(2000, 0, 1, 12, 0, 0, 0);
    date.setFullYear(Number(year), Number(monthIndex), Number(day));
    return date;
}

export function parseDateOnly(value) {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : createDateOnly(value.getFullYear(), value.getMonth(), value.getDate());
    }
    const match = String(value ?? '').match(DATE_ONLY_PATTERN);
    if (!match) return null;
    const date = createDateOnly(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return date.getFullYear() === Number(match[1]) && date.getMonth() === Number(match[2]) - 1 && date.getDate() === Number(match[3]) ? date : null;
}

export function formatDateKey(value) {
    const date = parseDateOnly(value);
    if (!date) return '';
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function todayDate() {
    const now = new Date();
    return createDateOnly(now.getFullYear(), now.getMonth(), now.getDate());
}

export function monthStart(value) {
    const date = parseDateOnly(value) || todayDate();
    return createDateOnly(date.getFullYear(), date.getMonth(), 1);
}

export function addMonths(value, amount) {
    const date = monthStart(value);
    date.setMonth(date.getMonth() + Number(amount));
    return createDateOnly(date.getFullYear(), date.getMonth(), 1);
}

export function monthLabel(value) {
    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(monthStart(value));
}

export function formatDisplayDate(value) {
    const date = parseDateOnly(value);
    return date ? new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric' }).format(date) : '';
}

export function calendarDays(value) {
    const first = monthStart(value);
    const start = createDateOnly(first.getFullYear(), first.getMonth(), 1 - first.getDay());
    return Array.from({ length: 42 }, (_, index) => {
        const date = createDateOnly(start.getFullYear(), start.getMonth(), start.getDate() + index);
        return { date, key: formatDateKey(date) };
    });
}

export function isDateDisabled(value, minDate, maxDate) {
    const key = formatDateKey(value);
    const min = formatDateKey(minDate);
    const max = formatDateKey(maxDate);
    return Boolean((min && key < min) || (max && key > max));
}

export function isSameDate(first, second) {
    return Boolean(formatDateKey(first) && formatDateKey(first) === formatDateKey(second));
}
