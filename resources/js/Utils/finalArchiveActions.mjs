import { interceptors } from '@inertiajs/core';

function hasRecordIdentity(selected) {
    return Boolean(selected?.source)
        && Number.isSafeInteger(Number(selected?.source_id))
        && Number(selected.source_id) > 0;
}

function responsePage(response) {
    const body = response?.data;
    if (typeof body === 'string') {
        try {
            return JSON.parse(body);
        } catch {
            return null;
        }
    }
    return body;
}

export function registerFinalArchivePageGuard(archivesPath) {
    let expectedPath;
    try {
        expectedPath = new URL(archivesPath, globalThis.location?.href || 'http://localhost').pathname;
    } catch {
        expectedPath = null;
    }

    return interceptors.onVisitResponse((visit, response) => {
        const visitPath = visit?.url instanceof URL
            ? visit.url.pathname
            : typeof visit?.url === 'string'
                ? new URL(visit.url, globalThis.location?.href || 'http://localhost').pathname
                : null;
        if (!expectedPath || visitPath !== expectedPath || response?.status >= 400) return response;

        const page = responsePage(response);
        const validPage = page && typeof page === 'object' && !Array.isArray(page)
            && typeof page.component === 'string' && page.component.length > 0
            && typeof page.url === 'string' && page.url.length > 0
            && page.props && typeof page.props === 'object' && !Array.isArray(page.props);
        if (!validPage) {
            const error = new Error('The selected Archives response did not contain a complete Inertia page.');
            error.name = 'InvalidFinalArchivePageResponseError';
            throw error;
        }
        return response;
    });
}

export function finalArchiveVisitErrorOptions(onFailure = () => {}) {
    return {
        onHttpException: response => {
            onFailure({ kind: 'http', status: Number(response?.status) || 0 });
            return false;
        },
        onNetworkError: () => {
            onFailure({ kind: 'network' });
            return false;
        },
    };
}

export function finalArchiveRequestFailureMessage() {
    return 'The archive response could not be confirmed. The receipt or archive may already be recorded. Check this selected record before recording another receipt; use Retry Archive only if its existing manifest needs it.';
}

export function postFinalReceipt(event, form, selected, routeFor, onSuccess) {
    event.preventDefault();
    if (!hasRecordIdentity(selected)) return false;

    return form.post(routeFor('final-archives.receipts.store', [selected.source, selected.source_id]), { onSuccess });
}

export function postFinalArchive(event, form, selected, routeFor, onSuccess, onFailure) {
    event.preventDefault();
    if (form.processing || !hasRecordIdentity(selected)) return false;

    return form.post(routeFor('final-archives.store', [selected.source, selected.source_id]), {
        forceFormData: true,
        onSuccess,
        ...finalArchiveVisitErrorOptions(onFailure),
    });
}

export function postFinalArchiveRetry(form, selected, routeFor, onSuccess, onFailure) {
    const manifestId = Number(selected?.latest_manifest?.id);
    if (form.processing || selected?.latest_manifest?.retry_available !== true
        || !Number.isSafeInteger(manifestId) || manifestId < 1) return false;

    return form.post(routeFor('final-archives.retry', manifestId), {
        onSuccess,
        ...finalArchiveVisitErrorOptions(onFailure),
    });
}
