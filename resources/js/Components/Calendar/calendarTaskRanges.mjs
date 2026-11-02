const DATE_KEY = /^(\d{4})-(\d{2})-(\d{2})$/;
const CLOCK = /^([01]\d|2[0-3]):[0-5]\d$/;

export function normalizeTaskDateKey(value) {
    const match = String(value ?? '').match(DATE_KEY);
    if (!match) return '';
    const [, year, month, day] = match;
    const date = new Date(2000, 0, 1, 12);
    date.setFullYear(Number(year), Number(month) - 1, Number(day));
    return date.getFullYear() === Number(year) && date.getMonth() === Number(month) - 1 && date.getDate() === Number(day)
        ? `${year}-${month}-${day}`
        : '';
}

function addDateKey(value, amount) {
    const match = value.match(DATE_KEY);
    const date = new Date(2000, 0, 1, 12);
    date.setFullYear(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + amount);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function taskDateKeys(task, visibleStart = '', visibleEnd = '') {
    const start = normalizeTaskDateKey(task?.scheduled_date);
    let end = normalizeTaskDateKey(task?.scheduled_end_date) || start;
    if (!start || !end || end < start) return [];

    const timed = !task?.is_all_day && CLOCK.test(String(task?.start_time ?? '')) && CLOCK.test(String(task?.end_time ?? ''));
    if (timed && task.end_time === '00:00' && end > start) end = addDateKey(end, -1);
    if (end < start) return [];

    const clipStart = normalizeTaskDateKey(visibleStart);
    const clipEnd = normalizeTaskDateKey(visibleEnd);
    const first = clipStart && start < clipStart ? clipStart : start;
    const last = clipEnd && end > clipEnd ? clipEnd : end;
    if (last < first) return [];

    const keys = [];
    for (let key = first; key <= last; key = addDateKey(key, 1)) keys.push(key);
    return keys;
}

export function groupTasksByDate(tasks, visibleStart, visibleEnd) {
    return (tasks || []).reduce((result, task) => {
        for (const key of taskDateKeys(task, visibleStart, visibleEnd)) {
            const day = result[key] ||= [];
            if (!day.some(existing => String(existing.id) === String(task.id))) day.push(task);
        }
        return result;
    }, {});
}

export function groupTasksByMonth(tasks, year) {
    return (tasks || []).reduce((result, task) => {
        const start = normalizeTaskDateKey(task?.scheduled_date);
        let end = normalizeTaskDateKey(task?.scheduled_end_date) || start;
        if (!start || !end || end < start) return result;
        const timed = !task?.is_all_day && CLOCK.test(String(task?.start_time ?? '')) && CLOCK.test(String(task?.end_time ?? ''));
        if (timed && task.end_time === '00:00' && end > start) end = addDateKey(end, -1);
        if (end < start) return result;
        const first = start > `${year}-01-01` ? start.slice(0, 7) : `${year}-01`;
        const last = end < `${year}-12-31` ? end.slice(0, 7) : `${year}-12`;
        if (last < first) return result;
        const months = [];
        const monthDate = new Date(2000, 0, 1, 12);
        const [firstYear, firstMonth] = first.split('-').map(Number);
        monthDate.setFullYear(firstYear, firstMonth - 1, 1);
        while (`${monthDate.getFullYear()}-${String(monthDate.getMonth() + 1).padStart(2, '0')}` <= last) {
            months.push(String(monthDate.getMonth() + 1).padStart(2, '0'));
            monthDate.setMonth(monthDate.getMonth() + 1);
        }
        for (const monthNumber of months) {
            const items = result[monthNumber] ||= [];
            if (!items.some(existing => String(existing.id) === String(task.id))) items.push(task);
        }
        return result;
    }, {});
}
