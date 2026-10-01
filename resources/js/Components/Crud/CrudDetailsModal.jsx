import { useEffect } from 'react';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';
import CrudSummaryGrid from './CrudSummaryGrid';
import CrudSection from './CrudSection';
import { ReportDetailsContext } from './ReportDetailsContext';
import TimelinessBadge from '../TimelinessBadge';
import Button from '@/Components/Button';
import CrudModalCardContext from './CrudModalCardContext';

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

export default function CrudDetailsModal({ open, icon, title, subtitle, onClose, children, summary, attachments, report = false, darkTheme = false, canEdit = false, canDelete = false, onEdit, onDelete, editLabel = 'Edit Details', deleteLabel = 'Delete Record', closeLabel = 'Close Details', maxWidth = 'max-w-4xl', compact = false, footerActions = null, closeOnEscape = true }) {
    useEffect(() => { if (!open || !closeOnEscape) return; const onKey = event => event.key === 'Escape' && onClose?.(); document.addEventListener('keydown', onKey); return () => document.removeEventListener('keydown', onKey); }, [open, onClose, closeOnEscape]);
    if (!open) return null;
    const isReport = report || (/\breport\b/i.test(title || '') && !/^Overdue Report\b/i.test(title || '')) || title === 'Management of IPAF Details' || title === 'Revenue Collection Details';
    const reportData = children?.props?.report || children?.props?.record || null;
    const terminalReport = isReport && String(reportData?.submission_status || '').toLowerCase() === 'completed';
    const displaySummary = isReport ? standardizedReportSummary(summary, reportData) : summary;
    const attachmentContent = attachments || (isReport && <p className="text-xs text-gray-500 dark:text-gray-400">No MOV / attachment has been submitted.</p>);
    const displayAttachments = isReport && attachmentContent
        ? (attachmentContent.type === CrudSection ? attachmentContent : <CrudSection title="Attachment / MOV">{attachmentContent}</CrudSection>)
        : attachmentContent;
    return <div className={`fixed inset-0 z-50 flex items-center justify-center overflow-y-auto ${darkTheme ? 'bg-gray-950/70 p-2 sm:p-4' : 'bg-gray-950/60 p-4'} backdrop-blur-xs`} role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose?.()}>
        <div role="dialog" aria-modal="true" aria-label={title} className={`relative flex ${darkTheme ? 'dark max-h-[calc(100dvh-1rem)] border-slate-700 bg-slate-950 sm:max-h-[90vh]' : 'max-h-[90vh] border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900'} w-full flex-col overflow-hidden rounded-2xl border shadow-2xl ${maxWidth}`}>
            <CrudModalHeader icon={icon} report={isReport} title={title} subtitle={subtitle} onClose={onClose} showClose={false} />
            <CrudModalCardContext.Provider value><ReportDetailsContext.Provider value={isReport ? (reportData || {}) : null}><div className={`custom-table-scrollbar min-h-0 flex-1 overflow-y-auto text-sm ${darkTheme ? 'bg-slate-950 text-slate-100' : ''} ${compact ? (darkTheme ? 'space-y-4 p-4 sm:p-5' : 'space-y-4 p-4 sm:p-5') : 'space-y-6 p-6'}`}>{displaySummary}{children}{displayAttachments}</div></ReportDetailsContext.Provider></CrudModalCardContext.Provider>
            <CrudModalFooter left={<>{canEdit && !terminalReport && onEdit && <Button type="button" size="compact" variant="primary" onClick={onEdit} className="rounded-xl px-4 py-2 text-xs">✏️ {editLabel}</Button>}{canDelete && !terminalReport && onDelete && <Button type="button" size="compact" variant="danger" onClick={onDelete} className="rounded-xl px-4 py-2 text-xs">{deleteLabel}</Button>}</>}>
                {footerActions}
                <Button type="button" size="compact" variant="primary" onClick={onClose} className="rounded-xl px-5 py-2 text-xs">{closeLabel}</Button>
            </CrudModalFooter>
        </div>
    </div>;
}
