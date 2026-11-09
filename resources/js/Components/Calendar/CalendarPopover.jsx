import { createPortal } from 'react-dom';
import { useLayoutEffect, useState } from 'react';

export default function CalendarPopover({ open, anchorRef, popoverRef, children, wide = false }) {
    const [position, setPosition] = useState(null);

    useLayoutEffect(() => {
        if (!open) return undefined;
        const updatePosition = () => {
            const anchor = anchorRef.current;
            if (!anchor) return;
            const rect = anchor.getBoundingClientRect();
            const width = wide ? Math.min(680, window.innerWidth - 16) : Math.min(340, window.innerWidth - 16);
            const estimatedHeight = wide ? 520 : 480;
            const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
            const below = rect.bottom + 8;
            const desiredTop = below + estimatedHeight <= window.innerHeight ? below : rect.top - estimatedHeight - 8;
            const top = Math.max(8, Math.min(desiredTop, window.innerHeight - estimatedHeight - 8));
            const owner = anchor.closest('.cds-filter-panel-enter, .cds-filter-panel, .cds-filter-dropdown');
            const ownerZ = owner ? Number.parseInt(window.getComputedStyle(owner).zIndex, 10) || 120 : 70;
            setPosition({ top, left, zIndex: owner ? ownerZ + 10 : 70 });
        };
        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
        return () => { window.removeEventListener('resize', updatePosition); window.removeEventListener('scroll', updatePosition, true); };
    }, [anchorRef, open, wide]);

    if (!open || !position || typeof document === 'undefined') return null;
    return createPortal(<div ref={popoverRef} role='dialog' aria-label='Calendar date picker' style={{ top: position.top, left: position.left, zIndex: position.zIndex }} className={wide ? 'cds-menu-panel fixed z-[70] max-h-[min(78vh,560px)] overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900 w-[min(680px,calc(100vw-16px))]' : 'cds-menu-panel fixed z-[70] max-h-[min(78vh,560px)] overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900 w-[min(340px,calc(100vw-16px))]'}>
        {children}
    </div>, document.body);
}
