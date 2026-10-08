import { useCallback, useEffect, useRef, useState } from 'react';
import { GlobalWorkerOptions, getDocument } from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { fitPageScale, nearestPageToViewportCenter, nextPage, nextZoom, zoomedScrollOffset } from '@/Utils/pdfViewerGeometry.mjs';
import { createRenderRequestIdentity } from '@/Utils/pdfViewerRequests.mjs';

GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const clampZoom = value => Math.min(4, Math.max(0.25, value));
const FALLBACK_PAGE_SIZE = { width: 612, height: 792 };
const MAX_CANVAS_EDGE = 4096;
const MAX_CANVAS_PIXELS = 6_000_000;

function outputScaleFor(width, height) {
    const requested = Math.min(globalThis.devicePixelRatio || 1, 2);
    const pixelLimit = Math.sqrt(MAX_CANVAS_PIXELS / Math.max(1, width * height));
    return Math.max(0.1, Math.min(requested, MAX_CANVAS_EDGE / width, MAX_CANVAS_EDGE / height, pixelLimit));
}

/** Renders only pages near the viewport and keeps a completed canvas during replacement renders. */
function PdfPageCanvas({ pdf, pageNumber, pageCount, active, scale, fallbackSize, onPageSize }) {
    const canvasRef = useRef(null);
    const requestRef = useRef(null);
    if (requestRef.current === null) requestRef.current = createRenderRequestIdentity();
    const [renderedKey, setRenderedKey] = useState(null);
    const [renderError, setRenderError] = useState(false);
    const [actualSize, setActualSize] = useState(null);
    const pageSize = actualSize || fallbackSize || FALLBACK_PAGE_SIZE;
    const cssWidth = Math.max(1, Math.round(pageSize.width * scale));
    const cssHeight = Math.max(1, Math.round(pageSize.height * scale));
    const currentKey = Math.round(scale * 10000) + ':' + cssWidth + ':' + cssHeight;

    useEffect(() => {
        setRenderedKey(null);
        setRenderError(false);
        setActualSize(null);
    }, [pdf]);

    useEffect(() => {
        const requestId = requestRef.current.begin();
        let cancelled = false;
        let renderTask;
        let renderCanvas;
        if (!pdf || !active || !scale) return undefined;

        (async () => {
            try {
                const page = await pdf.getPage(pageNumber);
                if (cancelled || !requestRef.current.isCurrent(requestId)) return;
                const natural = page.getViewport({ scale: 1 });
                const measured = { width: natural.width, height: natural.height };
                setActualSize(measured);
                onPageSize(pageNumber, measured);
                const viewport = page.getViewport({ scale });
                const outputScale = outputScaleFor(viewport.width, viewport.height);
                renderCanvas = document.createElement('canvas');
                renderCanvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
                renderCanvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
                renderTask = page.render({
                    canvas: renderCanvas,
                    viewport,
                    transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
                });
                await renderTask.promise;
                if (cancelled || !requestRef.current.isCurrent(requestId)) return;
                const canvas = canvasRef.current;
                const context = canvas?.getContext('2d');
                if (!canvas || !context) throw new Error('PDF canvas is unavailable');
                canvas.width = renderCanvas.width;
                canvas.height = renderCanvas.height;
                canvas.style.width = Math.floor(viewport.width) + 'px';
                canvas.style.height = Math.floor(viewport.height) + 'px';
                context.drawImage(renderCanvas, 0, 0);
                setRenderedKey(currentKey);
                setRenderError(false);
            } catch (error) {
                if (!cancelled && requestRef.current.isCurrent(requestId) && error?.name !== 'RenderingCancelledException') setRenderError(true);
            } finally {
                if (renderCanvas) {
                    renderCanvas.width = 0;
                    renderCanvas.height = 0;
                }
            }
        })();

        return () => {
            cancelled = true;
            requestRef.current.invalidate(requestId);
            renderTask?.cancel();
        };
    }, [pdf, pageNumber, active, scale, currentKey, onPageSize]);

    useEffect(() => {
        if (active || renderedKey === null) return;
        const canvas = canvasRef.current;
        if (canvas) {
            canvas.width = 0;
            canvas.height = 0;
            canvas.style.width = '';
            canvas.style.height = '';
        }
        setRenderedKey(null);
    }, [active, renderedKey]);

    const committed = renderedKey !== null;
    return <div className="relative flex flex-none justify-center" data-pdf-page={pageNumber} style={{ width: '100%', height: cssHeight + 'px' }}>
        <canvas ref={canvasRef} className={committed ? 'block max-w-none shrink-0 border border-gray-300 bg-white shadow-lg' : 'hidden'} aria-label={pageNumber + ' of ' + pageCount} />
        {active && renderError && !committed && <p role="alert" className="absolute inset-0 flex items-center justify-center text-sm text-red-300">This page could not be rendered. Retry the preview or download the current copy.</p>}
        {active && pdf && (!committed || renderedKey !== currentKey || renderError) && <span role="status" className="pointer-events-none absolute bottom-2 left-2 rounded-md bg-gray-800/95 px-2 py-1 text-xs text-gray-100 shadow">{renderError ? 'Unable to render page ' + pageNumber : 'Updating page ' + pageNumber + '…'}</span>}
    </div>;
}

export default function PdfDocumentViewer({ blob, title = 'PDF document', className = '' }) {
    const stageRef = useRef(null);
    const pageNodesRef = useRef(new Map());
    const zoomRef = useRef(1);
    const scaleRef = useRef(1);
    const panRef = useRef(null);
    const anchorRef = useRef(null);
    const [pdf, setPdf] = useState(null);
    const [pageNumber, setPageNumber] = useState(1);
    const [pageCount, setPageCount] = useState(0);
    const [pageSizes, setPageSizes] = useState({});
    const [pageInput, setPageInput] = useState('1');
    const [zoom, setZoomState] = useState(1);
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
    const onPageSize = useCallback((number, nextSize) => {
        setPageSizes(current => {
            const old = current[number];
            if (old && old.width === nextSize.width && old.height === nextSize.height) return current;
            return { ...current, [number]: nextSize };
        });
    }, []);

    useEffect(() => {
        if (!blob) {
            setPdf(null);
            setPageNumber(1);
            setPageCount(0);
            setPageSizes({});
            setZoom(1);
            setStatus('error');
            return undefined;
        }
        let disposed = false;
        let loadingTask;
        setPdf(null);
        setPageNumber(1);
        setPageCount(0);
        setPageSizes({});
        setZoom(1);
        setStatus('loading');
        (async () => {
            try {
                const data = new Uint8Array(await blob.arrayBuffer());
                if (disposed) return;
                loadingTask = getDocument({ data });
                const document = await loadingTask.promise;
                if (disposed) return;
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

    const activeSize = pageSizes[pageNumber] || pageSizes[1] || FALLBACK_PAGE_SIZE;
    const fitScale = size.width && size.height
        ? fitPageScale(activeSize.width, activeSize.height, Math.max(1, size.width - 32), Math.max(1, size.height - 32))
        : 1;
    const scale = fitScale * zoom;
    scaleRef.current = scale;

    const scrollToPage = number => {
        const next = nextPage(number, pageCount, 0);
        setPageNumber(next);
        pageNodesRef.current.get(next)?.scrollIntoView?.({ block: 'start', behavior: 'auto' });
    };

    const commitPageInput = () => {
        const requested = Number(pageInput);
        if (Number.isInteger(requested) && requested >= 1 && requested <= pageCount) {
            scrollToPage(requested);
        } else {
            setPageInput(pageCount ? String(pageNumber) : '');
        }
    };

    useEffect(() => {
        setPageInput(pageCount ? String(pageNumber) : '');
    }, [pageNumber, pageCount]);

    useEffect(() => {
        const stage = stageRef.current;
        if (!stage) return undefined;
        let frame = null;
        const updateCurrentPage = () => {
            frame = null;
            const stageRect = stage.getBoundingClientRect();
            const pagesInView = [];
            for (const [number, node] of pageNodesRef.current) {
                const rect = node.getBoundingClientRect();
                pagesInView.push({ number, top: rect.top, bottom: rect.bottom });
            }
            const nearestPage = nearestPageToViewportCenter(pagesInView, stageRect.top, stage.clientHeight);
            if (nearestPage !== null) setPageNumber(current => current === nearestPage ? current : nearestPage);
        };
        const schedule = () => {
            if (frame === null) frame = requestAnimationFrame(updateCurrentPage);
        };
        stage.addEventListener('scroll', schedule, { passive: true });
        schedule();
        return () => {
            stage.removeEventListener('scroll', schedule);
            if (frame !== null) cancelAnimationFrame(frame);
        };
    }, [pageCount]);

    useEffect(() => {
        const stage = stageRef.current;
        if (!stage) return undefined;
        const onWheel = event => {
            if (!event.ctrlKey || status !== 'ready') return;
            event.preventDefault();
            const oldScale = scaleRef.current;
            const next = clampZoom(zoomRef.current * Math.exp(-event.deltaY * 0.002));
            if (next === zoomRef.current) return;
            const rect = stage.getBoundingClientRect();
            anchorRef.current = { x: event.clientX - rect.left, y: event.clientY - rect.top, scale: oldScale };
            setZoom(next);
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
    }, [status]);

    useEffect(() => {
        const anchor = anchorRef.current;
        const stage = stageRef.current;
        if (!anchor || !stage || !scale) return undefined;
        anchorRef.current = null;
        const frame = requestAnimationFrame(() => {
            stage.scrollLeft = zoomedScrollOffset(stage.scrollLeft, anchor.x, anchor.scale, scale);
            stage.scrollTop = zoomedScrollOffset(stage.scrollTop, anchor.y, anchor.scale, scale);
        });
        return () => cancelAnimationFrame(frame);
    }, [scale]);

    const handleKeyDown = event => {
        if (event.target !== stageRef.current || !pageCount) return;
        let target = null;
        if (['ArrowDown', 'ArrowRight', 'PageDown', ' '].includes(event.key)) target = nextPage(pageNumber, pageCount, 1);
        if (['ArrowUp', 'ArrowLeft', 'PageUp'].includes(event.key)) target = nextPage(pageNumber, pageCount, -1);
        if (event.key === 'Home') target = 1;
        if (event.key === 'End') target = pageCount;
        if (target === null) return;
        event.preventDefault();
        scrollToPage(target);
    };

    const committedPages = Object.keys(pageSizes).map(Number);
    const largestWidth = Math.max(1, ...committedPages.map(number => pageSizes[number]?.width || 1));
    const contentWidth = Math.max(size.width - 32, largestWidth * scale);
    const pages = Array.from({ length: pageCount }, (_, index) => index + 1);
    return <section className={'flex min-h-0 flex-1 flex-col ' + className} aria-label={title + ' viewer'}>
        <div className="flex flex-none flex-wrap items-center justify-between gap-2 border-b border-gray-800 bg-gray-900 px-2.5 py-2 text-gray-100" aria-label="PDF controls">
            <div className="flex flex-wrap items-center gap-1">
                <button type="button" aria-label="Zoom out" title="Zoom out" disabled={status !== 'ready'} onClick={() => setZoom(value => nextZoom(value, -1))} className="h-8 w-8 rounded-md border border-gray-700 bg-gray-800 text-sm font-bold text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">−</button>
                <span className="min-w-12 px-1 text-center text-xs tabular-nums text-gray-200" aria-label="Zoom percentage" aria-live="polite">{status === 'ready' ? Math.round(scale * 100) + '%' : '—%'}</span>
                <button type="button" onClick={() => setZoom(1)} disabled={status !== 'ready'} className="h-8 rounded-md border border-gray-700 bg-gray-800 px-2.5 text-[11px] font-semibold text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">Fit Page</button>
                <button type="button" aria-label="Zoom in" title="Zoom in" disabled={status !== 'ready'} onClick={() => setZoom(value => nextZoom(value, 1))} className="h-8 w-8 rounded-md border border-gray-700 bg-gray-800 text-sm font-bold text-gray-100 hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50">+</button>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-[11px] text-gray-200">
                <label htmlFor="pdf-page-number" className="sr-only">Go to page</label>
                <input id="pdf-page-number" type="number" aria-label="Go to page" min="1" max={pageCount || 1} value={pageInput} disabled={status !== 'ready'} onChange={event => setPageInput(event.target.value)} onKeyDown={event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        commitPageInput();
                    }
                }} onBlur={commitPageInput} className="h-8 w-16 rounded-md border border-gray-700 bg-gray-800 px-2 text-center text-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 disabled:opacity-50" />
                <span aria-live="polite">Page {pageCount ? pageNumber : '—'} of {pageCount || '—'}</span>
            </div>
        </div>
        <div ref={stageRef} onKeyDown={handleKeyDown} tabIndex={0} aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight PageUp PageDown Home End" className={'relative min-h-[260px] min-w-0 flex-1 overflow-auto bg-gray-950 p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-400 ' + (panning ? 'cursor-grabbing' : pdf ? 'cursor-grab' : '')} role="region" aria-label="PDF pages" style={{ touchAction: 'pan-x pan-y' }}>
            <div className="min-h-full min-w-full space-y-5" style={{ width: Math.max(1, contentWidth) + 'px' }}>
                {status === 'loading' && <p role="status" className="flex min-h-[260px] items-center justify-center text-sm text-gray-300">Preparing PDF preview…</p>}
                {status === 'error' && <p role="alert" className="flex min-h-[260px] items-center justify-center text-sm text-red-300">This PDF could not be rendered. Download the current copy to inspect it.</p>}
                {status === 'ready' && pages.map(number => {
                    const active = Math.abs(number - pageNumber) <= 1;
                    const knownSize = pageSizes[number] || pageSizes[1] || FALLBACK_PAGE_SIZE;
                    const ref = node => {
                        if (node) pageNodesRef.current.set(number, node);
                        else pageNodesRef.current.delete(number);
                    };
                    return <div key={number} ref={ref} data-page-container={number} aria-label={title + ', page ' + number + ' of ' + pageCount} className="w-full">
                        <PdfPageCanvas
                            key={number}
                            pdf={pdf}
                            pageNumber={number}
                            pageCount={pageCount}
                            active={active}
                            scale={scale}
                            fallbackSize={knownSize}
                            onPageSize={onPageSize}
                        />
                    </div>;
                })}
            </div>
        </div>
    </section>;
}
