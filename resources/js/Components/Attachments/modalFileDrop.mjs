const extensionOf = (name = '') => String(name).split('.').pop()?.toLowerCase() || '';

export function fileMatchesAccept(file, accept = '') {
    const rules = String(accept).split(',').map((rule) => rule.trim().toLowerCase()).filter(Boolean);
    if (!rules.length) return true;
    const extension = `.${extensionOf(file?.name)}`;
    return rules.some((rule) => {
        if (rule.startsWith('.')) return extension === rule;
        if (rule.endsWith('/*')) return String(file?.type || '').toLowerCase().startsWith(rule.slice(0, -1));
        return String(file?.type || '').toLowerCase() === rule;
    });
}

export function getDropTarget(inputs, { active = null, primary = null, direct = null } = {}) {
    const available = Array.from(inputs || []).filter((input) => !input.disabled);
    if (direct && available.includes(direct)) return direct;
    if (active && available.includes(active)) return active;
    if (primary && available.includes(primary)) return primary;
    return available.length === 1 ? available[0] : null;
}

export function validateDroppedFiles(files, input) {
    const incoming = Array.from(files || []);
    if (!input || !incoming.length) return { files: [], error: 'Choose a file field first.' };
    const maxSize = Number(input.dataset?.maxSizeBytes || 0);
    const valid = incoming.filter((file) => fileMatchesAccept(file, input.accept));
    const tooLarge = valid.filter((file) => maxSize > 0 && file.size > maxSize);
    const accepted = valid.filter((file) => !(maxSize > 0 && file.size > maxSize));
    if (!accepted.length) {
        if (tooLarge.length) return { files: [], error: `${tooLarge[0].name} exceeds the maximum file size.` };
        return { files: [], error: `${incoming[0].name} is not an allowed file type.` };
    }
    const limited = input.multiple ? accepted : accepted.slice(0, 1);
    return { files: limited, error: incoming.length > accepted.length ? 'Some files were not accepted.' : null };
}

export function assignDroppedFiles(input, files) {
    const transfer = new DataTransfer();
    Array.from(files || []).forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

export function forwardFileInputChange(onChange, event) {
    onChange?.(event);
}
