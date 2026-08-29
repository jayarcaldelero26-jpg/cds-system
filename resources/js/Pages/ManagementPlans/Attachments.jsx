import { FileInput } from "@/Components/Crud/FileInput";import { useEffect, useMemo, useRef, useState } from 'react';
import FilePreviewPanel from '../../Components/Crud/FilePreviewPanel';
import Tooltip from '../../Components/Tooltip';

export const MANAGEMENT_PLAN_ACCEPT = '.pdf,.docx,.zip,.jpg,.jpeg,.png';
export const MANAGEMENT_PLAN_TYPES = 'PDF, DOCX, ZIP, JPG/JPEG, and PNG';

const normalizeExisting = (attachment, index) => {
  if (typeof attachment === 'string') {
    return { id: attachment, path: attachment, name: attachment.split('/').pop() || `Attachment ${index + 1}`, url: null };
  }

  return {
    ...attachment,
    id: attachment?.id || attachment?.key || `existing-${index}`,
    key: attachment?.key ?? String(index),
    name: attachment?.original_name || attachment?.name || attachment?.stored_name || attachment?.path?.split('/').pop() || `Attachment ${index + 1}`,
    type: attachment?.mime_type || ''
  };
};

export function useManagementPlanAttachments(initialAttachments = [], onChange) {
  const initial = useMemo(() => (Array.isArray(initialAttachments) ? initialAttachments : [initialAttachments]).filter(Boolean).map(normalizeExisting), [initialAttachments]);
  const [existingFiles, setExistingFiles] = useState(initial);
  const [newFiles, setNewFiles] = useState([]);
  const [removedExistingFiles, setRemovedExistingFiles] = useState([]);
  const [activePreview, setActivePreview] = useState(initial[0] || null);
  const newFilesRef = useRef([]);

  useEffect(() => {newFilesRef.current = newFiles;}, [newFiles]);
  useEffect(() => () => newFilesRef.current.forEach((item) => URL.revokeObjectURL(item.url)), []);

  const publish = (nextNew, nextRemoved = removedExistingFiles) => onChange?.(nextNew.map((item) => item.file), nextRemoved.map((item) => item.key ?? item.path));

  const addFiles = (files) => {
    const added = files.map((file) => ({ id: `${file.name}-${file.size}-${file.lastModified}-${crypto.randomUUID?.() || Math.random()}`, file, name: file.name, type: file.type, size: file.size, url: URL.createObjectURL(file), temporary: true }));
    const next = [...newFiles, ...added];
    setNewFiles(next);
    publish(next);
    if (added.length) setActivePreview(added[added.length - 1]);
  };

  const removeNew = (item) => {
    URL.revokeObjectURL(item.url);
    const next = newFiles.filter((file) => file.id !== item.id);
    setNewFiles(next);
    publish(next);
    if (activePreview?.id === item.id) setActivePreview(next[0] || existingFiles[0] || null);
  };

  const removeExisting = (item) => {
    const nextExisting = existingFiles.filter((file) => (file.key ?? file.path) !== (item.key ?? item.path));
    const nextRemoved = [...removedExistingFiles, item];
    setExistingFiles(nextExisting);
    setRemovedExistingFiles(nextRemoved);
    publish(newFiles, nextRemoved);
    if ((activePreview?.key ?? activePreview?.path) === (item.key ?? item.path)) setActivePreview(nextExisting[0] || newFiles[0] || null);
  };

  return { existingFiles, newFiles, activePreview, setActivePreview, addFiles, removeNew, removeExisting };
}

const sizeLabel = (size) => Number.isFinite(Number(size)) ? `${(Number(size) / 1024 / 1024).toFixed(2)} MB` : '';

export default function ManagementPlanAttachments({ manager, error, canRemoveExisting = true, previewClassName = '', previewOnly = false, required = false }) {
  return <>
        {!previewOnly && <div className="space-y-3">
            <div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><label htmlFor="attachments-attachments-multiple-maximum-20-mb-per-file" className="mb-1 block text-xs font-semibold text-gray-700 dark:text-gray-300">Supporting Documents{required && <span className="ml-0.5 text-xs leading-4 text-red-500">*</span>}</label>{required && <p className="mb-1 text-amber-700 dark:text-amber-300">At least one supporting document is required.</p>}


        <FileInput id="attachments-attachments-multiple-maximum-20-mb-per-file" type="file" required={required && manager.existingFiles.length === 0 && manager.newFiles.length === 0} multiple accept={MANAGEMENT_PLAN_ACCEPT} onChange={(event) => {manager.addFiles(Array.from(event.target.files || []));event.target.value = '';}} />
            </div>
            {error && <p className="text-sm text-red-700 dark:text-red-300">{error}</p>}
            {manager.existingFiles.length > 0 && <FileGroup title="Existing Attachments" files={manager.existingFiles} active={manager.activePreview} onSelect={manager.setActivePreview} onRemove={canRemoveExisting ? manager.removeExisting : null} />}
            {manager.newFiles.length > 0 && <FileGroup title="New Attachments" files={manager.newFiles} active={manager.activePreview} onSelect={manager.setActivePreview} onRemove={manager.removeNew} />}
        </div>}
        <FilePreviewPanel file={manager.activePreview} title="Live Document Preview" emptyText="No file selected for preview" heightClass="h-[650px]" className={previewClassName} />
    </>;
}

function FileGroup({ title, files, active, onSelect, onRemove }) {
  return <div><p className="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">{title} (click to preview)</p><div className="flex flex-wrap gap-2">{files.map((file) => <div key={file.id} className={`flex max-w-full items-center gap-1 rounded-xl border p-1 text-xs font-semibold transition ${active?.id === file.id ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300'}`}><Tooltip content={file.name}><button type="button" onClick={() => onSelect(file)} className="max-w-[190px] truncate rounded-lg px-2 py-1.5 transition hover:bg-black/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">📄 {file.name}</button></Tooltip>{sizeLabel(file.size) && <span className="px-1 opacity-75">{sizeLabel(file.size)}</span>}{onRemove && <button type="button" onClick={() => onRemove(file)} className={`rounded-lg px-2 py-1.5 font-bold transition hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 ${active?.id === file.id ? 'text-white hover:bg-white/15' : 'text-red-600 dark:text-red-300 dark:hover:bg-red-950/50'}`} aria-label={`Remove ${file.name}`}>×</button>}</div>)}</div></div>;
}
