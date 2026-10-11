export function resolveHistoricalRecordHref(canViewHistoricalReports, routeBuilder) {
    if (!canViewHistoricalReports) return null;
    return routeBuilder('settings.historical-reports.index');
}

export function stopHistoricalRecordEvent(event, callback = null) {
    event.stopPropagation();
    callback?.(event);
}
