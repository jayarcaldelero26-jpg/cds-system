function navigationHrefKey(href) {
    if (!href) return '';

    try {
        const url = new URL(href, 'http://cds-smart');
        const query = [...url.searchParams.entries()]
            .sort(([leftKey, leftValue], [rightKey, rightValue]) => leftKey.localeCompare(rightKey) || leftValue.localeCompare(rightValue));

        return `${url.pathname}${query.length ? `?${new URLSearchParams(query)}` : ''}`;
    } catch {
        return String(href).split('#')[0];
    }
}

export const finalArchivesNavigationItem = Object.freeze({
    label: 'Archives',
    href: '/submission-tracking/archives',
    icon: 'archive',
    permission: 'canViewFinalArchives',
    section: 'BOTH',
});

export function matchesNavigationItem(item, url) {
    if (item.children?.length) return item.children.some(child => matchesNavigationItem(child, url));
    const currentPath = url.split('?')[0];
    if (item.activePrefix && (currentPath === item.activePrefix || currentPath.startsWith(`${item.activePrefix}/`))) return true;
    if (item.activePrefixes?.some(prefix => currentPath === prefix || currentPath.startsWith(`${prefix}/`))) return true;
    if (!item.href || item.href === '#') return false;
    const [targetPath, targetQuery = ''] = item.href.split('?');
    if (!(item.exact ? currentPath === targetPath : (currentPath === targetPath || currentPath.startsWith(targetPath + '/')))) return false;
    const currentParams = new URLSearchParams(url.split('?')[1] || '');
    const targetParams = new URLSearchParams(targetQuery);
    for (const [key, value] of targetParams.entries()) {
        if (currentParams.get(key) !== value && !item.activeQueryAny?.some(query => currentParams.get(key) === query[key])) return false;
    }
    if (item.activeQueryAny?.length) return item.activeQueryAny.some(query => Object.entries(query).every(([key, value]) => currentParams.get(key) === value));
    return Object.entries(item.activeQuery || {}).every(([key, value]) => value === null ? !currentParams.has(key) : currentParams.get(key) === value);
}

export function withGenericModuleNavigation(navigation, modules = [], auth = {}, nativeModules = [], moduleCatalog = null) {
    // The authenticated category is the identity boundary. Inferring PAMO
    // mode from a browse flag made the module catalog fall back to the static
    // permission-filtered links on pages where that flag was also true.
    const pamoScoped = auth.user?.user_category === 'PAMO' && auth.canViewPamoReports === true;

    const areas = {
        protected_area_management_and_development: { label: 'Protected Area Management and Development', icon: 'protected-area' },
        wildlife_conservation_and_protection: { label: 'Wildlife Conservation and Protection', icon: 'wildlife' },
        community_based_forest_management: { label: 'Community-Based Forest Management', icon: 'forest' },
        integrated_watershed_management: { label: 'Integrated Watershed Management', icon: 'watershed' },
        engp: { label: 'National Greening Program', icon: 'sprout' },
        conservation: { label: 'Conservation', icon: 'wildlife' },
        development: { label: 'Development', icon: 'sprout' },
    };
    const availableModules = [...modules, ...nativeModules];
    if (pamoScoped) availableModules.sort((left, right) => (left.display_order ?? Number.MAX_SAFE_INTEGER) - (right.display_order ?? Number.MAX_SAFE_INTEGER) || left.label.localeCompare(right.label));
    const grouped = availableModules.reduce((result, module) => {
        (result[module.program_area] ||= []).push({
            label: module.label,
            href: module.href,
            permissionAny: pamoScoped ? ['canViewPamoReports'] : ['canViewTechnicalReports'],
            catalogManaged: true,
        });
        return result;
    }, {});
    const catalogReady = moduleCatalog?.ready === true && Array.isArray(moduleCatalog.entries);
    const catalogStatus = new Map(catalogReady
        ? moduleCatalog.entries
            .filter(entry => entry?.href)
            .map(entry => [navigationHrefKey(entry.href), entry])
        : []);
    const isKnownInactive = href => {
        const entry = catalogReady ? catalogStatus.get(navigationHrefKey(href)) : null;
        return Boolean(entry && (entry.is_active === false || entry.is_retired === true));
    };
    const represented = new Set();
    const merged = navigation.map(item => {
        const area = Object.entries(areas).find(([, value]) => value.label === item.label)?.[0];
        if (!area) {
            if (!item.children?.length || !catalogReady) return item;
            return { ...item, children: item.children.filter(child => !isKnownInactive(child.href)) };
        }
        if (!grouped[area] && !pamoScoped) {
            if (!catalogReady || !item.children?.length) return item;
            return { ...item, children: item.children.filter(child => !isKnownInactive(child.href)) };
        }

        const available = grouped[area] || [];
        const byHref = new Map(available.map(child => [navigationHrefKey(child.href), child]));
        const existingChildren = (item.children || []).flatMap(child => {
            if (isKnownInactive(child.href)) return [];
            const key = navigationHrefKey(child.href);
            const catalogChild = key ? byHref.get(key) : null;
            if (catalogChild) {
                represented.add(`${area}:${key}`);
                // Keep curated in-app links relative to the current origin.
                // The catalog may provide an APP_URL-based absolute href that
                // would switch hosts for users who opened the application via
                // another valid alias. Catalog permission metadata still wins.
                return [{
                    ...catalogChild,
                    ...child,
                    permissionAny: catalogChild.permissionAny ?? child.permissionAny,
                    catalogManaged: true,
                }];
            }
            if (pamoScoped) return [];
            return [child];
        });
        if (pamoScoped && available.length === 0) return null;
        represented.add(area);
        const additions = available.filter(child => {
            const key = navigationHrefKey(child.href);
            if (key && represented.has(`${area}:${key}`)) return false;
            if (key) represented.add(`${area}:${key}`);
            return true;
        });

        return { ...item, groupOnly: false, comingSoon: false, children: [...existingChildren, ...additions] };
    }).filter(Boolean);

    Object.entries(grouped).forEach(([area, children]) => {
        if (represented.has(area)) return;
        const config = areas[area];
        if (config) {
            const index = merged.findIndex(item => item.label === 'CDS-SMART MONITORING');
            const entry = { ...config, section: 'CDS', unit: area === 'engp' || area === 'development' ? 'development' : 'conservation', children };
            if (index < 0) merged.push(entry);
            else merged.splice(index, 0, entry);
        }
    });

    return merged;
}

export function filterNavigationByUnit(items, unit, inheritedUnit = null, auth = {}) {
    return items.map((item) => {
        if (item.permission && !auth[item.permission]) return null;
        if (item.permissionAny?.length && !item.permissionAny.some(permission => auth[permission])) return null;

        const itemUnit = item.unit || inheritedUnit;
        const containsPamoReportLink = item.children?.some(child => child.href?.startsWith('/conservation-reports/')) && auth.canViewPamoReports;
        const containsPamoCatalogLink = item.children?.some(child => child.catalogManaged === true) && auth.canViewPamoReports;
        const isPamoCatalogLink = item.catalogManaged === true && auth.canViewPamoReports;
        if (itemUnit === 'conservation' && auth.canBrowseConservationModules === false && !containsPamoReportLink && !containsPamoCatalogLink && !isPamoCatalogLink) return null;
        if (itemUnit === 'development' && auth.canBrowseDevelopmentModules === false) return null;
        if (unit && itemUnit && itemUnit !== unit) return null;
        const children = item.children ? filterNavigationByUnit(item.children, unit, itemUnit, auth) : null;
        if (item.children && !children?.length) return null;
        return children ? { ...item, children } : item;
    }).filter(Boolean);
}

/** Exact navigation model consumed by AuthenticatedLayout's Sidebar render. */
export function buildSidebarNavigation(navigation, modules, auth, nativeModules = [], unit = null, moduleCatalog = null) {
    const pamoScoped = auth.user?.user_category === 'PAMO' && auth.canViewPamoReports === true;
    if (pamoScoped) {
        const preserved = navigation.filter(item => ['Manual Operation', 'Conservation Database', 'Alerts', 'Calendar', 'Administration'].includes(item.label));
        const pamoNavigation = [
            { label: 'Submission Tracking', heading: true, staticHeading: true, section: 'BOTH' },
            { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', section: 'BOTH' },
            { label: 'Reports', href: '/pamo/reports', icon: 'reports', permissionAny: ['canViewPamoReports'], activePrefixes: ['/conservation-reports', '/pamo-native-reports'], section: 'BOTH' },
            { label: 'For Action', href: '/submission-tracking?view=incoming', icon: 'clock', permission: 'canViewSubmissionTracking', section: 'BOTH' },
            { label: 'Sent Reports', href: '/submission-tracking?view=outgoing', icon: 'submission-tracking', permission: 'canViewSubmissionTracking', section: 'BOTH' },
            { label: 'Completed Reports', href: '/submission-tracking?view=history', icon: 'check', permission: 'canViewSubmissionTracking', section: 'BOTH' },
            finalArchivesNavigationItem,
            ...preserved,
        ];
        return filterNavigationByUnit(pamoNavigation, unit, null, auth);
    }
    const merged = withGenericModuleNavigation(navigation, modules, auth, nativeModules, moduleCatalog);
    return filterNavigationByUnit(
        merged.filter(item => !unit || item.section === 'BOTH' || item.section === 'CDS'),
        unit,
        null,
        auth,
    ).map(item => {
        if (!pamoScoped || !item.children) return item;
        return { ...item, defaultExpanded: item.children.some(child => child.catalogManaged) };
    });
}
