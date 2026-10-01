import Tooltip from '@/Components/Tooltip';
import { useState } from 'react';
import { assignDroppedFiles, forwardFileInputChange, validateDroppedFiles } from '@/Components/Attachments/modalFileDrop.mjs';

export function FileInput({ className = '', tooltip = 'Choose a file to upload.', variant = 'calendar', modalPrimary = false, maxSizeBytes, onChange, ...props }) {
    const [dropError, setDropError] = useState('');
    const fieldClasses = variant === 'legacy'
        ? 'rounded-xl border border-gray-300 bg-white text-xs text-gray-500 shadow-sm file:mr-4 file:rounded-xl file:border-0 file:bg-green-50 file:px-4 file:py-2.5 file:text-xs file:font-semibold file:text-green-700 hover:file:bg-green-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'
        : 'min-h-11 rounded-lg border border-gray-300 bg-white text-sm leading-5 font-normal text-gray-800 shadow-none outline-none transition file:mr-3 file:my-1 file:rounded-md file:border-0 file:bg-green-50 file:px-3 file:py-1.5 file:text-sm file:leading-5 file:font-normal file:text-green-800 hover:file:bg-green-100 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700/20 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:file:bg-green-950/50 dark:file:text-green-300 dark:hover:file:bg-green-950 dark:focus:border-green-500 dark:focus:ring-green-500/25';
    const onDrop = (event) => {
        const panel = event.currentTarget.closest('[data-cds-modal-drop-panel]');
        if (panel) return;
        const input = event.currentTarget.querySelector('input[type="file"]');
        if (input.disabled) { event.preventDefault(); return; }
        const result = validateDroppedFiles(event.dataTransfer.files, input);
        event.preventDefault();
        setDropError(result.error || '');
        if (result.files.length) assignDroppedFiles(input, result.files);
    };
    return <Tooltip content={tooltip} className="block w-full"><div className="cds-file-dropzone-native" data-cds-upload-zone onDragOver={(event) => event.preventDefault()} onDrop={onDrop}>
        <svg className="mx-auto mb-1 h-5 w-5 text-emerald-700 dark:text-emerald-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M4.5 15.5v3A1.5 1.5 0 0 0 6 20h12a1.5 1.5 0 0 0 1.5-1.5v-3"/></svg>
        <p className="mb-2 text-xs font-medium text-slate-700 dark:text-slate-200">Drop files here or use the file picker</p>
        <input {...props} type="file" onChange={(event) => { setDropError(''); forwardFileInputChange(onChange, event); }} data-cds-modal-primary={modalPrimary ? 'true' : undefined} data-max-size-bytes={maxSizeBytes || undefined} className={`block w-full cursor-pointer disabled:cursor-not-allowed disabled:opacity-60 ${fieldClasses} ${className}`} />
        {dropError && <p className="mt-2 text-left text-xs font-medium text-red-700 dark:text-red-300" role="alert">{dropError}</p>}
    </div></Tooltip>;
}

export default FileInput;
