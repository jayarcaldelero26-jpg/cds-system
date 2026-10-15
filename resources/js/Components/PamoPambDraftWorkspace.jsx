import { router, useForm } from '@inertiajs/react';
import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudTable from '@/Components/Crud/CrudTable';
import FileAttachmentPanel from '@/Components/Crud/FileAttachmentPanel';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import DatePicker from '@/Components/DatePicker';
import DateRangePicker from '@/Components/DateRangePicker';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';
import Button from '@/Components/Button';
import { createUuid } from '@/Utils/createUuid.mjs';
import { compactDateConductedRanges, emptyDateConductedRange, rangesFromRecord } from '@/Utils/dateConductedRanges';
import { formatMoney } from '@/Utils/moneyFormatters';
import { formatManila } from '@/Utils/dateFormatters';
import { invalidatePamoPendingRequest, startPamoPendingRequest } from '@/Utils/pamoPendingRequest.mjs';
import AwsSubmissionFields from '@/Components/Reports/AwsSubmissionFields';
import PamoGenericSubmissionFields from '@/Components/PamoGenericSubmissionFields';
import PamoReportPendingAnimation from '@/Components/PamoReportPendingAnimation';

const emptyDraft = { activity_name: '', document_type: '', reporting_period: '', semester: '', quarter: '', reporting_month: '', reporting_year: '', total_collected: '', deadline_submission: '', monitoring_period_start: '', monitoring_period_end: '', date_conducted: '', date_conducted_ranges: [], date_accomplished: '', remarks: '', mov: null, lock_version: 1 };

export function createPamoDraftFormData() {
    return { ...emptyDraft, date_conducted_ranges: [] };
}

export function serializePamoDraftPayload(data, { awsForm, periodField, rangeEnabled }) {
    if (awsForm) {
        const sourceFields = ['activity_name', 'document_type', 'quarter', 'date_conducted', 'monitoring_period_start', 'monitoring_period_end', 'date_accomplished', 'remarks', 'mov', 'lock_version'];
        const submission = Object.fromEntries(sourceFields.map(key => [key, data[key] !== undefined ? data[key] : (emptyDraft[key] ?? '')]));
        return { ...submission, quarter: submission.quarter ? Number(submission.quarter) : '' };
    }
    return { ...data, [periodField]: data[periodField] || '', date_conducted_ranges: rangeEnabled ? compactDateConductedRanges(data.date_conducted_ranges) : undefined };
}

const PamoPambDraftWorkspace = forwardRef(function PamoPambDraftWorkspace({ workflow: initialWorkflow, workflows = [], drafts = [], assignedProtectedArea, pamoContext = null }, ref) {
    const workflowCatalog = workflows.length ? workflows : (initialWorkflow ? [initialWorkflow] : []);
    const [workflowKey, setWorkflowKey] = useState(initialWorkflow?.key || workflowCatalog[0]?.key || '');
    const workflow = workflowCatalog.find(item => item.key === workflowKey) || initialWorkflow || workflowCatalog[0] || {};
    const [selectedId, setSelectedId] = useState('');
    const [correctionRecord, setCorrectionRecord] = useState(null);
    const [submitMode, setSubmitMode] = useState(false);
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState(null);
    const [pendingIntent, setPendingIntent] = useState(null);
    const pendingRequest = useRef({ sequence: 0, activeId: null, intent: null });
    const token = useRef(createUuid());
    const initialFormSnapshot = useRef('');
    const form = useForm(emptyDraft);
    const forwardForm = useForm({ lock_version: 1 });
    const workspaceKey = `${assignedProtectedArea?.id || ''}`;
    const previousWorkspaceKey = useRef(workspaceKey);
    const selected = drafts.find(draft => draft.id === selectedId) || null;
    const selectedContext = correctionRecord || selected;
    const rangeEnabled = Boolean(workflow.date_conducted_ranges_enabled);
    const awsForm = workflow.form === 'aws_submission';
    const revenueForm = workflow.form === 'revenue';
    const maintenanceForm = workflow.form === 'imea_maintenance';
    const ipafManagementForm = workflow.form === 'ipaf_management';
    const standardForm = workflow.form === 'standard_bms' || workflow.form === 'standard_a';
    const genericForm = !awsForm && !revenueForm && !maintenanceForm && !ipafManagementForm && !standardForm;
    const periodField = workflow.period_field || 'reporting_period';
    const periodOptions = workflow.period_options || (workflow.periods || []).map(period => ({ value: period, label: period }));
    const fileAccept = awsForm ? '.pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx' : '.pdf,.jpg,.jpeg,.png,.doc,.docx';
    const fileMaxBytes = awsForm || revenueForm || ipafManagementForm ? 10 * 1024 * 1024 : maintenanceForm ? 20 * 1024 * 1024 : 100 * 1024 * 1024;
    const revenueCents = Math.round(Number(form.data.total_collected || 0) * 100);
    const riaCents = Math.round(revenueCents * 0.75);
    const fileTypesHint = awsForm ? 'PDF, JPG, PNG, DOC, DOCX, XLS, or XLSX' : 'PDF, JPG, PNG, DOC, or DOCX';
    useEffect(() => () => { if (preview?.temporary) URL.revokeObjectURL(preview.url); }, [preview]);
    useEffect(() => () => invalidatePamoPendingRequest(pendingRequest.current), []);
    useEffect(() => {
        if (previousWorkspaceKey.current === workspaceKey) return;
        previousWorkspaceKey.current = workspaceKey;
        invalidatePamoPendingRequest(pendingRequest.current);
        setPendingIntent(null);
        setSelectedId('');
        setCorrectionRecord(null);
        setOpen(false);
        setPreview(null);
        token.current = createUuid();
        form.reset();
        form.clearErrors();
        forwardForm.reset();
    }, [workspaceKey]);

    const runPendingRequest = (intent, send, handlers = {}) => startPamoPendingRequest(pendingRequest.current, {
        intent,
        setIntent: setPendingIntent,
        send,
        ...handlers,
    });

    const initializeNew = () => createPamoDraftFormData();
    const snapshotForm = data => JSON.stringify({ ...data, mov: null });
    const hasUnsavedChanges = () => Boolean(form.data.mov) || (initialFormSnapshot.current !== '' && snapshotForm(form.data) !== initialFormSnapshot.current);
    const startNew = requestedKey => {
        const config = workflowCatalog.find(item => item.key === requestedKey) || workflowCatalog.find(item => item.key === workflowKey) || workflow;
        if (config?.key) setWorkflowKey(config.key);
        setSelectedId('');
        setCorrectionRecord(null);
        setPreview(null);
        token.current = createUuid();
        form.clearErrors();
        forwardForm.clearErrors();
        const initialData = initializeNew();
        form.setData(initialData);
        initialFormSnapshot.current = snapshotForm(initialData);
        setOpen(true);
    };
    useImperativeHandle(ref, () => ({ startNew, openDraft, submitDraft: draft => openDraft(draft, true) }));

    const openDraft = (draft, submit = false) => {
        const config = workflowCatalog.find(item => item.key === draft.workflow_key);
        if (!config) return;
        setWorkflowKey(config.key);
        setSelectedId(draft.id);
        setSubmitMode(submit);
        setCorrectionRecord(null);
        setPreview(null);
        form.clearErrors();
        forwardForm.clearErrors();
        const draftData = { ...emptyDraft, ...(draft.report_payload || {}), date_conducted_ranges: config.date_conducted_ranges_enabled ? rangesFromRecord(draft.report_payload?.date_conducted_ranges, draft.report_payload?.date_conducted) : [], mov: null, lock_version: draft.lock_version };
        form.setData(draftData);
        initialFormSnapshot.current = snapshotForm(draftData);
        forwardForm.setData('lock_version', draft.lock_version);
        setOpen(true);
    };

    const openCorrection = record => {
        const config = workflowCatalog.find(item => item.key === (record.workflow_key || workflowKey));
        if (config) setWorkflowKey(config.key);
        setSelectedId('');
        setSubmitMode(false);
        setCorrectionRecord(record);
        setPreview(null);
        form.clearErrors();
        forwardForm.clearErrors();
        const editable = Object.fromEntries(Object.keys(emptyDraft).map(key => [key, record[key] ?? emptyDraft[key]]));
        const correctionData = { ...editable, date_conducted_ranges: config?.date_conducted_ranges_enabled ? rangesFromRecord(record.date_conducted_ranges, record.date_conducted) : [], mov: null, lock_version: 1 };
        form.setData(correctionData);
        initialFormSnapshot.current = snapshotForm(correctionData);
        setOpen(true);
    };

    const switchWorkflow = key => {
        const config = workflowCatalog.find(item => item.key === key);
        if (!config || config.key === workflowKey) return;
        if (hasUnsavedChanges() && !window.confirm('Discard these unsaved changes and switch report activity?')) return;
        setWorkflowKey(config.key);
        setSelectedId('');
        setCorrectionRecord(null);
        setPreview(null);
        token.current = createUuid();
        form.clearErrors();
        forwardForm.clearErrors();
        const initialData = initializeNew();
        form.setData(initialData);
        initialFormSnapshot.current = snapshotForm(initialData);
    };

    const close = (saved = false) => {
        if (!saved && hasUnsavedChanges() && !window.confirm('Discard unsaved report changes?')) return;
        setOpen(false); setPreview(null); setCorrectionRecord(null); setSelectedId(''); setSubmitMode(false);
    };
    const serializeCurrentDraft = data => serializePamoDraftPayload(data, { awsForm, periodField, rangeEnabled });
    const save = event => {
        event.preventDefault();
        if (form.processing || pendingRequest.current.activeId !== null || selected?.status === 'forwarded') return;
        if (selected && submitMode) { forward(); return; }
        if (correctionRecord) {
            form.transform(data => ({ ...serializeCurrentDraft(data), source_updated_at: correctionRecord.source_updated_at, _method: 'put' }));
            runPendingRequest('save', callbacks => form.post(route('pamo-native-reports.correction.update', [workflow.key, correctionRecord.id]), { forceFormData: true, preserveScroll: true, ...callbacks }), {
                onSuccess: () => { form.transform(data => data); initialFormSnapshot.current = snapshotForm(form.data); close(true); },
                onError: () => form.transform(data => data),
            });
            return;
        }
        if (selected) {
            form.transform(data => ({ ...serializeCurrentDraft(data), lock_version: selected.lock_version, _method: 'put' }));
            runPendingRequest('save', callbacks => form.post(route('conservation-reports.pamo-drafts.update', [workflow.key, selected.id]), { forceFormData: true, preserveScroll: true, ...callbacks }), {
                onSuccess: () => { form.transform(data => data); initialFormSnapshot.current = snapshotForm(form.data); close(true); },
                onError: () => form.transform(data => data),
            });
            return;
        }
        form.transform(data => ({ ...serializeCurrentDraft(data), start_idempotency_key: token.current }));
        runPendingRequest('save', callbacks => form.post(route('conservation-reports.pamo-drafts.store', workflow.key), { forceFormData: true, preserveScroll: true, ...callbacks }), {
            onSuccess: () => { form.transform(data => data); initialFormSnapshot.current = snapshotForm(form.data); token.current = createUuid(); close(true); },
            onError: () => form.transform(data => data),
        });
    };
    const forward = () => {
        if (pendingRequest.current.activeId !== null) return;
        if (correctionRecord && !forwardForm.processing) {
            const action = correctionRecord.resubmit_action;
            if (!action) return;
            runPendingRequest('submit', callbacks => router.post(route('submission-tracking.transition', [workflow.source, correctionRecord.id, action]), { stage: action }, { preserveScroll: true, ...callbacks }), {
                onSuccess: () => close(true),
            });
            return;
        }
        if (!selected || forwardForm.processing) return;
        forwardForm.transform(() => ({ ...serializeCurrentDraft(form.data), lock_version: selected.lock_version }));
        runPendingRequest('submit', callbacks => forwardForm.post(route('conservation-reports.pamo-drafts.forward', [workflow.key, selected.id]), { forceFormData: true, preserveScroll: true, ...callbacks }), {
            onError: errors => Object.entries(errors).forEach(([name, message]) => form.setError(name, message)),
            onSuccess: () => { forwardForm.transform(data => data); initialFormSnapshot.current = snapshotForm(form.data); close(true); },
        });
    };
    const updateRange = (index, value) => form.setData('date_conducted_ranges', form.data.date_conducted_ranges.map((range, at) => at === index ? { from: value?.from || '', to: value?.to || '' } : range));
    const forwarded = selected?.status === 'forwarded';
    const inCorrection = Boolean(correctionRecord);
    const draftMode = !submitMode && !inCorrection && !forwarded;
    const contentRequired = !draftMode && !forwarded;
    const savedFile = selectedContext?.mov_url ? { url: selectedContext.mov_url, download_url: selectedContext.mov_url, name: selectedContext.mov_file_name || selectedContext.report_file_name } : null;
    const fileField = awsForm ? 'Formal AWS report file' : 'MOV Attachment';
    const maxSizeBytes = awsForm || revenueForm || ipafManagementForm ? 10 * 1024 * 1024 : maintenanceForm ? 20 * 1024 * 1024 : 100 * 1024 * 1024;
    const accept = awsForm ? '.xlsx,.xls,.docx,.pdf' : '.pdf,.jpg,.jpeg,.png,.doc,.docx';
    const change = name => event => form.setData(name, event.target.value);
    const fieldError = name => form.errors[name] && <span className="mt-1 block text-xs text-red-600">{form.errors[name]}</span>;

    return <>
        <CrudFormModal open={open} mode={selected || inCorrection ? 'edit' : 'create'} title={awsForm ? (inCorrection ? 'Correct Automated Weather Station Report Submission' : selected ? 'Edit Automated Weather Station Report Submission' : 'Add Automated Weather Station Report Submission') : inCorrection ? `Correct ${workflow.label} report` : selected ? (forwarded ? 'Forwarded report' : submitMode ? 'Submit report' : 'Edit draft') : 'New draft report'} subtitle={inCorrection ? 'This report was received back for correction. Save the corrected native fields, then resubmit.' : selected ? `Preparation started ${formatManila(selected.started_at)}` : 'Save privately, then submit when ready.'} onClose={close} onSubmit={save} processing={form.processing || forwardForm.processing} progress={form.progress} busyOverlay={pendingIntent ? <PamoReportPendingAnimation intent={pendingIntent} /> : null} busyOverlayPresentation="success-sized" errors={{ ...form.errors, ...forwardForm.errors }} canSave={!forwarded} saveLabel={inCorrection ? 'Save correction' : submitMode ? 'Submit Report' : 'Save Draft'} maxWidth="max-w-5xl" preview={<FilePreviewPanel file={preview || savedFile} title={awsForm ? 'Live Document Preview' : 'Report / MOV preview'} />}>
            {!selected && !inCorrection && workflowCatalog.length > 1 && <ScopedOptionSelect id="pamo-report-workflow" label="Report / Activity" value={workflow.key || ''} options={workflowCatalog.map(item => ({ id: item.key, label: item.label }))} onChange={switchWorkflow} required placeholder="Search report or activity" />}
            <p className="text-xs text-gray-600 dark:text-gray-300">Protected Area: <span className="font-semibold">{assignedProtectedArea?.name || 'Your assigned Protected Area'}</span></p>
            {!forwarded && !inCorrection && pamoContext?.first_recipient && <div className="flex items-start gap-2 rounded-xl border border-green-100 bg-green-50 px-3 py-2.5 text-sm text-green-950 dark:border-green-900/60 dark:bg-green-950/30 dark:text-green-100"><span aria-hidden="true">âž¤</span><div><p className="font-semibold">Will be sent to {pamoContext.first_recipient}</p><p className="mt-0.5 text-xs text-green-800/80 dark:text-green-200/80">Reports route to the assigned recipient after submission.</p></div></div>}
            {awsForm && <AwsSubmissionFields form={form} workflowConfig={workflow} pamoContext={{ protected_area: pamoContext?.protected_area || assignedProtectedArea?.name, target_office: pamoContext?.target_office }} attachmentField="mov" activeFile={preview || savedFile} savedFile={savedFile} onSelectFile={setPreview} onChangeFile={file => { form.setData('mov', file); setPreview(file ? { url: URL.createObjectURL(file), name: file.name, type: file.type, temporary: true } : savedFile); }} disabled={forwarded || form.processing} draftMode={draftMode} fileRequired={!savedFile} maxSizeBytes={maxSizeBytes} />}
            {standardForm && <CrudSection title="General / Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FloatingInput id="pamo-native-activity" label="Name of Activity" required={contentRequired} value={form.data.activity_name || ''} onChange={change('activity_name')} error={form.errors.activity_name} />
                <FloatingSelect id="pamo-native-document" label="Type of Document" value={form.data.document_type || ''} onChange={change('document_type')} error={form.errors.document_type}><option value="">Select Type of Document</option>{workflow.documents.map(document => <option key={document}>{document}</option>)}</FloatingSelect>
                <FloatingSelect id="pamo-native-semester" label="Semester" required={contentRequired} value={form.data.semester || ''} onChange={change('semester')} error={form.errors.semester}><option value="">Select Semester</option>{workflow.periods.map(period => <option key={period}>{period}</option>)}</FloatingSelect>
                <div className="sm:col-span-2 space-y-2"><p className="text-xs font-semibold text-gray-700 dark:text-gray-200">Date Conducted {contentRequired && <span className="text-red-600" aria-hidden="true">*</span>}</p>{(form.data.date_conducted_ranges || []).map((range, index) => <div key={index} className="flex flex-wrap items-end gap-2"><div className="min-w-52 flex-1"><DateRangePicker id={`pamo-native-date-${index}`} label={`Date ${index + 1}`} required={contentRequired} actualDate compactSeptember value={range} onChange={value => updateRange(index, value)} error={form.errors[`date_conducted_ranges.${index}.from`] || form.errors[`date_conducted_ranges.${index}.to`]} /></div><Button type="button" size="default" variant="danger" onClick={() => form.setData('date_conducted_ranges', form.data.date_conducted_ranges.filter((_, at) => at !== index))}>Remove</Button></div>)}<Button type="button" size="default" variant="primary" onClick={() => form.setData('date_conducted_ranges', [...form.data.date_conducted_ranges, emptyDateConductedRange()])}>+ Add Date</Button>{fieldError('date_conducted_ranges')}</div>
                <DatePicker id="pamo-native-date-accomplished" label="Date Accomplished" actualDate compactSeptember required={contentRequired} value={form.data.date_accomplished || ''} onChange={value => form.setData('date_accomplished', value)} error={form.errors.date_accomplished} />
            </div></CrudSection>}
            {genericForm && <PamoGenericSubmissionFields workflow={workflow} form={form} draftMode={draftMode} rangeEnabled={rangeEnabled} periodField={periodField} periodOptions={periodOptions} onActivityChange={activity => form.setData({ ...form.data, activity_name: activity, document_type: '' })} onPeriodChange={change(periodField)} onDateConductedChange={value => form.setData('date_conducted', value)} onDateAccomplishedChange={value => form.setData('date_accomplished', value)} onRangeChange={updateRange} onAddRange={() => form.setData('date_conducted_ranges', [...form.data.date_conducted_ranges, emptyDateConductedRange()])} onRemoveRange={index => form.setData('date_conducted_ranges', form.data.date_conducted_ranges.filter((_, at) => at !== index))} fieldError={fieldError} />}
            {maintenanceForm && <CrudSection title="General / Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><FloatingInput id="pamo-maintenance-activity" label="Name of Activity" required={contentRequired} value={form.data.activity_name || ''} onChange={change('activity_name')} error={form.errors.activity_name} /><FloatingSelect id="pamo-maintenance-document" label="Type of Document" required={contentRequired} value={form.data.document_type || ''} onChange={change('document_type')} error={form.errors.document_type}><option value="">Select Type of Document</option>{workflow.documents.map(document => <option key={document}>{document}</option>)}</FloatingSelect><FloatingSelect id="pamo-maintenance-quarter" label="Quarter" required={contentRequired} value={form.data.quarter || ''} onChange={change('quarter')} error={form.errors.quarter}><option value="">Select Quarter</option>{workflow.quarters.map(quarter => <option key={quarter}>{quarter}</option>)}</FloatingSelect><DatePicker id="pamo-maintenance-date-conducted" label="Date Conducted" required={contentRequired} actualDate compactSeptember value={form.data.date_conducted || ''} onChange={value => form.setData('date_conducted', value)} error={form.errors.date_conducted} /><DatePicker id="pamo-maintenance-date-accomplished" label="Date Accomplished" required={contentRequired} actualDate compactSeptember value={form.data.date_accomplished || ''} onChange={value => form.setData('date_accomplished', value)} error={form.errors.date_accomplished} /></div></CrudSection>}
            {ipafManagementForm && <CrudSection title="General / Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><FloatingSelect id="pamo-ipaf-management-document" label="Type of Document" required={contentRequired} value={form.data.document_type || ''} onChange={change('document_type')} error={form.errors.document_type}><option value="">Select Type of Document</option>{workflow.documents.map(document => <option key={document}>{document}</option>)}</FloatingSelect><DatePicker id="pamo-ipaf-management-date-conducted" label="Date Conducted" required={contentRequired} actualDate compactSeptember value={form.data.date_conducted || ''} onChange={value => form.setData('date_conducted', value)} error={form.errors.date_conducted} /><DatePicker id="pamo-ipaf-management-date-accomplished" label="Date Accomplished" required={contentRequired} actualDate compactSeptember value={form.data.date_accomplished || ''} onChange={value => form.setData('date_accomplished', value)} error={form.errors.date_accomplished} /></div></CrudSection>}
            {revenueForm && <><CrudSection title="Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><FloatingSelect id="pamo-revenue-month" label="Reporting Month" required={contentRequired} value={form.data.reporting_month || ''} onChange={change('reporting_month')} error={form.errors.reporting_month}><option value="">Select Month</option>{['January','February','March','April','May','June','July','August','September','October','November','December'].map((month,index)=><option key={month} value={index+1}>{month}</option>)}</FloatingSelect><FloatingInput id="pamo-revenue-year" type="number" label="Reporting Year" required={contentRequired} value={form.data.reporting_year || ''} onChange={change('reporting_year')} error={form.errors.reporting_year} /><FloatingSelect id="pamo-revenue-document" label="Type of Document" required={contentRequired} value={form.data.document_type || ''} onChange={change('document_type')} error={form.errors.document_type}><option value="">Select Type of Document</option>{workflow.documents.map(document => <option key={document}>{document}</option>)}</FloatingSelect></div></CrudSection><CrudSection title="Revenue Collection"><FloatingInput id="pamo-revenue-total" type="number" min="0" step="0.01" label="Total Collected" required={contentRequired} value={form.data.total_collected || ''} onChange={change('total_collected')} error={form.errors.total_collected} />{String(form.data.total_collected ?? "").trim() !== "" && <div className="mt-4 grid gap-3 sm:grid-cols-2">{[['IPAF RIA (75%)', riaCents / 100], ['SAGF (25%)', (revenueCents - riaCents) / 100]].map(([label, amount]) => <div key={label} className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900"><p className="text-[11px] font-bold uppercase text-gray-500">{label}</p><p className="mt-1 text-sm font-bold dark:text-white">â‚±{formatMoney(amount)}</p></div>)}</div>}</CrudSection><CrudSection title="Compliance Basis"><DatePicker id="pamo-revenue-deadline" label="Deadline for Submission to PENRO" required={contentRequired} compactSeptember value={form.data.deadline_submission || ''} onChange={value => form.setData('deadline_submission', value)} error={form.errors.deadline_submission} /></CrudSection></>}
            {!awsForm && <CrudSection title="MOV / Supporting Document & Remarks"><div className="space-y-4"><FileAttachmentPanel id="pamo-native-mov" label={fileField} accept={accept} acceptedTypesHint="PDF, JPG, PNG, DOC, or DOCX" maxSizeHint={`Maximum ${Math.round(maxSizeBytes / 1024 / 1024)} MB`} maxSizeBytes={maxSizeBytes} required={contentRequired && !savedFile} existingFiles={savedFile ? [savedFile] : []} selectedFiles={form.data.mov ? [form.data.mov] : []} activeFile={preview || savedFile} onSelectFile={setPreview} onChange={file => { form.setData('mov', file); setPreview(file ? { url: URL.createObjectURL(file), name: file.name, type: file.type, temporary: true } : savedFile); }} error={form.errors.mov || form.errors.report_file} disabled={forwarded || form.processing} canManage={!forwarded} /><FloatingTextarea id="pamo-native-remarks" label="Remarks" rows={4} value={form.data.remarks || ''} onChange={change('remarks')} error={form.errors.remarks} /></div></CrudSection>}
            {selected?.status === 'draft' && !submitMode && <p className="text-xs text-slate-500">Save changes privately, or choose Submit Report from the draft row when ready.</p>}
            {selectedContext?.source_url && <a href={selectedContext.source_url} className="block text-xs font-semibold text-emerald-800 underline dark:text-emerald-300">Open submission tracking</a>}
        </CrudFormModal>
    </>;
});

export default PamoPambDraftWorkspace;
