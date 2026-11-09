export function navigationPendingReducer(current, action) {
    if (action?.type === 'start' && Number.isInteger(action.intent?.id)) return action.intent;
    if (action?.type === 'finish' && current?.id === action.id) return null;
    return current;
}

export function navigationVisitKey(visit) {
    if (!visit?.url) return null;
    const url = new URL(visit.url, globalThis.location?.origin || 'http://localhost');
    return url.pathname.replace(/\/+$/, '') + url.search;
}

const excludedPath = /(?:export|download|preview)(?:\/|$)/i;

export function navigationSkeletonIntent(visit, currentUrl, id) {
    if (!visit || String(visit.method || 'get').toLowerCase() !== 'get') return null;
    if (visit.only?.length || visit.except?.length || visit.async === true || visit.prefetch === true) return null;
    const baseOrigin = globalThis.location?.origin || 'http://localhost';
    const destination = new URL(visit.url, baseOrigin);
    const current = new URL(currentUrl || '/', baseOrigin);
    if (destination.origin !== current.origin || excludedPath.test(destination.pathname)) return null;
    if (destination.pathname === current.pathname && destination.search === current.search) return null;
    if (/^(?:export|download|format)$/i.test(destination.searchParams.keys().next().value || '')) return null;
    const path = destination.pathname.replace(/\/+$/, '') || '/';
    const tracker = destination.searchParams.get('tracker');
    const family = path === '/reports' || path.startsWith('/reports/') ? 'reports'
        : path === '/bms' ? (tracker === '1' ? 'bms-tracker' : 'bms-list')
        : path.includes('calendar') ? 'calendar'
        : /(?:settings|profile|account|configuration)/i.test(path) ? 'form'
        : /(?:admin|management|reports|tracking)/i.test(path) ? 'list'
        : 'generic';
    return { id, href: destination.pathname + destination.search, family };
}
