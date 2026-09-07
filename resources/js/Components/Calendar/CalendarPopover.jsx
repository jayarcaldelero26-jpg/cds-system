import { createPortal } from 'react-dom';
import { useEffect, useState } from 'react';

export default function CalendarPopover({ open, anchorRef, popoverRef, children, wide = false }) {
    const [position, setPosition] = useState({ top: 0, left: 0 });

    useEffect(() => {
        if (!open) return undefined;
        const updatePosition = () => {
            const anchor = anchorRef.current;
            if (!anchor) return;
            const rect = anchor.getBoundingClientRect();
            const width = wide ? Math.min(680, window.innerWidth - 16) : Math.min(340, window.innerWidth - 16);
            const estimatedHeight = wide ? 520 : 480;
            const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
            const below = rect.bottom + 8;
            const top = below + estimatedHeight <= window.innerHeight ? below : Math.max(8, rect.top - estimatedHeight - 8);
            setPosition({ top, left });
        };
        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
        return () => { window.removeEventListener('resize', updatePosition); window.removeEventListener('scroll', updatePosition, true); };
    }, [anchorRef, open, wide]);

    if (!open || typeof document === 'undefined') return null;
    return createPortal(<div ref={popoverRef} role='dialog' aria-label='Calendar date picker' style={{ top: position.top, left: position.left }} className={wide ? 'fixed z-[70] max-h-[min(78vh,560px)] overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900 w-[min(680px,calc(100vw-16px))]' : 'fixed z-[70] max-h-[min(78vh,560px)] overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900 w-[min(340px,calc(100vw-16px))]'}>
        {children}
    </div>, document.body);
}
