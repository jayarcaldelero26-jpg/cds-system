import React from 'react';

const formatBytes = (value) => {
    if (!Number.isFinite(value)) return null;
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
};

export default function SaveProgressIndicator({ processing, progress }) {
    if (!processing) return null;
    const percentage = Number.isFinite(Number(progress?.percentage)) ? Math.max(0, Math.min(100, Number(progress.percentage))) : null;
    const loaded = formatBytes(Number(progress?.loaded));
    const total = formatBytes(Number(progress?.total));
    return <div className="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900" role="status" aria-live="polite">
        {percentage === null && <div className="flex items-center gap-2"><span className="h-4 w-4 animate-spin rounded-full border-2 border-emerald-300 border-t-emerald-700" aria-hidden="true" />Preparing / Validating</div>}
        {percentage !== null && percentage < 100 && <>
            <div className="flex justify-between"><span>Uploading attachments</span><span>{percentage}%</span></div>
            <div className="mt-1 h-2 overflow-hidden rounded bg-emerald-100"><div className="h-full bg-emerald-600 transition-[width]" style={{ width: `${percentage}%` }} /></div>
            {loaded && total && <div className="mt-1 text-xs text-emerald-800">{loaded} / {total}</div>}
        </>}
        {percentage === 100 && <div className="flex items-center gap-2"><span className="font-semibold">Uploading attachments ✓</span><span className="h-4 w-4 animate-spin rounded-full border-2 border-emerald-300 border-t-emerald-700" aria-hidden="true" /><span>Upload complete. Processing on server...</span></div>}
    </div>;
}
