const MANILA = 'Asia/Manila';
const localParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: MANILA,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

function asInputMinute(value) {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const parts = Object.fromEntries(localParts.formatToParts(date).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

export function manilaDateTimeInput(value = new Date()) {
    return asInputMinute(value);
}

export function earliestManilaReceiptInput(releaseAt) {
    if (!releaseAt) return '';
    const release = new Date(releaseAt);
    if (Number.isNaN(release.getTime())) return '';
    const selectableMinute = Math.ceil(release.getTime() / 60_000) * 60_000;
    return asInputMinute(new Date(selectableMinute));
}

export function defaultManilaReceiptInput(releaseAt, existingReceiptAt = null, now = new Date()) {
    if (existingReceiptAt) return manilaDateTimeInput(existingReceiptAt);
    const nowMinute = Math.floor(now.getTime() / 60_000) * 60_000;
    const earliest = earliestManilaReceiptInput(releaseAt);
    if (!earliest) return asInputMinute(new Date(nowMinute));
    const earliestInstant = new Date(`${earliest}:00+08:00`).getTime();
    return asInputMinute(new Date(Math.max(nowMinute, earliestInstant)));
}
