export function releaseModalFocusTarget(panel, documentObject) {
    const target = documentObject?.activeElement;
    if (!target || !panel?.contains?.(target)) return null;
    target.blur?.();
    return target;
}

export function restoreModalFocusTarget(target, documentObject, schedule = callback => queueMicrotask(callback)) {
    if (!target || !documentObject?.contains?.(target)) return false;
    schedule(() => {
        if (documentObject.contains(target)) target.focus?.();
    });
    return true;
}
