import { useEffect } from 'react';

export default function Modal({ open, onClose, title, children, footer, size = 'md' }) {
    useEffect(() => { const closeOnEscape = (event) => { if (event.key === 'Escape') onClose?.(); }; if (open) document.addEventListener('keydown', closeOnEscape); return () => document.removeEventListener('keydown', closeOnEscape); }, [open, onClose]);
    if (!open) return null;
    const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl' };
    return <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="modal-title"><button type="button" className="absolute inset-0 cursor-default bg-gray-950/50" onClick={onClose} aria-label="Close dialog" /><section className={`relative w-full ${widths[size] || widths.md} rounded-xl bg-white shadow-xl dark:bg-gray-900`}><header className="flex items-start justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700"><h2 id="modal-title" className="text-lg font-semibold text-gray-900 dark:text-white">{title}</h2><button type="button" onClick={onClose} className="-mr-1 rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Close dialog">×</button></header><div className="px-6 py-5">{children}</div>{footer && <footer className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">{footer}</footer>}</section></div>;
}
