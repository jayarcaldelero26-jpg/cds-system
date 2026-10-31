export function restoreModalFocusTarget(target, documentObject, schedule = callback => queueMicrotask(callback)) {
    if (!target || !documentObject?.contains?.(target)) return false;
    schedule(() => {
        if (documentObject.contains(target)) target.focus?.();
    });
    return true;
}
