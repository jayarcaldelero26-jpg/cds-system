import { Icon } from '@iconify/react';
import { createPortal } from 'react-dom';
import { useRef } from 'react';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';

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
}) {
    const panelRef = useRef(null);
    const overlayRef = useRef(null);
    const { present, phase, onTransitionEnd } = useModalMotion(open, {
        onClose,
        panelRef,
        layerRef: overlayRef,
        initialFocusSelector,
        closeOnEscape,
    });

    if (!present || typeof document === 'undefined') return null;

    const panelSizing = expanded
        ? 'h-[100dvh] max-h-[100dvh] w-screen max-w-none rounded-none'
        : 'h-[calc(100dvh-16px)] max-h-[calc(100dvh-16px)] w-[min(960px,calc(100vw-16px))] max-w-[960px] rounded-xl sm:h-[calc(100dvh-40px)] sm:max-h-[calc(100dvh-40px)] sm:w-[min(960px,calc(100vw-32px))] sm:rounded-2xl';

    return createPortal(<div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className={`cds-modal-motion-backdrop fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/65 backdrop-blur-sm ${expanded ? 'p-0' : 'p-2 sm:p-4'}`} role="presentation">
        <section ref={panelRef} onTransitionEnd={onTransitionEnd} role="dialog" aria-modal="true" aria-label={title || 'Document reader'} tabIndex={-1} data-motion-state={phase} className={`cds-modal-motion-panel relative flex min-h-0 flex-col overflow-hidden border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900 ${panelSizing}`}>
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
                        {tabs.map(tab => <button key={tab.value} type="button" disabled={closeDisabled} id={`${idPrefix}-tab-${tab.value}`} role="tab" aria-controls={`${idPrefix}-tabpanel-${tab.value}`} aria-selected={activeTab === tab.value} onClick={() => onTabChange?.(tab.value)} className={`min-h-8 rounded-lg px-3 py-1.5 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 disabled:opacity-50 ${activeTab === tab.value ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'}`}>{tab.label}</button>)}
                    </div>
                </div>}
                {tabs.map(tab => <div key={tab.value} role="tabpanel" id={`${idPrefix}-tabpanel-${tab.value}`} aria-labelledby={tabs.length > 1 ? `${idPrefix}-tab-${tab.value}` : undefined} aria-label={tabs.length === 1 ? tab.label : undefined} hidden={activeTab !== tab.value} className={`${activeTab === tab.value ? 'flex' : 'hidden'} min-h-0 flex-1 flex-col overflow-hidden`}>
                    {panels[tab.value]}
                </div>)}
            </div>
            {busyOverlay}
        </section>
    </div>, document.body);
}
