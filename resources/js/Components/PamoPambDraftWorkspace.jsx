import { useForm } from '@inertiajs/react';
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
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

const emptyDraft = { activity_name: '', document_type: '', reporting_period: '', date_conducted: '', date_conducted_ranges: [], date_accomplished: '', remarks: '', mov: null, lock_version: 1 };

const PamoPambDraftWorkspace = forwardRef(function PamoPambDraftWorkspace({ workflow, drafts = [], assignedProtectedArea }, ref) {
    const [selectedId, setSelectedId] = useState('');
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState(null);
    const token = useRef(createUuid());
    const form = useForm(emptyDraft);
    const forwardForm = useForm({ lock_version: 1 });
    const workspaceKey = `${workflow?.key || ''}:${assignedProtectedArea?.id || ''}`;
    const previousWorkspaceKey = useRef(workspaceKey);
    const selected = drafts.find(draft => draft.id === selectedId) || null;
    const rangeEnabled = Boolean(workflow.date_conducted_ranges_enabled);
    const documentOptions = useMemo(() => (workflow.activity_documents || {})[form.data.activity_name] || workflow.documents || [], [workflow.activity_documents, workflow.documents, form.data.activity_name]);
    const savedFile = selected?.mov_url ? { url: selected.mov_url, download_url: selected.mov_url, name: selected.mov_file_name } : null;
    useEffect(() => () => { if (preview?.temporary) URL.revokeObjectURL(preview.url); }, [preview]);
    useEffect(() => {
        if (previousWorkspaceKey.current === workspaceKey) return;
        previousWorkspaceKey.current = workspaceKey;
        setSelectedId('');
        setOpen(false);
        setPreview(null);
        token.current = createUuid();
        form.reset();
        form.clearErrors();
        forwardForm.reset();
    }, [workspaceKey]);

    const startNew = () => {
        setSelectedId('');
        setPreview(null);
        token.current = createUuid();
        form.clearErrors();
        forwardForm.clearErrors();
        form.setData({ ...emptyDraft, activity_name: workflow.activities?.length === 1 ? workflow.activities[0] : workflow.default_activity || '', date_conducted_ranges: rangeEnabled ? [emptyDateConductedRange()] : [] });
        setOpen(true);
    };
    useImperativeHandle(ref, () => ({ startNew, openDraft }));

    const openDraft = draft => {
        setSelectedId(draft.id);
        setPreview(null);
        form.clearErrors();
        forwardForm.clearErrors();
        form.setData({ ...emptyDraft, ...(draft.report_payload || {}), date_conducted_ranges: rangeEnabled ? rangesFromRecord(draft.report_payload?.date_conducted_ranges, draft.report_payload?.date_conducted) : [], mov: null, lock_version: draft.lock_version });
        forwardForm.setData('lock_version', draft.lock_version);
        setOpen(true);
    };

    const close = () => { setOpen(false); setPreview(null); };
    const save = event => {
        event.preventDefault();
        if (form.processing || selected?.status === 'forwarded') return;
        const payload = data => ({ ...data, date_conducted_ranges: rangeEnabled ? compactDateConductedRanges(data.date_conducted_ranges) : undefined });
        if (selected) {
            form.transform(data => ({ ...payload(data), lock_version: selected.lock_version, _method: 'put' }));
            form.post(route('conservation-reports.pamo-drafts.update', [workflow.key, selected.id]), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.transform(data => data); close(); }, onError: () => form.transform(data => data) });
            return;
        }
        form.transform(data => ({ ...payload(data), start_idempotency_key: token.current }));
        form.post(route('conservation-reports.pamo-drafts.store', workflow.key), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.transform(data => data); token.current = createUuid(); close(); }, onError: () => form.transform(data => data) });
    };
    const forward = () => {
        if (!selected || forwardForm.processing) return;
        forwardForm.setData('lock_version', selected.lock_version);
        forwardForm.post(route('conservation-reports.pamo-drafts.forward', [workflow.key, selected.id]), { preserveScroll: true, onError: errors => Object.entries(errors).forEach(([name, message]) => form.setError(name, message)), onSuccess: close });
    };
    const updateRange = (index, value) => form.setData('date_conducted_ranges', form.data.date_conducted_ranges.map((range, at) => at === index ? { from: value?.from || '', to: value?.to || '' } : range));
    const forwarded = selected?.status === 'forwarded';

    return <>
        <CrudFormModal open={open} mode={selected ? 'edit' : 'create'} title={selected ? (forwarded ? 'Forwarded report' : 'Edit draft') : `New ${workflow.label} draft`} subtitle={selected ? `Preparation started ${formatManila(selected.started_at)}` : 'Save work in progress, then Forward when complete.'} onClose={close} onSubmit={save} processing={form.processing || forwardForm.processing} progress={form.progress} errors={{ ...form.errors, ...forwardForm.errors }} canSave={!forwarded} saveLabel="Save draft" preview={<FilePreviewPanel file={preview || savedFile} title="Report / MOV preview" />}>
            <CrudSection title="Report information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <p className="sm:col-span-2 text-xs text-gray-600 dark:text-gray-300">Protected Area: <span className="font-semibold">{assignedProtectedArea?.name || 'Your assigned Protected Area'}</span></p>
                {workflow.activities?.length > 1 && <div>{workflow.allow_custom_activity ? <FloatingInput label="Activity" value={form.data.activity_name || ''} onChange={event => form.setData('activity_name', event.target.value)} /> : <ScopedOptionSelect id="pamo-draft-activity" label="Activity" value={form.data.activity_name || ''} options={workflow.activities.map(activity => ({ id: activity, label: activity }))} onChange={activity => form.setData({ ...form.data, activity_name: activity, document_type: '' })} error={form.errors.activity_name} placeholder="Search activities" />}</div>}
                <FloatingSelect label="Document type" value={form.data.document_type || ''} onChange={event => form.setData('document_type', event.target.value)}><option value="">Select document type</option>{documentOptions.map(document => <option key={document} value={document}>{document}</option>)}</FloatingSelect>
                <FloatingSelect label={workflow.period_label || 'Reporting period'} value={form.data.reporting_period || ''} onChange={event => form.setData('reporting_period', event.target.value)}><option value="">Select period</option>{(workflow.periods || []).map(period => <option key={period} value={period}>{period}</option>)}</FloatingSelect>
                {rangeEnabled ? <div className="sm:col-span-2 space-y-2"><p className="text-xs font-semibold text-gray-700 dark:text-gray-200">Date conducted</p>{(form.data.date_conducted_ranges || []).map((range, index) => <div key={index} className="flex flex-wrap items-end gap-2"><div className="min-w-52 flex-1"><DateRangePicker id={`pamo-draft-range-${index}`} label={`Date ${index + 1}`} actualDate value={range} onChange={value => updateRange(index, value)} error={form.errors[`date_conducted_ranges.${index}.from`] || form.errors[`date_conducted_ranges.${index}.to`]} /></div><Button type="button" size="compact" variant="danger" onClick={() => form.setData('date_conducted_ranges', form.data.date_conducted_ranges.filter((_, at) => at !== index))}>Remove</Button></div>)}<Button type="button" size="compact" onClick={() => form.setData('date_conducted_ranges', [...form.data.date_conducted_ranges, emptyDateConductedRange()])}>Add date</Button></div> : <DatePicker id="pamo-draft-date-conducted" label="Date conducted" actualDate value={form.data.date_conducted || ''} onChange={value => form.setData('date_conducted', value)} error={form.errors.date_conducted} />}
                <DatePicker id="pamo-draft-date-accomplished" label="Date accomplished" actualDate value={form.data.date_accomplished || ''} onChange={value => form.setData('date_accomplished', value)} error={form.errors.date_accomplished} />
            </div></CrudSection>
            <CrudSection title="Report attachment & remarks"><div className="space-y-4"><FileAttachmentPanel id="pamo-draft-mov" label="Report / MOV" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" acceptedTypesHint="PDF, JPG, PNG, DOC, or DOCX" maxSizeHint="Maximum 100 MB" maxSizeBytes={100 * 1024 * 1024} existingFiles={savedFile ? [savedFile] : []} selectedFiles={form.data.mov ? [form.data.mov] : []} activeFile={preview || savedFile} onSelectFile={setPreview} onChange={file => { form.setData('mov', file); setPreview(file ? { url: URL.createObjectURL(file), name: file.name, type: file.type, temporary: true } : savedFile); }} error={form.errors.mov} disabled={forwarded || form.processing} canManage={!forwarded} /><FloatingTextarea label="Remarks" value={form.data.remarks || ''} onChange={event => form.setData('remarks', event.target.value)} error={form.errors.remarks} rows={3} /></div></CrudSection>
            {selected?.status === 'draft' && <Button type="button" size="compact" variant="success" disabled={forwardForm.processing || form.processing} onClick={forward}>Forward when complete</Button>}
            {selected?.source_url && <a href={selected.source_url} className="block text-xs font-semibold text-emerald-800 underline dark:text-emerald-300">Open submitted report</a>}
        </CrudFormModal>
    </>;
});

export default PamoPambDraftWorkspace;

function formatManila(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(date);
}
