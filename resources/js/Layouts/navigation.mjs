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

export function withGenericModuleNavigation(navigation, modules) {
    if (!modules?.length) return navigation;

    const areas = {
        protected_area_management_and_development: { label: 'Protected Area Management and Development', icon: 'protected-area' },
        wildlife_conservation_and_protection: { label: 'Wildlife Conservation and Protection', icon: 'wildlife' },
        community_based_forest_management: { label: 'Community-Based Forest Management', icon: 'forest' },
        integrated_watershed_management: { label: 'Integrated Watershed Management', icon: 'watershed' },
        engp: { label: 'National Greening Program', icon: 'sprout' },
        conservation: { label: 'Conservation', icon: 'wildlife' },
        development: { label: 'Development', icon: 'sprout' },
    };
    const grouped = modules.reduce((result, module) => {
        (result[module.program_area] ||= []).push({ label: module.label, href: module.href, permission: 'canViewTechnicalReports' });
        return result;
    }, {});
    const represented = new Set();
    const merged = navigation.map(item => {
        const area = Object.entries(areas).find(([, value]) => value.label === item.label)?.[0];
        if (!area || !grouped[area]) return item;
        represented.add(area);

        const existingChildren = item.children || [];
        const existingKeys = new Set(existingChildren.map(child => navigationHrefKey(child.href)).filter(Boolean));
        const additions = grouped[area].filter(child => {
            const key = navigationHrefKey(child.href);
            if (key && existingKeys.has(key)) return false;
            if (key) existingKeys.add(key);
            return true;
        });

        return { ...item, groupOnly: false, comingSoon: false, children: [...existingChildren, ...additions] };
    });

    Object.entries(grouped).forEach(([area, children]) => {
        if (represented.has(area)) return;
        const config = areas[area];
        if (config) merged.splice(merged.findIndex(item => item.label === 'CDS-SMART MONITORING'), 0, { ...config, section: 'CDS', children });
    });

    return merged;
}
