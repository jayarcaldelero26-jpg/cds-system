import { useEffect } from 'react';
import Button from '@/Components/Button';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';

export default function CrudDetailsModal({ open, icon, title, subtitle, onClose, children, summary, attachments, canEdit = false, canDelete = false, onEdit, onDelete, editLabel = 'Edit Details', deleteLabel = 'Delete Record', closeLabel = 'Close Details', maxWidth = 'max-w-5xl' }) {
    useEffect(() => { if (!open) return; const onKey = event => event.key === 'Escape' && onClose?.(); document.addEventListener('keydown', onKey); return () => document.removeEventListener('keydown', onKey); }, [open, onClose]);
    if (!open) return null;
    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose?.()}><div role="dialog" aria-modal="true" aria-label={title} className={`flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900 ${maxWidth}`}><CrudModalHeader icon={icon} title={title} subtitle={subtitle} onClose={onClose} /><div className="custom-table-scrollbar min-h-0 flex-1 overflow-y-auto p-5"><div className="space-y-5">{summary}{children}{attachments}</div></div><CrudModalFooter left={<>{canEdit && onEdit && <Button variant="secondary" onClick={onEdit}>{editLabel}</Button>}{canDelete && onDelete && <Button variant="danger" onClick={onDelete}>{deleteLabel}</Button>}</>}><Button variant="primary" onClick={onClose}>{closeLabel}</Button></CrudModalFooter></div></div>;
}
