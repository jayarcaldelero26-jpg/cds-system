import { useEffect, useRef } from 'react';

export default function SuccessDialog({ open, message, title = 'Success!', onClose }) {
    const panelRef = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        const previouslyFocused = document.activeElement;
        const focusFrame = window.requestAnimationFrame(() => panelRef.current?.focus());
        const closeOnEscape = event => {
            if (event.key === 'Escape') onClose?.();
        };

        window.addEventListener('keydown', closeOnEscape);
        return () => {
            window.cancelAnimationFrame(focusFrame);
            window.removeEventListener('keydown', closeOnEscape);
            previouslyFocused?.focus?.();
        };
    }, [open, onClose]);

    if (!open) return null;

    return <div className="fixed inset-0 z-[100] flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs" role="dialog" aria-modal="true" aria-labelledby="success-dialog-title" onMouseDown={event => event.target === event.currentTarget && onClose?.()}>
        <style>{`
            @keyframes success-dialog-pop-in {
                from { opacity: 0; transform: scale(.97) translateY(4px); }
                to { opacity: 1; transform: scale(1) translateY(0); }
            }
            @keyframes success-circle-scale {
                from { opacity: 0; transform: scale(.72); }
                to { opacity: 1; transform: scale(1); }
            }
            @keyframes success-check-stroke {
                from { stroke-dashoffset: 24; }
                to { stroke-dashoffset: 0; }
            }
            .success-dialog-panel { animation: success-dialog-pop-in 180ms ease-out both; }
            .success-dialog-circle { animation: success-circle-scale 220ms 40ms cubic-bezier(.2,.8,.2,1) both; }
            .success-dialog-check {
                stroke-dasharray: 24;
                stroke-dashoffset: 24;
                animation: success-check-stroke 280ms 150ms ease-out forwards;
            }
            @media (prefers-reduced-motion: reduce) {
                .success-dialog-panel,
                .success-dialog-circle,
                .success-dialog-check { animation: none !important; }
                .success-dialog-check { stroke-dashoffset: 0; }
            }
        `}</style>
        <div ref={panelRef} tabIndex={-1} className="success-dialog-panel w-full max-w-sm rounded-2xl border border-emerald-100 bg-white p-6 text-center shadow-2xl outline-none dark:border-emerald-900 dark:bg-gray-900">
            <div className="success-dialog-circle mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 shadow-sm dark:bg-emerald-950">
                <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor" aria-hidden="true"><path className="success-dialog-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
            </div>
            <h2 id="success-dialog-title" className="mb-2 text-lg font-bold text-gray-900 dark:text-white">{title}</h2>
            <p className="mb-6 text-sm text-gray-600 dark:text-gray-300">{message}</p>
            <button type="button" onClick={onClose} className="w-full rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">Continue</button>
        </div>
    </div>;
}
