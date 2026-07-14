import DangerButton from './DangerButton';
import SecondaryButton from './SecondaryButton';
import Modal from './Modal';

export default function ConfirmDialog({ open, title = 'Confirm action', message, confirmLabel = 'Confirm', onConfirm, onCancel, processing = false, children }) {
    return <Modal open={open} onClose={onCancel} title={title} size="sm" footer={<><SecondaryButton onClick={onCancel}>Cancel</SecondaryButton><DangerButton onClick={onConfirm} disabled={processing}>{processing ? 'Processing...' : confirmLabel}</DangerButton></>}><p className="text-sm leading-6 text-gray-600 dark:text-gray-300">{message}</p>{children && <div className="mt-4">{children}</div>}</Modal>;
}
