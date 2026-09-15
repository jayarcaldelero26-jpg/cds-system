import { useEffect, useState } from 'react';
import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';
import { downloadWithProgress } from '@/Utils/downloadWithProgress';

const bytes = value => !Number.isFinite(Number(value)) ? null : Number(value) < 1024 * 1024 ? `${(Number(value) / 1024).toFixed(1)} KB` : `${(Number(value) / 1024 / 1024).toFixed(1)} MB`;
function TransferProgress({ state }) {
    if (!state) return null;
    const percent = Number.isFinite(Number(state.percentage)) ? Math.max(0, Math.min(100, Number(state.percentage))) : null;
    return <div className="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-900" role="status" aria-live="polite"><div className="flex justify-between gap-3"><span>{state.label}</span>{percent !== null && <span>{percent}%</span>}</div><div className="mt-2 h-2 overflow-hidden rounded bg-green-100"><div className={`h-full bg-green-600 ${percent === null ? 'w-1/3 animate-pulse' : 'transition-[width]'}`} style={percent === null ? undefined : { width: `${percent}%` }} /></div>{state.loaded !== undefined && state.total && <p className="mt-1 text-[11px]">{bytes(state.loaded)} / {bytes(state.total)}</p>}</div>;
}

export default function RoutingAttachmentField({ currentDocument, file, onChange, error, disabled, processing, uploadProgress, attachmentAllowed = true }) {
    const [download, setDownload] = useState(null);
    const [downloadError, setDownloadError] = useState('');
    const [newUploadPreview, setNewUploadPreview] = useState(null);
    const [showNewPreview, setShowNewPreview] = useState(false);
    const hasSelectedAttachment = typeof File !== 'undefined' && file instanceof File;
    useEffect(() => {
        if (!hasSelectedAttachment) { setNewUploadPreview(null); setShowNewPreview(false); return undefined; }
        const url = URL.createObjectURL(file); setNewUploadPreview({ file, url }); setShowNewPreview(false);
        return () => URL.revokeObjectURL(url);
    }, [file, hasSelectedAttachment]);
    const uploadUrl = newUploadPreview?.file === file ? newUploadPreview.url : null;
    const extension = String(file?.name || '').split('.').pop()?.toLowerCase();
    const previewable = String(file?.type || '').toLowerCase() === 'application/pdf' || extension === 'pdf' || ['image/jpeg', 'image/png'].includes(String(file?.type || '').toLowerCase()) || ['jpg', 'jpeg', 'png'].includes(extension);
    const activeUploadProgress = attachmentAllowed && hasSelectedAttachment && processing && uploadProgress && Number.isFinite(Number(uploadProgress.percentage)) ? { label: 'Uploading document...', ...uploadProgress } : null;
    const downloadCurrent = async () => {
        if (!currentDocument?.download_url || download) return;
        setDownloadError(''); setDownload({ label: 'Downloading document...', percentage: null });
        try { await downloadWithProgress(currentDocument.download_url, currentDocument.name, progress => setDownload({ label: 'Downloading document...', ...progress })); setDownload({ label: 'Download complete', percentage: 100 }); window.setTimeout(() => setDownload(null), 1800); }
        catch (exception) { setDownload(null); setDownloadError(exception.message || 'The document download failed.'); }
    };
    return <div className="space-y-4">
        {currentDocument && <section className="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500">Saved / Previous Document Copy</p><p className="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{currentDocument.name}</p><p className="mt-0.5 text-[11px] text-gray-500">Source: {currentDocument.source || 'Original MOV / report'}</p><div className="mt-2"><button type="button" disabled={disabled || Boolean(download)} onClick={downloadCurrent} className="rounded-md border border-green-700 px-2.5 py-1.5 text-xs font-bold text-green-800 disabled:opacity-50">{download ? 'Downloading...' : 'Download Current Copy'}</button></div></section>}
        <TransferProgress state={download} />{downloadError && <p className="text-xs font-semibold text-red-700" role="alert">{downloadError}</p>}
        {attachmentAllowed && <><section className="space-y-2"><p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500">Updated Copy Dropzone</p><AttachmentDropzone id="routing-attachment" label="Updated / Signed / Stamped Copy (Optional)" files={hasSelectedAttachment ? [file] : []} onChange={onChange} accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" acceptedTypesHint="PDF, JPG, JPEG, PNG, DOC, or DOCX" maxSizeBytes={100 * 1024 * 1024} maxSizeHint="Maximum 100 MB" disabled={disabled} error={error} helperText="Attach a newly signed, stamped, or updated document copy only when applicable." /></section><TransferProgress state={activeUploadProgress} />
        {hasSelectedAttachment && uploadUrl && <section className="rounded-lg border border-blue-200 p-3 dark:border-blue-900"><p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500">New Upload Preview</p><p className="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{file.name}</p>{previewable ? <><button type="button" onClick={() => setShowNewPreview(value => !value)} className="mt-2 rounded-md border border-blue-700 px-2.5 py-1.5 text-xs font-bold text-blue-800">{showNewPreview ? 'Hide New Upload Preview' : 'Preview New Upload'}</button>{showNewPreview && <div className="mt-3 overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950/40">{extension === 'pdf' || file.type === 'application/pdf' ? <iframe title={`Preview ${file.name}`} src={uploadUrl} className="h-[360px] w-full" /> : <img src={uploadUrl} alt={`Preview ${file.name}`} className="mx-auto max-h-[360px] max-w-full object-contain" />}</div>}</> : <a href={uploadUrl} target="_blank" rel="noreferrer" className="mt-2 inline-block rounded-md border border-blue-700 px-2.5 py-1.5 text-xs font-bold text-blue-800">Open New Upload</a>}</section>}</>}
    </div>;
}
