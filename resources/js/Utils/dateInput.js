const pad = value => String(value).padStart(2, '0');

/** Return the browser's local calendar date for a native date input. */
export function localDateInputValue(value = new Date()) {
    return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}
