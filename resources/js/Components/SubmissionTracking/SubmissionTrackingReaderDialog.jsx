import { Icon } from '@iconify/react';
import { useLayoutEffect, useRef, useState } from 'react';
import Modal from '@/Components/Modal';
import { MODAL_MOTION_PRESETS } from '@/Components/ModalMotion/presets.mjs';
import { fixedBottomOverlayInset } from '@/Utils/fixedBottomOverlayInset.mjs';

export default function SubmissionTrackingReaderDialog({
    open,
    title,
    context = '',
    tabs = [],
    activeTab = 'document',
    onTabChange,
    panels = {},
    expanded = false,
    onExpandedChange,
    onClose,
    closeDisabled = false,
    contentInert = false,
    busyOverlay = null,
    closeOnEscape = true,
    idPrefix = 'tracking-reader',
    initialFocusSelector = '[data-reader-close]',
    avoidBottomDebugbar = false,
}) {
    const panelRef = useRef(null);
    const overlayRef = useRef(null);
    const [bottomObstruction, setBottomObstruction] = useState(0);
    useLayoutEffect(() => {
        if (!avoidBottomDebugbar || typeof document === 'undefined') {
            setBottomObstruction(0);
            return undefined;
        }
        if (!open) return undefined;

        const toolbar = document.querySelector('.phpdebugbar');
        if (!toolbar || !panelRef.current) {
            setBottomObstruction(0);
            return undefined;
        }

        const measure = () => {
            const toolbarStyle = window.getComputedStyle(toolbar);
            const toolbarRect = toolbar.getBoundingClientRect();
            const panelRect = panelRef.current?.getBoundingClientRect();
            const visible = toolbarStyle.position === 'fixed'
                && toolbarStyle.display !== 'none'
                && toolbarStyle.visibility !== 'hidden'
                && Number(toolbarStyle.opacity || 1) > 0;
            const nextInset = fixedBottomOverlayInset({
                toolbarRect,
                panelRect,
                viewportHeight: window.innerHeight,
                visible,
            });
            setBottomObstruction(current => current === nextInset ? current : nextInset);
        };

        measure();
        const resizeObserver = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);
        resizeObserver?.observe(toolbar);
        const mutationObserver = typeof MutationObserver === 'undefined' ? null : new MutationObserver(measure);
        mutationObserver?.observe(toolbar, { attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
        window.addEventListener('resize', measure);

        return () => {
            resizeObserver?.disconnect();
            mutationObserver?.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, [open, avoidBottomDebugbar, expanded]);

    const panelSizing = expanded
        ? 'h-[calc(100dvh_-_var(--reader-bottom-obstruction))] max-h-[calc(100dvh_-_var(--reader-bottom-obstruction))] w-screen max-w-none rounded-none'
        : 'h-[calc(100dvh_-_var(--reader-bottom-obstruction)_-_16px)] max-h-[calc(100dvh_-_var(--reader-bottom-obstruction)_-_16px)] w-[min(960px,calc(100vw-16px))] max-w-[960px] rounded-xl sm:h-[calc(100dvh_-_var(--reader-bottom-obstruction)_-_40px)] sm:max-h-[calc(100dvh_-_var(--reader-bottom-obstruction)_-_40px)] sm:w-[min(960px,calc(100vw-32px))] sm:rounded-2xl';
    const overlayStyle = {
        '--reader-bottom-obstruction': `${avoidBottomDebugbar ? bottomObstruction : 0}px`,
        ...(avoidBottomDebugbar ? { bottom: `${bottomObstruction}px` } : {}),
    };

    return <Modal open={open} title={title || 'Document reader'} motion={MODAL_MOTION_PRESETS.reader} onClose={onClose} closeOnEscape={closeOnEscape} initialFocusSelector={initialFocusSelector} panelRef={panelRef} layerRef={overlayRef} placement={expanded ? 'fullscreen' : 'center'} zIndex="z-[70]" backdropStyle={overlayStyle} wrapperClassName={expanded ? 'p-0' : 'p-2 sm:p-4'} backdropClassName="bg-slate-950/65 backdrop-blur-sm" panelClassName={`relative flex min-h-0 flex-col overflow-hidden border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900 ${panelSizing}`} panelProps={{ inert: contentInert || undefined, 'aria-hidden': contentInert || undefined }} ariaLabel={title || 'Document reader'}>
            <div inert={contentInert || undefined} aria-hidden={contentInert || undefined} className="flex min-h-0 flex-1 flex-col">
                <header className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 px-3 py-2.5 sm:px-4 dark:border-slate-700">
                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-sm font-semibold text-slate-900 dark:text-white" title={title}>{title || 'Document'}</h2>
                        {context && <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400" title={context}>{context}</p>}
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        <button type="button" disabled={closeDisabled} onClick={() => onExpandedChange?.(!expanded)} aria-label={expanded ? 'Restore reader size' : 'Expand reader'} title={expanded ? 'Restore size' : 'Expand'} className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 disabled:opacity-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <Icon icon={expanded ? 'lucide:shrink' : 'lucide:expand'} width="17" height="17" aria-hidden="true" />
                        </button>
                        <button type="button" onClick={onClose} disabled={closeDisabled} data-reader-close data-preview-close data-archive-reader-close aria-label="Close reader" title="Close" className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 disabled:opacity-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <Icon icon="lucide:x" width="18" height="18" aria-hidden="true" />
                        </button>
                    </div>
                </header>
                {tabs.length > 1 && <div className="flex shrink-0 items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900">
                    <div role="tablist" aria-label="Document sections" className="flex min-w-0 flex-wrap gap-1">
                        {tabs.map(tab => <button key={tab.value} type="button" disabled={closeDisabled} id={`${idPrefix}-tab-${tab.value}`} role="tab" aria-controls={`${idPrefix}-tabpanel-${tab.value}`} aria-selected={activeTab === tab.value} onClick={() => onTabChange?.(tab.value)} className={`min-h-8 rounded-lg px-3 py-1.5 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 disabled:opacity-50 ${activeTab === tab.value ? 'cds-tab-active' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{tab.label}</button>)}
                    </div>
                </div>}
                {tabs.map(tab => <div key={tab.value} role="tabpanel" id={`${idPrefix}-tabpanel-${tab.value}`} aria-labelledby={tabs.length > 1 ? `${idPrefix}-tab-${tab.value}` : undefined} aria-label={tabs.length === 1 ? tab.label : undefined} hidden={activeTab !== tab.value} className={`${activeTab === tab.value ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col overflow-hidden`}>
                    {panels[tab.value]}
                </div>)}
            </div>
            {busyOverlay}
    </Modal>;
}
