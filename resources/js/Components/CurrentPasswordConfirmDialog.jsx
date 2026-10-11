import { useEffect, useRef, useState } from 'react';
import Button from '@/Components/Button';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';

export default function CurrentPasswordConfirmDialog({ open, onCancel, onConfirmed, message = 'Confirm your current password to continue.', confirmLabel = 'Confirm and continue' }) {
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [processing, setProcessing] = useState(false);
    const dialogRef = useRef(null);
    const inputRef = useRef(null);
    const onCancelRef = useRef(onCancel);
    const overlayRef = useRef(null);
    const processingRef = useRef(processing);
    processingRef.current = processing;

    useEffect(() => {
        onCancelRef.current = onCancel;
    }, [onCancel]);

    const cancel = () => {
        if (processingRef.current) return;
        setPassword('');
        setError('');
        onCancelRef.current?.();
    };
    const { present, phase, onTransitionEnd } = useModalMotion(open, {
        onClose: cancel,
        panelRef: dialogRef,
        layerRef: overlayRef,
        initialFocusSelector: '#passkey-current-password',
    });

    if (!present) return null;

    const submit = async (event) => {
        event.preventDefault();
        if (processing || !password) return;

        setProcessing(true);
        setError('');

        try {
            const response = await fetch('/confirm-password', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ password }),
            });
            const payload = await response.json().catch(() => ({}));

            if (response.status === 422) {
                setError(payload.errors?.password?.[0] || 'The current password is incorrect.');
                return;
            }

            if (!response.ok || payload.confirmed !== true) {
                setError(payload.message || 'Password confirmation could not be completed. Please try again.');
                return;
            }

            setPassword('');
            onConfirmed?.();
        } catch {
            setError('Password confirmation could not be completed. Check your connection and try again.');
        } finally {
            setProcessing(false);
        }
    };

    return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-[70] flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation">
        <section ref={dialogRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="current-password-title" aria-describedby="current-password-description" data-motion-state={phase} className="cds-modal-motion-panel w-full max-w-md rounded-2xl border border-gray-200 bg-white p-5 shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:p-6">
            <h2 id="current-password-title" className="text-lg font-bold text-gray-900 dark:text-white">Confirm your current password</h2>
            <p id="current-password-description" className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{message}</p>
            <form onSubmit={submit} className="mt-5 space-y-4">
                <div>
                    <label htmlFor="passkey-current-password" className="mb-1.5 block text-sm font-semibold text-gray-800 dark:text-gray-200">Current password</label>
                    <input ref={inputRef} id="passkey-current-password" name="password" type="password" autoComplete="current-password" required value={password} onChange={(event) => { setPassword(event.target.value); if (error) setError(''); }} aria-invalid={Boolean(error)} aria-describedby={error ? 'current-password-error' : undefined} disabled={processing} className="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 shadow-sm focus:border-green-700 focus:outline-none focus:ring-2 focus:ring-green-700/30 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    {error && <p id="current-password-error" className="mt-2 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}
                </div>
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button type="button" size="compact" variant="cancel" onClick={cancel} disabled={processing} className="h-11 rounded-lg px-4 text-sm">Cancel</Button>
                    <Button type="submit" size="compact" variant="primary" disabled={processing || !password} className="h-11 rounded-lg px-4 text-sm">{processing ? 'Confirming…' : confirmLabel}</Button>
                </div>
            </form>
        </section>
    </div>;
}
