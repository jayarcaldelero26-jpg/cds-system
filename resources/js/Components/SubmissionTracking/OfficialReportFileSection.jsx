import Button from '@/Components/Button';
import CrudSection from '@/Components/Crud/CrudSection';

export default function OfficialReportFileSection({ row, onPreview }) {
    const attachment = row?.current_document;
    const reportsUpload = row?.mov_processing?.status_label === 'MOV Uploaded / Ready for Submission';

    return <CrudSection title="Official Report File">
        {attachment ? <div className="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-700 dark:bg-gray-900/50">
            <div className="min-w-0">
                <p className="break-all text-sm font-semibold text-gray-900 dark:text-white">{attachment.name || 'Official report file'}</p>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{attachment.mime_type || attachment.type || 'File type unavailable'}</p>
            </div>
            {attachment.can_preview === true
                ? <Button type="button" size="compact" variant="cancel" onClick={() => onPreview?.(row)} className="shrink-0 rounded-xl px-4 py-2 text-xs">Preview file</Button>
                : <p className="text-xs text-gray-500 dark:text-gray-400">Protected file access is unavailable for this account.</p>}
        </div> : <p className="text-xs text-gray-600 dark:text-gray-300">{reportsUpload
            ? 'The report source records a MOV upload, but its protected report file is currently unavailable.'
            : 'No protected report file is available in the current response.'}</p>}
    </CrudSection>;
}
