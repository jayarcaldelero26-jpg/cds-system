export const emptyDateConductedRange = () => ({ from: '', to: '' });
export const canonicalDateConductedValue = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || '')) ? String(value) : '';
export const legacyDateConductedHelper = (value) => { const text = String(value || ''); return text && !canonicalDateConductedValue(text) ? `Existing value: ${text}` : ''; };

export const rangesFromRecord = (ranges, legacy = '') => {
    if (Array.isArray(ranges) && ranges.length > 0) return ranges.map((range) => ({ from: range?.from || '', to: range?.to || range?.from || '' }));
    const value = String(legacy || '');
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? [{ from: value, to: value }] : [emptyDateConductedRange()];
};

export const compactDateConductedRanges = (ranges) => (Array.isArray(ranges) ? ranges.filter((range) => range?.from || range?.to) : []);

export const dateConductedRangeError = (range) => {
    if (!range?.from && !range?.to) return '';
    if (!range?.from) return 'From date is required.';
    if (range?.to && range.to < range.from) return 'To date must be on or after From date.';
    return '';
};
