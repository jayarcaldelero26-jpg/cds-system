import { useState } from 'react';
import Tooltip from '@/Components/Tooltip';

export default function FileAttachmentPanel({ id, label = 'Attachment', multiple = false, required = false, accept, acceptedTypesHint, maxSizeHint, maxSizeBytes = null, sizeErrorLabel = null, requiredMessage = 'A report attachment / MOV is required.', existingFiles = [], selectedFiles = [], activeFile, onSelectFile, onChange, onRemoveExisting, error, disabled = false, canManage = true }) {
    const [clientError, setClientError] = useState('');
    const files = Array.isArray(existingFiles) ? existingFiles : existingFiles ? [existingFiles] : [];
    const selected = Array.isArray(selectedFiles) ? selectedFiles : selectedFiles ? [selectedFiles] : [];
    const isActive = file => activeFile && (activeFile.id === file.id || activeFile.url === file.url || activeFile.name === file.name);
    const handleChange = (event) => {
        const value = multiple ? Array.from(event.target.files || []) : (event.target.files?.[0] || null);
        const selectedFilesList = Array.isArray(value) ? value : value ? [value] : [];
        const oversized = maxSizeBytes && selectedFilesList.find((file) => file.size > maxSizeBytes);

        if (oversized) {
            setClientError(`${sizeErrorLabel || label} must not exceed ${maxSizeHint?.replace(/^Maximum\s+/i, '') || 'the allowed size'}.`);
            event.target.value = '';
            return;
        }

        setClientError('');
        onChange?.(value, event);
    };
    return <section className="space-y-3 border-t border-gray-100 pt-2 dark:border-gray-800">
        {required && files.length === 0 && selected.length === 0 && <p className="text-xs font-medium text-amber-700 dark:text-amber-300">{requiredMessage}</p>}
        <div><label htmlFor={id} className="block text-xs font-semibold text-gray-700 dark:text-gray-300">{label}{required && <span className="ml-0.5 text-xs leading-4 text-red-500 dark:text-red-400">*</span>}</label>{(acceptedTypesHint || maxSizeHint) && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{[acceptedTypesHint, maxSizeHint].filter(Boolean).join(' · ')}</p>}</div>
        {files.length > 0 && <div><span className="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-400">Current Files (Click to Preview):</span><div className="flex flex-wrap gap-2">{files.map((file, index) => <span key={file.id ?? file.url ?? index} className={`inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${isActive(file) ? 'border-green-600 bg-green-700 text-white' : 'border-green-200 bg-green-50 text-green-800 hover:bg-green-100 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300'}`}><Tooltip content={file.name}><button type="button" onClick={() => onSelectFile?.(file)} className="min-w-0 truncate text-left">{file.name || `File ${index + 1}`}</button></Tooltip>{file.url && !onSelectFile && <a href={file.url} target="_blank" rel="noreferrer" className="font-bold hover:underline">Open</a>}{canManage && onRemoveExisting && <Tooltip content={`Remove ${file.name || 'file'}`}><button type="button" onClick={() => onRemoveExisting(file)} className={isActive(file) ? 'font-bold text-white' : 'font-bold text-red-600'} aria-label={`Remove ${file.name || 'file'}`}>×</button></Tooltip>}</span>)}</div></div>}
        {canManage && <Tooltip content={`${label}. ${acceptedTypesHint || ''}${maxSizeHint ? ` ${maxSizeHint}.` : ''}`.trim()}><input id={id} type="file" multiple={multiple} required={required && files.length === 0 && selected.length === 0} accept={accept} disabled={disabled} onChange={handleChange} className="block min-h-11 w-full cursor-pointer rounded-lg border border-gray-300 bg-white text-sm leading-5 text-gray-700 outline-none transition file:mr-3 file:my-1 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-green-800 hover:file:bg-green-100 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:file:bg-green-950/50 dark:file:text-green-300 dark:hover:file:bg-green-950 dark:focus:border-green-500 dark:focus:ring-green-500/25" /></Tooltip>}
        {selected.length > 0 && <div className="flex flex-wrap gap-2">{selected.map((file, index) => <span key={file?.name ?? index} className="inline-flex max-w-full items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-800 dark:border-blue-900 dark:bg-blue-950/50 dark:text-blue-300"><span className="truncate">New: {file?.name || `File ${index + 1}`}</span></span>)}</div>}
        {(clientError || error) && <p className="text-xs font-medium text-red-600 dark:text-red-400" role="alert">{clientError || error}</p>}
    </section>;
}
