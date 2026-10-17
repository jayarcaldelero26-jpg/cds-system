import { getDocument } from 'pdfjs-dist';
import { createArchiveThumbnailLoader } from './archiveThumbnailLoader.mjs';
import { pdfJsWasmUrl } from './pdfjsWasmAssets.mjs';

const MAX_WIDTH = 320;
const MAX_HEIGHT = 180;
const MAX_PIXELS = MAX_WIDTH * MAX_HEIGHT;
const MAX_EDGE = Math.max(MAX_WIDTH, MAX_HEIGHT);

function throwIfCancelled(signal) {
    if (!signal?.aborted) return;
    const error = new Error('Thumbnail work was cancelled.');
    error.name = 'AbortError';
    throw error;
}

function targetSize(width, height) {
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return null;
    const scale = Math.min(MAX_WIDTH / width, MAX_HEIGHT / height, MAX_EDGE / Math.max(width, height), Math.sqrt(MAX_PIXELS / (width * height)), 1);
    return { width: Math.max(1, Math.floor(width * scale)), height: Math.max(1, Math.floor(height * scale)) };
}

function canvasToJpeg(canvas, signal) {
    throwIfCancelled(signal);
    if (typeof canvas.toBlob === 'function') {
        return new Promise((resolve, reject) => {
            canvas.toBlob(blob => {
                if (signal?.aborted) {
                    const error = new Error('Thumbnail work was cancelled.');
                    error.name = 'AbortError';
                    reject(error);
                } else if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('Thumbnail canvas could not be encoded.'));
                }
            }, 'image/jpeg', 0.78);
        });
    }
    if (typeof canvas.toBuffer === 'function') return Promise.resolve(new Blob([canvas.toBuffer('image/jpeg', 0.78)], { type: 'image/jpeg' }));
    return Promise.reject(new Error('Thumbnail canvas could not be encoded.'));
}

export async function renderPdfFirstPage(blob, signal, canvasFactory = () => document.createElement('canvas')) {
    throwIfCancelled(signal);
    const data = new Uint8Array(await blob.arrayBuffer());
    throwIfCancelled(signal);
    const loadingTask = getDocument({ data, wasmUrl: pdfJsWasmUrl });
    let pdf = null;
    let renderTask = null;
    let destroyPromise = null;
    const destroy = () => {
        if (!destroyPromise) destroyPromise = loadingTask.destroy();
        return destroyPromise;
    };
    const abort = () => {
        renderTask?.cancel();
        void destroy().catch(() => {});
    };
    signal?.addEventListener('abort', abort, { once: true });
    let canvas = null;

    try {
        pdf = await loadingTask.promise;
        throwIfCancelled(signal);
        const page = await pdf.getPage(1);
        throwIfCancelled(signal);
        const natural = page.getViewport({ scale: 1 });
        const size = targetSize(natural.width, natural.height);
        if (!size) throw new Error('PDF page has invalid dimensions.');
        const scale = size.width / natural.width;
        const viewport = page.getViewport({ scale });
        canvas = canvasFactory();
        canvas.width = Math.max(1, Math.floor(viewport.width));
        canvas.height = Math.max(1, Math.floor(viewport.height));
        const context = canvas.getContext('2d', { alpha: false });
        if (!context || canvas.width * canvas.height > MAX_PIXELS) throw new Error('PDF thumbnail canvas is unavailable.');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        renderTask = page.render({ canvas, viewport });
        await renderTask.promise;
        throwIfCancelled(signal);
        return await canvasToJpeg(canvas, signal);
    } finally {
        signal?.removeEventListener('abort', abort);
        if (canvas) { canvas.width = 0; canvas.height = 0; }
        if (pdf && !signal?.aborted) await pdf.cleanup?.();
        try { await destroy(); } catch (error) { if (!signal?.aborted) throw error; }
    }
}

async function imageDimensions(blob, type) {
    if (type === 'image/png') {
        const bytes = new Uint8Array(await blob.slice(0, 24).arrayBuffer());
        if (bytes.length < 24) return null;
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        return { width: view.getUint32(16), height: view.getUint32(20) };
    }

    const bytes = new Uint8Array(await blob.slice(0, 512 * 1024).arrayBuffer());
    if (bytes[0] !== 0xff || bytes[1] !== 0xd8) return null;
    const frameMarkers = new Set([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf]);
    let offset = 2;
    while (offset + 4 < bytes.length) {
        if (bytes[offset] !== 0xff) { offset += 1; continue; }
        while (bytes[offset] === 0xff) offset += 1;
        const marker = bytes[offset++];
        if (marker === 0xd9 || marker === 0xda) break;
        if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd8)) continue;
        const length = (bytes[offset] << 8) | bytes[offset + 1];
        if (length < 2 || offset + length > bytes.length) break;
        if (frameMarkers.has(marker) && length >= 7) {
            return { height: (bytes[offset + 3] << 8) | bytes[offset + 4], width: (bytes[offset + 5] << 8) | bytes[offset + 6] };
        }
        offset += length;
    }
    return null;
}

export async function renderImageThumbnail(blob, type, signal) {
    throwIfCancelled(signal);
    const dimensions = await imageDimensions(blob, type);
    const size = dimensions && targetSize(dimensions.width, dimensions.height);
    if (!size || typeof globalThis.createImageBitmap !== 'function') throw new Error('Image thumbnail decoding is unavailable.');
    const bitmap = await createImageBitmap(blob, {
        resizeWidth: size.width,
        resizeHeight: size.height,
        resizeQuality: 'high',
    });
    let canvas = null;
    try {
        throwIfCancelled(signal);
        canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        if (canvas.width * canvas.height > MAX_PIXELS) throw new Error('Image thumbnail canvas is too large.');
        const context = canvas.getContext('2d', { alpha: false });
        if (!context) throw new Error('Image thumbnail canvas is unavailable.');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, size.width, size.height);
        return await canvasToJpeg(canvas, signal);
    } finally {
        bitmap.close?.();
        if (canvas) { canvas.width = 0; canvas.height = 0; }
    }
}

export const archiveThumbnailLoader = createArchiveThumbnailLoader({
    renderPdf: renderPdfFirstPage,
    renderImage: renderImageThumbnail,
    concurrency: 2,
});
