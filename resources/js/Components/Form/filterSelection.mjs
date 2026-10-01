export function normalizeFilterValues(values) {
    return Array.isArray(values) ? values.map(String) : [];
}

export function toggleFilterValue(values, value) {
    const current = normalizeFilterValues(values);
    const key = String(value);
    return current.includes(key) ? current.filter(item => item !== key) : [...current, key];
}

export function filterSelectionSummary(options, values, placeholder = 'Select') {
    const selected = normalizeFilterValues(values);
    const labels = options.filter(option => selected.includes(String(option.value))).map(option => option.label);
    return labels.length === 0 ? placeholder : labels.length <= 2 ? labels.join(', ') : `${labels.length} selected`;
}
