const FILTER_KEYS = ['search', 'module', 'protected_area', 'office', 'reporting_year'];

export function finalArchiveFilterParams({ search, module, protectedArea, office, year, selected }) {
    return {
        ...(search?.trim() ? { search: search.trim() } : {}),
        ...(module ? { module } : {}),
        ...(protectedArea ? { protected_area: protectedArea } : {}),
        ...(office ? { office } : {}),
        ...(year ? { reporting_year: year } : {}),
        ...(selected ? { source: selected.source, record: selected.source_id } : {}),
    };
}

export function finalArchiveFiltersMatch(currentQuery, params) {
    return FILTER_KEYS.every(key => (currentQuery.get(key) || '') === (params[key] || ''));
}

export function applyFinalArchiveFilters(router, routeFor, filters, currentQuery, visitOptions = {}) {
    const params = finalArchiveFilterParams(filters);
    if (finalArchiveFiltersMatch(currentQuery, params)) return false;

    router.get(routeFor('final-archives.index'), params, { preserveState: true, preserveScroll: true, replace: true, ...visitOptions });
    return true;
}
