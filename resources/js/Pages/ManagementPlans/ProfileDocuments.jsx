import { FileInput } from "@/Components/Crud/FileInput";import { FloatingSelect } from "@/Components/Form";import { useEffect, useRef, useState } from 'react';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import Tooltip from '@/Components/Tooltip';

export function useProfileDocuments(initialDocuments = [], onChange) {
  const normalize = (document, index) => ({ ...document, id: document.path || `existing-${index}`, name: document.original_name || document.name || document.path?.split('/').pop() || `Document ${index + 1}`, type: document.mime_type || '' });
  const [existing, setExisting] = useState((Array.isArray(initialDocuments) ? initialDocuments : []).map(normalize));
  const [added, setAdded] = useState([]);
  const [removed, setRemoved] = useState([]);
  const [active, setActive] = useState(existing[0] || null);
  const addedRef = useRef([]);
  useEffect(() => {addedRef.current = added;}, [added]);
  useEffect(() => () => addedRef.current.forEach((item) => URL.revokeObjectURL(item.url)), []);
  const publish = (nextAdded, nextRemoved = removed) => onChange(nextAdded.map((item) => item.file), nextAdded.map((item) => item.category), nextRemoved.map((item) => item.path));
  const addFiles = (files, category) => {
    const items = files.map((file) => ({ id: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`, file, name: file.name, type: file.type, size: file.size, category, temporary: true, url: URL.createObjectURL(file) }));
    const next = [...added, ...items];setAdded(next);publish(next);if (items.length) setActive(items[items.length - 1]);
  };
  const removeAdded = (item) => {URL.revokeObjectURL(item.url);const next = added.filter((file) => file.id !== item.id);setAdded(next);publish(next);if (active?.id === item.id) setActive(next[0] || existing[0] || null);};
  const removeExisting = (item) => {const nextExisting = existing.filter((file) => file.path !== item.path);const nextRemoved = [...removed, item];setExisting(nextExisting);setRemoved(nextRemoved);publish(added, nextRemoved);if (active?.path === item.path) setActive(nextExisting[0] || added[0] || null);};
  return { existing, added, active, setActive, addFiles, removeAdded, removeExisting };
}

export default function ProfileDocuments({ manager, categories = {}, error, previewOnly = false }) {
  const [category, setCategory] = useState('main_plan');
  return <>{!previewOnly && <div className="space-y-3"><div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><FloatingSelect id="profiledocuments-document-category" label="Document Category" value={category} onChange={(event) => setCategory(event.target.value)}>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</FloatingSelect></div><div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><FileInput id="profiledocuments-supporting-documents" type="file" multiple accept=".pdf,.doc,.docx,.zip,.jpg,.jpeg,.png" onChange={(event) => {manager.addFiles(Array.from(event.target.files || []), category);event.target.value = '';}} /></div>{error && <p className="text-xs text-red-500">{error}</p>}<DocumentGroup title="Existing Documents" files={manager.existing} manager={manager} remove={manager.removeExisting} categories={categories} /><DocumentGroup title="New Documents" files={manager.added} manager={manager} remove={manager.removeAdded} categories={categories} /></div>}{previewOnly && <FilePreviewPanel file={manager.active} title="Supporting Document Preview" emptyText="No document selected for preview" heightClass="h-[560px]" />}</>;
}

function DocumentGroup({ title, files, manager, remove, categories }) {
  if (!files.length) return null;
  return <div><p className="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">{title}</p><div className="space-y-2">{files.map((file) => <div key={file.id} className={`flex items-center gap-2 rounded-xl border p-2 text-xs ${manager.active?.id === file.id ? 'border-green-600 bg-green-50 dark:bg-green-950/30' : 'border-gray-200 dark:border-gray-700'}`}><Tooltip content={file.name}><button type="button" onClick={() => manager.setActive(file)} className="min-w-0 flex-1 truncate text-left font-semibold text-gray-700 hover:text-green-700 dark:text-gray-200">{file.name}</button></Tooltip><span className="hidden rounded-full bg-gray-100 px-2 py-1 text-[10px] text-gray-600 dark:bg-gray-700 dark:text-gray-300 sm:inline">{categories[file.category] || file.category}</span><Tooltip content={`Remove ${file.name}`}><button type="button" onClick={() => remove(file)} className="rounded-lg px-2 py-1.5 font-bold text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/40" aria-label={`Remove ${file.name}`}>×</button></Tooltip></div>)}</div></div>;
}
