import { useEffect } from 'react';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';

export default function CrudDetailsModal({ open, icon, title, subtitle, onClose, children, summary, attachments, canEdit = false, canDelete = false, onEdit, onDelete, editLabel = 'Edit Details', deleteLabel = 'Delete Record', closeLabel = 'Close Details', maxWidth = 'max-w-4xl' }) {
    useEffect(() => { if (!open) return; const onKey = event => event.key === 'Escape' && onClose?.(); document.addEventListener('keydown', onKey); return () => document.removeEventListener('keydown', onKey); }, [open, onClose]);
    if (!open) return null;
    return <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose?.()}>
        <div role="dialog" aria-modal="true" aria-label={title} className={`relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900 ${maxWidth}`}>
            <CrudModalHeader icon={icon} title={title} subtitle={subtitle} onClose={onClose} />
            <div className="custom-table-scrollbar min-h-0 flex-1 space-y-6 overflow-y-auto p-6 text-sm">{summary}{children}{attachments}</div>
            <CrudModalFooter left={<>{canEdit && onEdit && <button type="button" onClick={onEdit} className="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 transition hover:bg-green-100 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300">✏️ {editLabel}</button>}{canDelete && onDelete && <button type="button" onClick={onDelete} className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">{deleteLabel}</button>}</>}>
                <button type="button" onClick={onClose} className="rounded-xl bg-green-700 px-5 py-2 text-xs font-bold text-white shadow-md transition hover:bg-green-800">{closeLabel}</button>
            </CrudModalFooter>
        </div>
    </div>;
}
