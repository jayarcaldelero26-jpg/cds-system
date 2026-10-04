import { useEffect, useRef, useState } from 'react';
import { GlobalWorkerOptions, getDocument } from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { fitPageScale, nextPage, nextZoom, zoomedScrollOffset } from '@/Utils/pdfViewerGeometry.mjs';
import { createRenderRequestIdentity } from '@/Utils/pdfViewerRequests.mjs';

GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const clampZoom = value => Math.min(4, Math.max(0.25, value));

export default function PdfDocumentViewer({ blob, title = 'PDF document', className = '' }) {
    const stageRef = useRef(null);
    const canvasRef = useRef(null);
    const renderRequestRef = useRef(null);
    if (renderRequestRef.current === null) renderRequestRef.current = createRenderRequestIdentity();
    const zoomRef = useRef(1);
    const panRef = useRef(null);
    const anchorRef = useRef(null);
    const [pdf, setPdf] = useState(null);
    const [pageNumber, setPageNumber] = useState(1);
    const [pageCount, setPageCount] = useState(0);
    const [zoom, setZoomState] = useState(1);
    const [fitScale, setFitScale] = useState(null);
    const [rendered, setRendered] = useState(null);
    const [renderError, setRenderError] = useState(false);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const [status, setStatus] = useState('loading');
    const [panning, setPanning] = useState(false);

    const setZoom = update => {
        setZoomState(current => {
            const next = clampZoom(typeof update === 'function' ? update(current) : update);
            zoomRef.current = next;
            return next;
        });
    };

    useEffect(() => {
        if (!blob) {
            setPdf(null);
            setPageNumber(1);
            setPageCount(0);
            setZoom(1);
            setFitScale(null);
            setRendered(null);
            setRenderError(false);
            setStatus('error');
            return undefined;
        }
        let disposed = false;
        let loadingTask;
        setPdf(null);
        setPageNumber(1);
        setPageCount(0);
        setZoom(1);
        setFitScale(null);
        setRendered(null);
        setRenderError(false);
        setStatus('loading');
        (async () => {
            try {
                const data = new Uint8Array(await blob.arrayBuffer());
                if (disposed) return;
                loadingTask = getDocument({ data });
                const document = await loadingTask.promise;
                if (disposed) {
                    await document.destroy();
                    return;
                }
                setPdf(document);
                setPageCount(document.numPages);
                setStatus('ready');
            } catch {
                if (!disposed) setStatus('error');
            }
        })();
        return () => {
            disposed = true;
            if (loadingTask) void loadingTask.destroy();
        };
    }, [blob]);

    useEffect(() => {
        const stage = stageRef.current;
        if (!stage) return undefined;
        const update = () => {
            const next = { width: stage.clientWidth, height: stage.clientHeight };
            setSize(current => current.width === next.width && current.height === next.height ? current : next);
        };
        update();
        const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(update) : null;
        observer?.observe(stage);
        return () => observer?.disconnect();
    }, []);

    useEffect(() => {
        const requestId = renderRequestRef.current.begin();
        let cancelled = false;
        let renderTask;
        let renderCanvas;
        if (!pdf || !size.width || !size.height) return undefined;
        setRenderError(false);
        (async () => {
            try {
                const page = await pdf.getPage(pageNumber);
                if (cancelled || !renderRequestRef.current.isCurrent(requestId)) return;
                const natural = page.getViewport({ scale: 1 });
                const fit = fitPageScale(natural.width, natural.height, Math.max(1, size.width - 32), Math.max(1, size.height - 32));
                setFitScale(current => current === null || Math.abs(current - fit) >= 0.001 ? fit : current);
                const scale = fit * zoom;
                const viewport = page.getViewport({ scale });
                const outputScale = Math.min(globalThis.devicePixelRatio || 1, 2);
                renderCanvas = document.createElement('canvas');
                renderCanvas.width = Math.floor(viewport.width * outputScale);
                renderCanvas.height = Math.floor(viewport.height * outputScale);
                renderTask = page.render({ canvas: renderCanvas, viewport, transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0] });
                await renderTask.promise;
                if (cancelled || !renderRequestRef.current.isCurrent(requestId)) return;
                const canvas = canvasRef.current;
                const context = canvas?.getContext('2d');
                if (!canvas || !context) throw new Error('PDF canvas is unavailable');
                canvas.width = renderCanvas.width;
                canvas.height = renderCanvas.height;
                canvas.style.width = `${Math.floor(viewport.width)}px`;
                canvas.style.height = `${Math.floor(viewport.height)}px`;
                context.drawImage(renderCanvas, 0, 0);
                setRendered({ pageNumber, zoom, fitScale: fit, key: `${pageNumber}:${zoom}:${fit}` });
                setRenderError(false);
            } catch (error) {
                if (!cancelled && renderRequestRef.current.isCurrent(requestId) && error?.name !== 'RenderingCancelledException') setRenderError(true);
            } finally {
                if (renderCanvas) {
                    renderCanvas.width = 0;
                    renderCanvas.height = 0;
                }
            }
        })();
        return () => {
            cancelled = true;
            renderRequestRef.current.invalidate(requestId);
            renderTask?.cancel();
        };
    }, [pdf, pageNumber, size, zoom]);

    const displayedPage = rendered?.pageNumber ?? pageNumber;
    const displayedScale = rendered ? rendered.fitScale * rendered.zoom : null;
    const isUpdating = status === 'ready' && (!rendered || rendered.pageNumber !== pageNumber || rendered.zoom !== zoom || renderError);

    useEffect(() => {
        const stage = stageRef.current;
        if (!stage) return undefined;
        const onWheel = event => {
            if (event.ctrlKey && status === 'ready') {
                event.preventDefault();
                const oldScale = rendered ? rendered.fitScale * rendered.zoom : (fitScale ?? 1) * zoomRef.current;
                const next = clampZoom(zoomRef.current * Math.exp(-event.deltaY * 0.002));
                if (next === zoomRef.current) return;
                const rect = stage.getBoundingClientRect();
                anchorRef.current = { x: event.clientX - rect.left, y: event.clientY - rect.top, scale: oldScale };
                setZoom(next);
            } else if (event.shiftKey && stage.scrollWidth > stage.clientWidth && event.deltaY !== 0) {
                event.preventDefault();
                stage.scrollLeft += event.deltaY;
            }
        };
        const onPointerDown = event => {
            if (event.pointerType === 'touch' || event.button !== 0 || !event.target.closest('canvas')) return;
            if (stage.scrollWidth <= stage.clientWidth && stage.scrollHeight <= stage.clientHeight) return;
            panRef.current = { id: event.pointerId, x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop };
            stage.setPointerCapture(event.pointerId);
            setPanning(true);
            event.preventDefault();
        };
        const onPointerMove = event => {
            if (panRef.current?.id !== event.pointerId) return;
            stage.scrollLeft = panRef.current.left - (event.clientX - panRef.current.x);
            stage.scrollTop = panRef.current.top - (event.clientY - panRef.current.y);
        };
        const endPan = event => {
            if (panRef.current?.id !== event.pointerId) return;
            if (stage.hasPointerCapture(event.pointerId)) stage.releasePointerCapture(event.pointerId);
            panRef.current = null;
            setPanning(false);
        };
        stage.addEventListener('wheel', onWheel, { passive: false });
        stage.addEventListener('pointerdown', onPointerDown);
        stage.addEventListener('pointermove', onPointerMove);
        stage.addEventListener('pointerup', endPan);
        stage.addEventListener('pointercancel', endPan);
        stage.addEventListener('lostpointercapture', endPan);
        return () => {
            stage.removeEventListener('wheel', onWheel);
            stage.removeEventListener('pointerdown', onPointerDown);
            stage.removeEventListener('pointermove', onPointerMove);
            stage.removeEventListener('pointerup', endPan);
            stage.removeEventListener('pointercancel', endPan);
            stage.removeEventListener('lostpointercapture', endPan);
            if (panRef.current && stage.hasPointerCapture(panRef.current.id)) stage.releasePointerCapture(panRef.current.id);
            panRef.current = null;
        };
    }, [status, fitScale, rendered]);

    useEffect(() => {
        const anchor = anchorRef.current;
        const stage = stageRef.current;
        if (!anchor || !stage || !rendered || rendered.zoom !== zoom) return undefined;
        anchorRef.current = null;
        const frame = requestAnimationFrame(() => {
            const ratio = (rendered.fitScale * rendered.zoom) / anchor.scale;
            stage.scrollLeft = zoomedScrollOffset(stage.scrollLeft, anchor.x, anchor.scale, anchor.scale * ratio);
            stage.scrollTop = zoomedScrollOffset(stage.scrollTop, anchor.y, anchor.scale, anchor.scale * ratio);
        });
        return () => cancelAnimationFrame(frame);
    }, [rendered, zoom]);

    const committed = rendered !== null;
    const canvasLabelPage = displayedPage;
    return <section className={`flex min-h-0 flex-1 flex-col ${className}`} aria-label={`${title} viewer`}>
        <div className="flex flex-none flex-wrap items-center justify-between gap-2 border-b border-gray-800 bg-gray-900 px-2.5 py-2 text-gray-100" aria-label="PDF controls">
            <div className="flex flex-wrap items-center gap-1">
                <button type="button" aria-label="Zoom out" title="Zoom out" disabled={status !== 'ready'} onClick={() => setZoom(value => nextZoom(value, -1))} className="h-8 w-8 rounded-md border border-gray-700 bg-gray-800 text-sm font-bold text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">−</button>
                <span className="min-w-12 px-1 text-center text-xs tabular-nums text-gray-200" aria-label="Zoom percentage" aria-live="polite">{displayedScale === null ? '—%' : `${Math.round(displayedScale * 100)}%`}</span>
                <button type="button" onClick={() => setZoom(1)} disabled={status !== 'ready'} className="h-8 rounded-md border border-gray-700 bg-gray-800 px-2.5 text-[11px] font-semibold text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">Fit Page</button>
                <button type="button" aria-label="Zoom in" title="Zoom in" disabled={status !== 'ready'} onClick={() => setZoom(value => nextZoom(value, 1))} className="h-8 w-8 rounded-md border border-gray-700 bg-gray-800 text-sm font-bold text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">+</button>
            </div>
            <div className="flex flex-wrap items-center gap-1.5 text-[11px] text-gray-200">
                <button type="button" aria-label="Previous page" disabled={status !== 'ready' || pageNumber <= 1} onClick={() => setPageNumber(value => nextPage(value, pageCount, -1))} className="h-8 rounded-md border border-gray-700 bg-gray-800 px-2.5 text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">Previous</button>
                <span aria-live="polite">Page {pageCount ? (committed ? displayedPage : pageNumber) : '—'} of {pageCount || '—'}</span>
                <button type="button" aria-label="Next page" disabled={status !== 'ready' || pageNumber >= pageCount} onClick={() => setPageNumber(value => nextPage(value, pageCount, 1))} className="h-8 rounded-md border border-gray-700 bg-gray-800 px-2.5 text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">Next</button>
            </div>
        </div>
        <div ref={stageRef} className={`relative min-h-[260px] min-w-0 flex-1 overflow-auto bg-gray-950 p-4 ${panning ? 'cursor-grabbing' : committed ? 'cursor-grab' : ''}`} role="region" aria-label="PDF page" style={{ touchAction: 'pan-x pan-y' }}>
            <div className="flex min-h-full min-w-full w-max items-start justify-start">
                {status === 'loading' && <p role="status" className="m-auto text-sm text-gray-300">Preparing PDF preview…</p>}
                {status === 'error' && !committed && <p role="alert" className="m-auto text-sm text-red-300">This PDF could not be rendered. Download the current copy to inspect it.</p>}
                <canvas ref={canvasRef} className={committed ? 'm-auto block max-w-none shrink-0 border border-gray-300 bg-white shadow-lg' : 'hidden'} aria-label={`${title}, page ${canvasLabelPage} of ${pageCount}`} />
            </div>
            {isUpdating && <span role="status" className="pointer-events-none absolute bottom-2 left-2 z-10 rounded-md bg-gray-800/95 px-2 py-1 text-xs text-gray-100 shadow">{renderError ? 'Unable to render requested view' : `Updating page ${pageNumber}…`}</span>}
        </div>
    </section>;
}
