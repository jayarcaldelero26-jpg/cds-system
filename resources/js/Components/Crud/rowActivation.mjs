export function isRowActivationKey(event) {
    return event?.key === 'Enter' || event?.key === ' ';
}

export function isNestedInteractiveTarget(target, row) {
    const interactive = target?.closest?.('a[href], button, input, select, textarea, [role="button"], [role="link"], [tabindex]:not([tabindex="-1"])');
    return Boolean(interactive && interactive !== row);
}

export function shouldActivateRow(event, row) {
    if (event?.type === 'keydown' && !isRowActivationKey(event)) return false;
    return !isNestedInteractiveTarget(event?.target, row);
}
