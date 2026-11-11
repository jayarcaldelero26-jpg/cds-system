import { useEffect, useRef, useState } from 'react';
import Button from '@/Components/Button';
import Modal from '@/Components/Modal';
import FloatingInput from '@/Components/Form/FloatingInput';

export default function CurrentPasswordConfirmDialog({ open, onCancel, onConfirmed, message = 'Confirm your current password to continue.', confirmLabel = 'Confirm and continue' }) {
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [processing, setProcessing] = useState(false);
    const dialogRef = useRef(null);
    const inputRef = useRef(null);
    const onCancelRef = useRef(onCancel);
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

    return <Modal open={open} title="Confirm your current password" onClose={cancel} closeOnBackdrop={false} initialFocusSelector="#passkey-current-password" panelRef={dialogRef} zIndex="z-[70]" wrapperClassName="p-4" backdropClassName="bg-gray-950/60 backdrop-blur-xs" labelledBy="current-password-title" describedBy="current-password-description" panelClassName="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-5 shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:p-6">
            <h2 id="current-password-title" className="text-lg font-bold text-gray-900 dark:text-white">Confirm your current password</h2>
            <p id="current-password-description" className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{message}</p>
            <form onSubmit={submit} className="mt-5 space-y-4">
                <FloatingInput inputRef={inputRef} id="passkey-current-password" name="password" label="Current password" type="password" autoComplete="current-password" required value={password} onChange={(event) => { setPassword(event.target.value); if (error) setError(''); }} error={error} disabled={processing} />
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Button type="button" size="compact" variant="cancel" onClick={cancel} disabled={processing} className="h-11 rounded-lg px-4 text-sm">Cancel</Button>
                    <Button type="submit" size="compact" variant="primary" disabled={processing || !password} className="h-11 rounded-lg px-4 text-sm">{processing ? 'Confirming…' : confirmLabel}</Button>
                </div>
            </form>
    </Modal>;
}
