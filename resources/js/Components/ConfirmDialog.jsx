import { useRef } from 'react';
import Button from '@/Components/Button';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';

export default function ConfirmDialog({ open, title = 'Are you sure you want to change the data?', message = 'Once updated, you will not be able to revert it.', confirmLabel = 'OK', cancelLabel = 'Cancel', onConfirm, onCancel, processing = false, variant = 'default' }) {
    const overlayRef = useRef(null);
    const panelRef = useRef(null);
    const processingRef = useRef(processing);
    processingRef.current = processing;
    const { present, phase, onTransitionEnd } = useModalMotion(open, { onClose: () => { if (!processingRef.current) onCancel?.(); }, panelRef, layerRef: overlayRef });
    if (!present) return null;
    return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation">
        <div ref={panelRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message" data-motion-state={phase} className={`cds-modal-motion-panel w-full max-w-sm rounded-2xl border bg-white p-6 text-center shadow-2xl dark:bg-gray-900 ${variant === 'danger' ? 'border-red-100 dark:border-red-950' : 'border-gray-200 dark:border-gray-800'}`}>
            <div className={`mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full text-2xl font-bold shadow-sm ${variant === 'danger' ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-300' : 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300'}`}>{variant === 'danger' ? '!' : '?'}</div>
            <h3 id="confirm-dialog-title" className="mb-2 text-lg font-bold text-gray-900 dark:text-white">{title}</h3>
            <p id="confirm-dialog-message" className="mb-6 text-sm text-gray-600 dark:text-gray-300">{message}</p>
            <div className="flex gap-3"><Button type="button" size="compact" variant="cancel" onClick={onCancel} disabled={processing} className="flex-1 rounded-xl px-4 py-2 text-sm">{cancelLabel}</Button><Button type="button" size="compact" variant={variant === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} disabled={processing} className="flex-1 rounded-xl px-4 py-2 text-sm">{processing ? 'Processing…' : confirmLabel}</Button></div>
        </div>
    </div>;
}
