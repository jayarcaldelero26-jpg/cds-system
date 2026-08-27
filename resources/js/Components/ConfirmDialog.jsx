import { useEffect } from 'react';

export default function ConfirmDialog({ open, title = 'Are you sure you want to change the data?', message = 'Once updated, you will not be able to revert it.', confirmLabel = 'OK', cancelLabel = 'Cancel', onConfirm, onCancel, processing = false, variant = 'default' }) {
    useEffect(() => {
        if (!open) return;
        const closeTopDialog = event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopPropagation();
            if (!processing) onCancel?.();
        };
        document.addEventListener('keydown', closeTopDialog, true);
        return () => document.removeEventListener('keydown', closeTopDialog, true);
    }, [open, processing, onCancel]);

    if (!open) return null;
    return <div className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation">
        <div role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message" className={`w-full max-w-sm animate-pop-in rounded-2xl border bg-white p-6 text-center shadow-2xl dark:bg-gray-900 ${variant === 'danger' ? 'border-red-100 dark:border-red-950' : 'border-gray-200 dark:border-gray-800'}`}>
            <div className={`mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full text-2xl font-bold shadow-sm ${variant === 'danger' ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300' : 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300'}`}>{variant === 'danger' ? '!' : '?'}</div>
            <h3 id="confirm-dialog-title" className="mb-2 text-lg font-bold text-gray-900 dark:text-white">{title}</h3>
            <p id="confirm-dialog-message" className="mb-6 text-sm text-gray-600 dark:text-gray-300">{message}</p>
            <div className="flex gap-3"><button type="button" onClick={onCancel} disabled={processing} className="flex-1 rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">{cancelLabel}</button><button type="button" onClick={onConfirm} disabled={processing} className={`flex-1 rounded-xl px-4 py-2 text-sm font-semibold text-white shadow-sm transition disabled:cursor-not-allowed disabled:opacity-50 ${variant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-green-700 hover:bg-green-800'}`}>{processing ? 'Processing…' : confirmLabel}</button></div>
        </div>
    </div>;
}
