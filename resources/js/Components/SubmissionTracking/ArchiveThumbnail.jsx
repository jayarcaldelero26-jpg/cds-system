import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import { GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { archiveThumbnailLoader } from '@/Utils/archiveThumbnailRuntime.mjs';

GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

function ArchiveThumbnail({ identity, scopeKey, url, type, filename, kind }) {
    const elementRef = useRef(null);
    const [state, setState] = useState({ identity: null, scopeKey: null, status: 'idle', objectUrl: null });
    const visibleState = state.identity === identity && state.scopeKey === scopeKey
        ? state
        : { status: 'idle', objectUrl: null };

    useEffect(() => {
        archiveThumbnailLoader.activateScope(scopeKey);
        setState({ identity, scopeKey, status: 'idle', objectUrl: null });
        if (!identity || !scopeKey || !url || !type || typeof IntersectionObserver === 'undefined') {
            setState({ identity, scopeKey, status: 'fallback', objectUrl: null });
            return undefined;
        }

        let active = true;
        let started = false;
        let lease = null;
        let objectUrl = null;
        const observer = new IntersectionObserver(entries => {
            if (started || !entries.some(entry => entry.isIntersecting)) return;
            started = true;
            observer.disconnect();
            setState({ identity, scopeKey, status: 'loading', objectUrl: null });
            lease = archiveThumbnailLoader.request({ scopeKey, key: identity, url, type });
            lease.promise.then(blob => {
                if (!active) return;
                objectUrl = URL.createObjectURL(blob);
                setState({ identity, scopeKey, status: 'ready', objectUrl });
            }).catch(error => {
                if (!active || error?.name === 'AbortError') return;
                setState({ identity, scopeKey, status: 'fallback', objectUrl: null });
            });
        }, { rootMargin: '240px 80px' });

        if (elementRef.current) observer.observe(elementRef.current);
        return () => {
            active = false;
            observer.disconnect();
            lease?.release();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [identity, scopeKey, url, type]);

    return <span ref={elementRef} role="img" aria-label={`${visibleState.status === 'ready' ? 'First page' : 'Document'} thumbnail: ${filename || kind.label}`} data-archive-thumbnail={visibleState.status} className="flex h-[148px] w-full items-center justify-center overflow-hidden rounded-xl border border-slate-100 bg-white dark:border-slate-800 dark:bg-slate-950">
        {visibleState.status === 'ready' && visibleState.objectUrl
            ? <img src={visibleState.objectUrl} alt="" className="h-full w-full object-contain" />
            : <span className={`flex flex-col items-center gap-2 ${visibleState.status === 'loading' ? 'animate-pulse opacity-70' : ''}`} aria-hidden="true">
                <Icon icon={kind.icon} width="42" height="42" className={kind.tone.split(' ')[0]} />
                <span className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-bold tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">{kind.label}</span>
            </span>}
    </span>;
}

ArchiveThumbnail.activateScope = scopeKey => archiveThumbnailLoader.activateScope(scopeKey);

export default ArchiveThumbnail;
