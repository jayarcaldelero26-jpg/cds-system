export const settingsNavigationPermissions = [
    'canManageUsers',
    'canViewModuleManagement',
    'canManageComplianceAlerts',
    'canViewRoutingWorkflow',
    'canViewStorage',
    'canViewSystemDiagnostics',
    'canViewHistoricalReports',
];

export function resolveSettingsHref(auth = {}) {
    if (auth.canManageUsers) return '/settings';
    if (auth.canViewModuleManagement) return '/settings/module-management';
    if (auth.canManageComplianceAlerts) return '/settings/compliance-alerts';
    if (auth.canViewRoutingWorkflow) return '/settings/routing-workflow';
    if (auth.canViewStorage) return '/settings/storage';
    if (auth.canViewSystemDiagnostics) return '/settings/system-diagnostics';
    if (auth.canViewHistoricalReports) return '/settings/historical-reports';
    return '/settings';
}

export const settingsNavigationItem = {
    label: 'Settings',
    icon: 'settings',
    section: 'BOTH',
    activePrefix: '/settings',
    permissionAny: settingsNavigationPermissions,
    hrefResolver: resolveSettingsHref,
};
