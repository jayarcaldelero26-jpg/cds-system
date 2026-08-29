import { useEffect } from 'react';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';

export default function CrudFormModal({ open, mode = 'create', icon, title, subtitle, onClose, onSubmit, processing = false, errors = {}, children, preview, canDelete = false, onDelete, canSave = true, backLabel, saveLabel, deleteLabel = 'Delete Record', maxWidth = 'max-w-7xl' }) {
    useEffect(() => { if (!open || processing) return; const onKey = event => event.key === 'Escape' && onClose?.(); document.addEventListener('keydown', onKey); return () => document.removeEventListener('keydown', onKey); }, [open, processing, onClose]);
    if (!open) return null;
    const resolvedSaveLabel = saveLabel || (mode === 'edit' ? 'Save Changes' : 'Save Record');
    const displayPreview = Boolean(preview);
    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs">
        <form onSubmit={onSubmit} role="dialog" aria-modal="true" aria-label={title} className={`relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900 ${maxWidth}`}>
            <CrudModalHeader icon={icon} title={title} subtitle={subtitle} onClose={processing ? undefined : onClose} />
            <div className="custom-table-scrollbar min-h-0 flex-1 overflow-y-auto p-6">
                {Object.keys(errors || {}).length > 0 && <div className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert"><p className="font-bold">Please correct the following:</p><ul className="mt-1 list-disc pl-5">{Object.values(errors).map((error, index) => <li key={index}>{error}</li>)}</ul></div>}
                <div className={displayPreview ? 'grid grid-cols-1 gap-6 lg:grid-cols-12' : ''}><div className={displayPreview ? 'space-y-5 lg:col-span-6' : 'space-y-5'}>{children}</div>{displayPreview && <div className="lg:col-span-6"><div className="sticky top-4">{preview}</div></div>}</div>
            </div>
            <CrudModalFooter left={canDelete && onDelete ? <button type="button" onClick={onDelete} disabled={processing} className="rounded-xl bg-red-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:opacity-50">{deleteLabel}</button> : null}>
                <button type="button" onClick={onClose} disabled={processing} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">{backLabel || (mode === 'edit' ? '← Back' : 'Cancel')}</button>
                {canSave && <button type="submit" disabled={processing} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800 disabled:opacity-50">{processing ? (mode === 'edit' ? 'Updating…' : 'Saving…') : resolvedSaveLabel}</button>}
            </CrudModalFooter>
        </form>
    </div>;
}
