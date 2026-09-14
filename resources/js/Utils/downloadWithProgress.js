export async function downloadWithProgress(url, filename, onProgress) {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(response.status === 403 ? 'You are not authorized to download this document.' : response.status === 404 ? 'This document is no longer available.' : 'The document download failed.');
    const totalHeader = response.headers.get('Content-Length');
    const total = totalHeader && Number.isFinite(Number(totalHeader)) ? Number(totalHeader) : null;
    const reader = response.body?.getReader();
    if (!reader) { const blob = await response.blob(); trigger(blob, filename); return; }
    const chunks = []; let loaded = 0;
    onProgress?.({ loaded, total, percentage: total ? 0 : null });
    while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        chunks.push(value); loaded += value.byteLength;
        onProgress?.({ loaded, total, percentage: total ? Math.round((loaded / total) * 100) : null });
    }
    trigger(new Blob(chunks, { type: response.headers.get('Content-Type') || 'application/octet-stream' }), filename);
}

function trigger(blob, filename) {
    const href = URL.createObjectURL(blob); const link = document.createElement('a');
    link.href = href; link.download = filename || 'document'; document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(href), 0);
}
