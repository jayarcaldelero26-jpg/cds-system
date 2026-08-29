import { useEffect } from 'react';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';
import CrudSummaryGrid from './CrudSummaryGrid';
import CrudSection from './CrudSection';
import { ReportDetailsContext } from './ReportDetailsContext';
import TimelinessBadge from '../TimelinessBadge';

function standardizedReportSummary(summary, reportData = null) {
    const items = summary?.props?.items;
    if (!Array.isArray(items)) return summary;
    const isFinancialReport = reportData && ['total_collected', 'ipaf_ria', 'sagf'].some(key => Object.prototype.hasOwnProperty.call(reportData, key));
    if (isFinancialReport) {
        const complianceItems = items
            .filter(item => !/total collected|ipaf ria|sagf/i.test(item.label || ''))
            .map(item => /^(period|reporting month)$/i.test(item.label || '') ? { ...item, label: 'Reporting Period' } : item);
        return complianceItems.length ? <CrudSummaryGrid columns={4} items={complianceItems} /> : null;
    }
    const findItem = (pattern) => items.find(item => pattern.test(item.label || ''));
    const reportingPeriod = findItem(/reporting period|semester|quarter|period/i) || (reportData?.period_label ? { value: reportData.period_label } : null);
    const reportStatus = findItem(/report status|submission status|status of submission|^status$/i) || (reportData?.submission_status ? { value: reportData.submission_status } : null);
    const deadline = findItem(/deadline/i) || (reportData?.deadline_submission ? { value: reportData.deadline_submission } : null);
    const timeliness = findItem(/timeliness/i) || (reportData?.timeliness ? { value: reportData.timeliness } : null);
    if (!reportStatus || !timeliness) return summary;
    return <CrudSummaryGrid columns={4} items={[
        reportingPeriod ? { ...reportingPeriod, label: 'Reporting Period' } : { label: 'Reporting Period', value: reportData?.semester || reportData?.quarter || reportData?.reporting_period || '—' },
        { ...reportStatus, label: 'Submission Status' },
        deadline ? { ...deadline, label: 'Deadline' } : { label: 'Deadline', value: reportData?.deadline_submission || '—' },
        timeliness.render ? { ...timeliness, label: 'Timeliness Rating' } : { ...timeliness, label: 'Timeliness Rating', render: () => <TimelinessBadge value={timeliness.value || reportData?.timeliness} /> },
    ]} />;
}

export default function CrudDetailsModal({ open, icon, title, subtitle, onClose, children, summary, attachments, report = false, canEdit = false, canDelete = false, onEdit, onDelete, editLabel = 'Edit Details', deleteLabel = 'Delete Record', closeLabel = 'Close Details', maxWidth = 'max-w-4xl' }) {
    useEffect(() => { if (!open) return; const onKey = event => event.key === 'Escape' && onClose?.(); document.addEventListener('keydown', onKey); return () => document.removeEventListener('keydown', onKey); }, [open, onClose]);
    if (!open) return null;
    const isReport = report || (/\breport\b/i.test(title || '') && !/^Overdue Report\b/i.test(title || '')) || title === 'Management of IPAF Details' || title === 'Revenue Collection Details';
    const reportData = children?.props?.report || children?.props?.record || null;
    const displaySummary = isReport ? standardizedReportSummary(summary, reportData) : summary;
    const attachmentContent = attachments || (isReport && <p className="text-xs text-gray-500 dark:text-gray-400">No MOV / attachment has been submitted.</p>);
    const displayAttachments = isReport && attachmentContent
        ? (attachmentContent.type === CrudSection ? attachmentContent : <CrudSection title="Attachment / MOV">{attachmentContent}</CrudSection>)
        : attachmentContent;
    return <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose?.()}>
        <div role="dialog" aria-modal="true" aria-label={title} className={`relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900 ${maxWidth}`}>
            <CrudModalHeader icon={icon} report={isReport} title={title} subtitle={subtitle} onClose={onClose} />
            <ReportDetailsContext.Provider value={isReport ? (reportData || {}) : null}><div className="custom-table-scrollbar min-h-0 flex-1 space-y-6 overflow-y-auto p-6 text-sm">{displaySummary}{children}{displayAttachments}</div></ReportDetailsContext.Provider>
            <CrudModalFooter left={<>{canEdit && onEdit && <button type="button" onClick={onEdit} className="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 transition hover:bg-green-100 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300">✏️ {editLabel}</button>}{canDelete && onDelete && <button type="button" onClick={onDelete} className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300">{deleteLabel}</button>}</>}>
                <button type="button" onClick={onClose} className="rounded-xl bg-green-700 px-5 py-2 text-xs font-bold text-white shadow-md transition hover:bg-green-800">{closeLabel}</button>
            </CrudModalFooter>
        </div>
    </div>;
}
