import { useState } from 'react';
import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';
import { downloadWithProgress } from '@/Utils/downloadWithProgress';

const bytes = value => !Number.isFinite(Number(value)) ? null : Number(value) < 1024 * 1024 ? `${(Number(value) / 1024).toFixed(1)} KB` : `${(Number(value) / 1024 / 1024).toFixed(1)} MB`;

function TransferProgress({ state }) {
    if (!state) return null;
    const percent = Number.isFinite(Number(state.percentage)) ? Math.max(0, Math.min(100, Number(state.percentage))) : null;
    return <div className="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-900" role="status" aria-live="polite">
        <div className="flex justify-between gap-3"><span>{state.label}</span>{percent !== null && <span>{percent}%</span>}</div>
        {percent === null ? <div className="mt-2 h-2 overflow-hidden rounded bg-green-100"><div className="h-full w-1/3 animate-pulse bg-green-600" /></div> : <div className="mt-2 h-2 overflow-hidden rounded bg-green-100"><div className="h-full bg-green-600 transition-[width]" style={{ width: `${percent}%` }} role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow={percent} /></div>}
        {state.loaded !== undefined && state.total && <p className="mt-1 text-[11px]">{bytes(state.loaded)} / {bytes(state.total)}</p>}
    </div>;
}

export default function RoutingAttachmentField({ currentDocument, file, onChange, error, disabled, uploadProgress }) {
    const [download, setDownload] = useState(null); const [downloadError, setDownloadError] = useState('');
    const downloadCurrent = async () => {
        if (!currentDocument?.download_url || download) return;
        setDownloadError(''); setDownload({ label: 'Downloading document…', percentage: null });
        try { await downloadWithProgress(currentDocument.download_url, currentDocument.name, progress => setDownload({ label: 'Downloading document…', ...progress })); setDownload({ label: 'Download complete', percentage: 100 }); window.setTimeout(() => setDownload(null), 1800); }
        catch (exception) { setDownload(null); setDownloadError(exception.message || 'The document download failed.'); }
    };
    return <div className="space-y-4">
        {currentDocument && <section className="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500">Current Document Copy</p><p className="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{currentDocument.name}</p><p className="mt-0.5 text-[11px] text-gray-500">Source: {currentDocument.source || 'Original MOV / report'}</p><div className="mt-2 flex flex-wrap gap-2">{currentDocument.preview_url && <a href={currentDocument.preview_url} target="_blank" rel="noreferrer" className="rounded-md border border-green-700 px-2.5 py-1.5 text-xs font-bold text-green-800">Preview</a>}<button type="button" disabled={disabled || Boolean(download)} onClick={downloadCurrent} className="rounded-md border border-green-700 px-2.5 py-1.5 text-xs font-bold text-green-800 disabled:opacity-50">{download ? 'Downloading…' : 'Download Current Copy'}</button></div></section>}
        <TransferProgress state={download} />{downloadError && <p className="text-xs font-semibold text-red-700" role="alert">{downloadError}</p>}
        <AttachmentDropzone id="routing-attachment" label="Updated / Signed / Stamped Copy (Optional)" files={file ? [file] : []} onChange={onChange} accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" acceptedTypesHint="PDF, JPG, JPEG, PNG, DOC, or DOCX" maxSizeBytes={100 * 1024 * 1024} maxSizeHint="Maximum 100 MB" disabled={disabled} error={error} helperText="Attach a newly signed, stamped, or updated document copy only when applicable." />
        <TransferProgress state={uploadProgress ? { label: 'Uploading document…', ...uploadProgress } : null} />
    </div>;
}
