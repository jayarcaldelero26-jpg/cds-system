import { Icon } from '@iconify/react';
import { createPortal } from 'react-dom';
import { useEffect, useRef, useState } from 'react';
import AppliedFilterChip from './AppliedFilterChip';

export default function FilterToolbar({ label = 'Filters', chips = [], onClear, children, className = '', panelClassName = '' }) {
    const triggerRef = useRef(null);
    const panelRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [style, setStyle] = useState(null);

    useEffect(() => {
        if (!open) return undefined;
        const position = () => {
            const rect = triggerRef.current?.getBoundingClientRect();
            if (!rect) return;
            const margin = 12;
            const width = Math.min(620, window.innerWidth - margin * 2);
            const left = window.innerWidth < 768 ? margin : Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin));
            const top = Math.min(rect.bottom + 8, Math.max(margin, window.innerHeight - 200));
            setStyle({ position: 'fixed', left, top, width, maxHeight: `min(80vh, ${window.innerHeight - top - margin}px)` });
        };
        const keydown = event => {
            if (event.key === 'Escape') {
                event.preventDefault(); setOpen(false); requestAnimationFrame(() => triggerRef.current?.focus());
            }
        };
        const pointerdown = event => {
            const target = event.target;
            if (panelRef.current?.contains(target) || triggerRef.current?.contains(target) || target.closest?.('.cds-select-menu, .cds-filter-dropdown, .cds-menu-panel')) return;
            setOpen(false);
        };
        position();
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);
        document.addEventListener('keydown', keydown);
        document.addEventListener('pointerdown', pointerdown, true);
        return () => {
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', position, true);
            document.removeEventListener('keydown', keydown);
            document.removeEventListener('pointerdown', pointerdown, true);
        };
    }, [open]);

    const panel = open && typeof document !== 'undefined' ? createPortal(
        <section ref={panelRef} id="shared-filter-panel" role="region" aria-label={`${label} options`} style={style || { position: 'fixed', left: 12, top: 64, width: 'min(620px, calc(100vw - 24px))' }} className={`cds-filter-panel-enter z-[120] overflow-y-auto rounded-2xl border border-gray-200 bg-white p-4 shadow-2xl dark:border-gray-700 dark:bg-gray-900 ${panelClassName}`}>
            <header className="mb-3 flex items-center justify-between gap-3">
                <h2 className="text-sm font-bold text-gray-900 dark:text-white">{label}</h2>
                <button type="button" onClick={() => { setOpen(false); requestAnimationFrame(() => triggerRef.current?.focus()); }} className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:text-gray-300 dark:hover:bg-gray-800" aria-label={`Close ${label.toLowerCase()}`}><Icon icon="lucide:x" width="16" height="16" aria-hidden="true" /></button>
            </header>
            {children}
        </section>, document.body) : null;

    return <div className={`min-w-0 ${className}`}>
        <div className="flex min-w-0 flex-wrap items-center gap-2">
            <button ref={triggerRef} type="button" aria-expanded={open} aria-controls="shared-filter-panel" onClick={() => setOpen(value => !value)} className="inline-flex h-10 min-w-[7rem] items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-800 hover:border-green-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <Icon icon="lucide:sliders-horizontal" width="16" height="16" aria-hidden="true" className={`transition-transform duration-200 motion-reduce:transition-none ${open ? 'rotate-180' : ''}`} />
                <span className="transition-opacity duration-200 motion-reduce:transition-none">{chips.length ? `${label} · ${chips.length} selected` : label}</span>
            </button>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5" aria-label="Applied filters" aria-live="polite">
                {chips.map(chip => <AppliedFilterChip key={chip.key} label={chip.label} value={chip.value} onRemove={chip.onRemove} />)}
            </div>
            {onClear && chips.length > 0 && <button type="button" onClick={onClear} className="min-h-9 shrink-0 rounded-md px-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700 dark:text-gray-300 dark:hover:bg-gray-800">Clear all</button>}
        </div>
        {panel}
    </div>;
}
