const INLINE_TYPES = new Set(['application/pdf', 'image/jpeg', 'image/png']);

export function inlinePreviewType(value) {
    const type = String(value || '').split(';', 1)[0].trim().toLowerCase();
    return INLINE_TYPES.has(type) ? type : null;
}

export class PreviewLoadError extends Error {
    constructor(code) {
        const messages = {
            session: 'Your session has expired or requires sign-in. Sign in again, then retry.',
            forbidden: 'You do not have access to this document with your current account.',
            missing: 'The current document is no longer available. Refresh the submission details.',
            response: 'The server did not return a supported document. Refresh the submission details and retry.',
            network: 'The preview could not be loaded. Check your connection or session and retry.',
        };
        super(messages[code] || messages.response);
        this.name = 'PreviewLoadError';
        this.code = code;
    }
}

function matchesSignature(bytes, type) {
    if (type === 'application/pdf') {
        return [0x25, 0x50, 0x44, 0x46, 0x2d].every((byte, i) => bytes[i] === byte);
    }
    if (type === 'image/jpeg') return bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff;
    if (type === 'image/png') return [137, 80, 78, 71, 13, 10, 26, 10].every((byte, i) => bytes[i] === byte);
    return false;
}

// Use the existing protected endpoint and the current session. Never embed an
// application error/login page, follow an auth redirect, or use an archive URL.
export async function loadProtectedPreview(url, {
    baseUrl = globalThis.location?.href,
    fetchImpl = globalThis.fetch,
    signal,
} = {}) {
    let target;
    try {
        const base = new URL(baseUrl);
        target = new URL(url, base);
        if (!['http:', 'https:'].includes(target.protocol) || target.origin !== base.origin
            || target.username || target.password) throw new Error('Invalid preview URL');
    } catch {
        throw new PreviewLoadError('response');
    }

    let response;
    try {
        response = await fetchImpl(target.href, {
            credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal,
            headers: { Accept: 'application/pdf, image/jpeg, image/png' },
        });
    } catch (error) {
        if (error?.name === 'AbortError') throw error;
        throw new PreviewLoadError('network');
    }
    if (response.status === 401 || response.status === 419) throw new PreviewLoadError('session');
    if (response.status === 403) throw new PreviewLoadError('forbidden');
    if (response.status === 404) throw new PreviewLoadError('missing');
    if (!response.ok || response.redirected) throw new PreviewLoadError('response');

    const type = inlinePreviewType(response.headers.get('content-type'));
    if (!type) throw new PreviewLoadError('response');
    const blob = await response.blob();
    const header = new Uint8Array(await blob.slice(0, 8).arrayBuffer());
    if (!matchesSignature(header, type)) throw new PreviewLoadError('response');
    return { blob: blob.type === type ? blob : new Blob([blob], { type }), type };
}

// The dialog owns this task. Disposing on close/record change aborts the request,
// suppresses stale completions, and revokes the single temporary object URL.
export function startProtectedPreview(url, { onState, urlApi = URL, ...options }) {
    const controller = new AbortController();
    let active = true;
    let objectUrl = null;
    onState({ status: 'loading', url: null, type: null, error: null });
    const done = loadProtectedPreview(url, { ...options, signal: controller.signal }).then(({ blob, type }) => {
        if (!active) return;
        objectUrl = urlApi.createObjectURL(blob);
        onState({ status: 'ready', url: objectUrl, blob, type, error: null });
    }).catch(error => {
        if (!active || error?.name === 'AbortError') return;
        const failure = error instanceof PreviewLoadError ? error : new PreviewLoadError('network');
        onState({ status: 'error', url: null, type: null, error: failure });
    });
    return {
        done,
        dispose() {
            active = false;
            controller.abort();
            if (objectUrl !== null) {
                urlApi.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        },
    };
}
