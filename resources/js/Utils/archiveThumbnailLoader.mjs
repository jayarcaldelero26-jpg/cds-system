import { inlinePreviewType, loadProtectedPreview } from './protectedDocumentPreview.mjs';

const MAX_CACHE_ENTRIES = 28;
const MAX_CACHE_BYTES = 3 * 1024 * 1024;

function abortError() {
    const error = new Error('The thumbnail request was cancelled.');
    error.name = 'AbortError';
    return error;
}

function throwIfAborted(signal) {
    if (signal?.aborted) throw abortError();
}

export function archiveThumbnailScopeKey(auth = {}) {
    const user = auth.user;
    if (!user?.id || auth.canViewFinalArchives !== true) return '';
    const capabilities = Object.entries(auth)
        .filter(([, value]) => typeof value === 'boolean')
        .sort(([left], [right]) => left.localeCompare(right));
    return JSON.stringify({
        user: [user.id, user.office_designated || '', user.protected_area_id || null,
            user.account_role || '', user.user_category || '', Boolean(user.is_active), Boolean(user.is_approved),
            [...(user.roles || [])].sort()],
        capabilities,
    });
}

export function archiveThumbnailIdentity(row, file, scopeKey) {
    if (!scopeKey || !row?.source || row?.source_id === null || row?.source_id === undefined
        || !file?.id || !file?.preview_url || file?.status !== 'ARCHIVED') return null;

    const earlierDispatch = Boolean(row.earlier_dispatch_copy || row.manifest?.status === 'EARLIER_DISPATCH_COPY');
    const manifestVerified = row.manifest?.status === 'ARCHIVED'
        && Array.isArray(row.manifest?.files)
        && row.manifest.files.length > 0
        && row.manifest.files.every(part => part.status === 'ARCHIVED' && part.preview_url && part.download_url);
    const dispatchReadable = earlierDispatch
        && Boolean(row.earlier_dispatch_copy?.file?.preview_url || file.preview_url)
        && Boolean(row.earlier_dispatch_copy?.file?.download_url || file.download_url);
    if (!manifestVerified && !dispatchReadable) return null;

    const type = inlinePreviewType(file.mime_type);
    if (!type) return null;

    return JSON.stringify([
        scopeKey,
        row.source,
        String(row.source_id),
        earlierDispatch ? 'dispatch_checkpoint' : 'regional_return_final',
        earlierDispatch ? String(row.earlier_dispatch_copy?.archive_id || file.id) : String(row.manifest?.id || ''),
        earlierDispatch ? String(row.earlier_dispatch_copy?.archived_at || '') : String(row.manifest?.revision || ''),
        String(file.id),
        String(file.size || ''),
        type,
        String(file.preview_url),
    ]);
}

export function createArchiveThumbnailCache({ maxEntries = MAX_CACHE_ENTRIES, maxBytes = MAX_CACHE_BYTES } = {}) {
    const entries = new Map();
    let bytes = 0;

    return {
        get(key) {
            const entry = entries.get(key);
            if (!entry) return null;
            entries.delete(key);
            entries.set(key, entry);
            return entry.blob;
        },
        set(key, blob) {
            if (!key || !(blob instanceof Blob) || blob.size < 1 || blob.size > maxBytes) return;
            const old = entries.get(key);
            if (old) {
                bytes -= old.blob.size;
                entries.delete(key);
            }
            entries.set(key, { blob });
            bytes += blob.size;
            while (entries.size > maxEntries || bytes > maxBytes) {
                const oldestKey = entries.keys().next().value;
                const oldest = entries.get(oldestKey);
                bytes -= oldest.blob.size;
                entries.delete(oldestKey);
            }
        },
        clear() {
            entries.clear();
            bytes = 0;
        },
        stats() {
            return { entries: entries.size, bytes };
        },
    };
}

class BoundedTaskQueue {
    constructor(limit) {
        this.limit = Math.max(1, Math.floor(limit));
        this.active = 0;
        this.pending = [];
    }

    run(task, signal) {
        return new Promise((resolve, reject) => {
            const item = { task, signal, resolve, reject, abort: null, started: false };
            item.abort = () => {
                if (item.started) return;
                const index = this.pending.indexOf(item);
                if (index !== -1) this.pending.splice(index, 1);
                reject(abortError());
            };
            if (signal?.aborted) return reject(abortError());
            signal?.addEventListener('abort', item.abort, { once: true });
            this.pending.push(item);
            this.drain();
        });
    }

    drain() {
        while (this.active < this.limit && this.pending.length) {
            const item = this.pending.shift();
            item.signal?.removeEventListener('abort', item.abort);
            if (item.signal?.aborted) {
                item.reject(abortError());
                continue;
            }
            item.started = true;
            this.active += 1;
            Promise.resolve()
                .then(() => item.task(item.signal))
                .then(item.resolve, item.reject)
                .finally(() => {
                    this.active -= 1;
                    this.drain();
                });
        }
    }
}

export function createArchiveThumbnailLoader({
    fetchPreview = loadProtectedPreview,
    renderPdf,
    renderImage,
    concurrency = 2,
    cache = createArchiveThumbnailCache(),
} = {}) {
    const queue = new BoundedTaskQueue(concurrency);
    const inFlight = new Map();
    let activeScope = null;

    const activateScope = scopeKey => {
        if (scopeKey === activeScope) return;
        activeScope = scopeKey;
        cache.clear();
        for (const flight of inFlight.values()) flight.controller.abort();
        inFlight.clear();
    };

    const subscribe = flight => {
        flight.consumers += 1;
        let subscribed = true;
        return {
            promise: flight.promise,
            release() {
                if (!subscribed) return;
                subscribed = false;
                flight.consumers = Math.max(0, flight.consumers - 1);
                if (flight.consumers === 0 && !flight.settled) flight.controller.abort();
            },
        };
    };

    return {
        activateScope,
        request({ scopeKey, key, url, type }) {
            activateScope(scopeKey);
            const supportedType = inlinePreviewType(type);
            if (!scopeKey || !key || !url || !supportedType) {
                return { promise: Promise.reject(new Error('Thumbnail is not eligible.')), release() {} };
            }

            const cached = cache.get(key);
            if (cached) return { promise: Promise.resolve(cached), release() {} };

            let flight = inFlight.get(key);
            if (flight?.controller.signal.aborted) {
                inFlight.delete(key);
                flight = null;
            }
            if (!flight) {
                const controller = new AbortController();
                flight = { controller, consumers: 0, settled: false, promise: null };
                const render = supportedType === 'application/pdf' ? renderPdf : renderImage;
                flight.promise = queue.run(async signal => {
                    throwIfAborted(signal);
                    const document = await fetchPreview(url, { signal });
                    throwIfAborted(signal);
                    if (!document?.blob || document.type !== supportedType || typeof render !== 'function') {
                        throw new Error('Thumbnail source is not supported.');
                    }
                    const thumbnail = await render(document.blob, signal);
                    throwIfAborted(signal);
                    if (!(thumbnail instanceof Blob) || !thumbnail.type.startsWith('image/') || thumbnail.size < 1) {
                        throw new Error('Thumbnail rendering failed.');
                    }
                    cache.set(key, thumbnail);
                    return thumbnail;
                }, controller.signal).finally(() => {
                    flight.settled = true;
                    if (inFlight.get(key) === flight) inFlight.delete(key);
                });
                inFlight.set(key, flight);
            }

            return subscribe(flight);
        },
        stats() {
            return { ...cache.stats(), queued: queue.pending.length, active: queue.active, inFlight: inFlight.size };
        },
    };
}
