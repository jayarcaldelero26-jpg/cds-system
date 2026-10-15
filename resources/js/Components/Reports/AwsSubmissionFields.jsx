import CrudSection from '@/Components/Crud/CrudSection';
import DatePicker from '@/Components/DatePicker';
import DateRangePicker from '@/Components/DateRangePicker';
import FileAttachmentPanel from '@/Components/Crud/FileAttachmentPanel';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import ProtectedAreaSelect from '@/Components/Form/ProtectedAreaSelect';
import TargetOfficeSelect from '@/Components/Form/TargetOfficeSelect';
import { canonicalDateConductedValue, legacyDateConductedHelper } from '@/Utils/dateConductedRanges';

/** Shared native AWS report submission fields. PAMO supplies read-only actor context and draft handlers. */
export default function AwsSubmissionFields({
    form, workflowConfig, targetOffices = [], protectedAreasByOffice = {}, pamoContext = null,
    attachmentField = 'report_file', activeFile = null, savedFile = null, onSelectFile = () => {},
    onChangeFile = () => {}, disabled = false, fileRequired = false, draftMode = false, maxSizeBytes = 100 * 1024 * 1024,
}) {
    const error = name => form.errors[name] && <span className="mt-1 block text-xs text-red-500">{form.errors[name]}</span>;
    const change = name => event => form.setData(name, event.target.value);
    const periodField = workflowConfig?.period_field || 'quarter';
    const periods = workflowConfig?.period_options || [];
    const fileLabel = `Report Attachment / ${form.data.document_type || 'Document'}`;
    const documentTypes = workflowConfig?.documents || [];
    const selectedDocumentIsLegacy = form.data.document_type && !documentTypes.includes(form.data.document_type);
    const documentLabel = form.data.document_type === 'Reso' ? 'Resolution' : form.data.document_type || 'Document';

    return <>
        <CrudSection title="General / Report Information">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {pamoContext ? <>
                    <div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><span className="mb-1 block">Target Office</span><div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 font-medium dark:border-gray-700 dark:bg-gray-800">{pamoContext.target_office}</div></div>
                    <div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><span className="mb-1 block">Name of PA</span><div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 font-medium dark:border-gray-700 dark:bg-gray-800">{pamoContext.protected_area}</div></div>
                </> : <>
                    <div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><TargetOfficeSelect id="reportsubmissiontracker-target-office" value={form.data.target_office || ''} options={targetOffices} required onChange={id => form.setData({ ...form.data, target_office: id, protected_area_id: '' })} error={form.errors.target_office} /></div>
                    <div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><ProtectedAreaSelect id="reportsubmissiontracker-name-of-pa" label="Name of PA" required value={form.data.protected_area_id || ''} targetOfficeId={form.data.target_office} protectedAreasByOffice={protectedAreasByOffice} onChange={value => form.setData('protected_area_id', value)} error={form.errors.protected_area_id} disabled={!form.data.target_office} /></div>
                </>}
                <div><FloatingInput id="reportsubmissiontracker-name-of-activity" label="Name of Activity" required={!draftMode && workflowConfig?.activity_required !== false} value={form.data.activity_name || ''} onChange={change('activity_name')} error={form.errors.activity_name} /></div>
                <div><FloatingSelect id="reportsubmissiontracker-type-of-document" label="Type of Document" required={!draftMode && workflowConfig?.document_type_required !== false} value={form.data.document_type || ''} onChange={change('document_type')} error={form.errors.document_type}><option value="">Select Type of Document</option>{selectedDocumentIsLegacy && <option value={form.data.document_type}>{documentLabel} (Legacy)</option>}{documentTypes.map(document => <option key={document} value={document}>{document === 'Reso' ? 'Resolution' : document}</option>)}</FloatingSelect>{error('document_type')}</div>
                <div><FloatingSelect id="reportsubmissiontracker-reporting-period" label={workflowConfig?.period_label || 'Reporting Period'} required={!draftMode && workflowConfig?.period_required !== false} value={form.data[periodField] || ''} onChange={change(periodField)} error={form.errors[periodField]}><option value="">Select {workflowConfig?.period_label || 'Reporting Period'}</option>{periods.map(period => <option key={period.value} value={period.value}>{period.label}</option>)}</FloatingSelect>{error(periodField)}</div>
                <div><DatePicker id="reportsubmissiontracker-date-conducted" label="Date Conducted" actualDate value={canonicalDateConductedValue(form.data.date_conducted)} onChange={value => form.setData('date_conducted', value)} required={!draftMode && Boolean(workflowConfig?.date_conducted_required)} helperText={legacyDateConductedHelper(form.data.date_conducted)} error={form.errors.date_conducted} /></div>
                {workflowConfig?.coverage_period && <div><DateRangePicker id="reportsubmissiontracker-coverage-period" label="Coverage Period" value={{ from: form.data.monitoring_period_start || '', to: form.data.monitoring_period_end || '' }} onChange={({ from, to }) => form.setData({ ...form.data, monitoring_period_start: from, monitoring_period_end: to })} error={form.errors.monitoring_period_start || form.errors.monitoring_period_end} />{error('monitoring_period_start')}{error('monitoring_period_end')}</div>}
            </div>
        </CrudSection>
        <CrudSection title="Compliance Basis"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><div><DatePicker id="bms-report-date-accomplished" label="Date Accomplished" actualDate required={!draftMode && workflowConfig?.date_accomplished_required !== false} value={form.data.date_accomplished || ''} onChange={value => form.setData('date_accomplished', value)} error={form.errors.date_accomplished} />{error('date_accomplished')}</div></div></CrudSection>
        <CrudSection title="Report Attachment & Remarks"><div className="space-y-5"><FileAttachmentPanel id="reportsubmissiontracker-form-mov" label={fileLabel} required={!draftMode && fileRequired} requiredMessage={`${documentLabel} attachment is required.`} accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" acceptedTypesHint="PDF, JPG, PNG, DOC, or DOCX" maxSizeHint={`Maximum ${Math.round(maxSizeBytes / 1024 / 1024)} MB`} maxSizeBytes={maxSizeBytes} sizeErrorLabel={`${documentLabel} attachment`} existingFiles={savedFile ? [savedFile] : []} selectedFiles={form.data[attachmentField] ? [form.data[attachmentField]] : []} activeFile={activeFile} onSelectFile={onSelectFile} onChange={onChangeFile} error={form.errors[attachmentField] || form.errors.mov} disabled={disabled} canManage={!disabled} /><div><FloatingTextarea id="reportsubmissiontracker-remarks" label="Remarks" rows="4" value={form.data.remarks || ''} onChange={change('remarks')} error={form.errors.remarks} /></div></div></CrudSection>
    </>;
}
