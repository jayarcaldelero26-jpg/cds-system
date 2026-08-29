import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import CrudTable from '@/Components/Crud/CrudTable';
import FileAttachmentPanel from '@/Components/Crud/FileAttachmentPanel';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import { useReportDetails } from '@/Components/Crud/ReportDetailsContext';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import TimelinessBadge, { isTimelinessValue } from '@/Components/TimelinessBadge';
import { formatReportDate } from '@/Utils/dateFormatters';

const label = 'block text-xs font-semibold text-gray-700 dark:text-gray-300';
const show = value => value === null || value === undefined || value === '' ? '\u2014' : value;
const date = value => formatReportDate(value);
const badge = value => isTimelinessValue(value) ? <TimelinessBadge value={value} /> : <span className="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-800 dark:bg-green-950/60 dark:text-green-200">{show(value)}</span>;

export default function Index({ workflow, workflowConfig, workflows = [], submissions = {}, periods = [], offices = [], filters = {}, summary = null, summaryRows = [] }) {
    const { auth = {} } = usePage().props;
    const canCreate = Boolean(auth.canCreateTechnicalReports);
    const canUpdate = Boolean(auth.canUpdateTechnicalReports);
    const canDelete = Boolean(auth.canDeleteTechnicalReports);
    if (!workflow) return <Summary workflows={summary || []} rows={summaryRows} />;
    return <Tracker workflow={workflow} config={workflowConfig} rows={submissions} periods={periods} offices={offices} filters={filters} canCreate={canCreate} canUpdate={canUpdate} canDelete={canDelete} />;
}

function Summary({ workflows, rows = [] }) {
    const columns = [{ key: 'workflow_label', label: 'Workflow', render: row => <span className="font-bold dark:text-white">{row.workflow_label}</span> }, { key: 'office', label: 'Office' }, { key: 'period_label', label: 'Reporting Period' }, { key: 'deadline_submission', label: 'Deadline', render: row => date(row.deadline_submission) }, { key: 'date_received_penro', label: 'PENRO Received', render: row => date(row.date_received_penro) }, { key: 'timeliness_rating', label: 'Timeliness', render: row => badge(row.timeliness_rating) }, { key: 'submission_status', label: 'Status', render: row => badge(row.submission_status) }];
    return <AuthenticatedLayout title="ENGP Summary Monitoring"><PageHeader title="ENGP REPORT SUBMISSION (HARD COPIES) TO PENRO" description="Normalized live status summary for National Greening Program reports." /><div className="mt-6 space-y-5"><CrudTable title="Summary Monitoring" subtitle="Weekly Accomplishment is intentionally excluded from this source summary." columns={[{ key: 'label', label: 'Workflow', render: row => <span className="font-bold dark:text-white">{row.label}</span> }, { key: 'records', label: 'Current Records', render: row => row.records }]} rows={workflows} rowKey="workflow_key" emptyTitle="No ENGP workflows" emptyDescription="No summary workflows are available." />{rows.length > 0 && <CrudTable title="Live Submission Status" subtitle="Current office and period status from ENGP report records." columns={columns} rows={rows} rowKey="id" emptyTitle="No ENGP records" emptyDescription="No live report records are available." tableClassName="min-w-[1100px]" />}</div></AuthenticatedLayout>;
}

function Tracker({ workflow, config, rows = {}, periods, offices, filters, canCreate, canUpdate, canDelete }) {
    const records = rows.data || [];
    const [selected, setSelected] = useState(null);
    const [modal, setModal] = useState(null);
    const [preview, setPreview] = useState(null);
    const empty = { office: '', section_name: '', reporting_year: 2026, period_key: periods[0]?.key || '', mov: null, mov_external_url: '', remarks: '' };
    const form = useForm(empty);

    useEffect(() => () => { if (preview?.temporary) URL.revokeObjectURL(preview.url); }, [preview]);

    const openCreate = () => { form.setData(empty); form.clearErrors(); setSelected(null); setPreview(null); setModal('create'); };
    const openEdit = record => { form.setData({ ...empty, office: record.office || '', section_name: record.section_name || '', reporting_year: record.reporting_year || 2026, period_key: record.period_key || '', deadline_submission: record.deadline_submission || '', mov: null, mov_external_url: record.mov_external_url || '', remarks: record.remarks || '' }); form.clearErrors(); setSelected(record); setPreview(record.mov || null); setModal('edit'); };
    const close = () => { setModal(null); setSelected(null); setPreview(null); form.reset(); form.clearErrors(); };
    const chooseMov = file => { form.setData('mov', file); setPreview(file ? { name: file.name, type: file.type, size: file.size, url: URL.createObjectURL(file), temporary: true } : selected?.mov || null); };
    const submit = event => { event.preventDefault(); const edit = modal === 'edit'; form.transform(data => edit ? { ...data, _method: 'put' } : data); form.post(edit ? route('engp-reports.update', [workflow, selected.id]) : route('engp-reports.store', workflow), { forceFormData: true, preserveScroll: true, onSuccess: close }); };
    const apply = changes => router.get(route('engp-reports.index', workflow), { ...filters, search: undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const columns = [{ key: 'office', label: 'Office', render: row => <span className="font-bold dark:text-white">{row.office}</span> }, { key: 'period_label', label: 'Reporting Period', render: row => row.period_label }, { key: 'activity_name', label: 'Activity', render: row => row.activity_name }, { key: 'document_type', label: 'Document', render: row => row.document_type }, { key: 'deadline_submission', label: 'Deadline', render: row => date(row.deadline_submission) }, { key: 'date_received_penro', label: 'PENRO Received', render: row => date(row.date_received_penro) }, { key: 'days_complied', label: 'Days Complied', render: row => show(row.days_complied) }, { key: 'timeliness_rating', label: 'Timeliness', render: row => badge(row.timeliness_rating) }, { key: 'submission_status', label: 'Status', render: row => badge(row.submission_status) }];
    const pagination = rows.links?.length > 3 ? <div className="flex flex-wrap gap-1">{rows.links.map((link, index) => <button key={index} type="button" disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })} className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${link.active ? 'bg-green-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-green-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'} disabled:cursor-not-allowed disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div> : null;

    return <AuthenticatedLayout title={config.label}>
        <PageHeader title={config.label} description="ENGP report submission monitoring and hard-copy routing." />
        <div className="mt-5 space-y-4">
            <div className="flex flex-col gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-end sm:justify-between"><div className="grid min-w-0 flex-1 gap-3 sm:grid-cols-2"><div className={label}><FloatingSelect id="engp-office-filter" label="Office" value={filters.office || ''} onChange={event => apply({ office: event.target.value || undefined })} size="sm"><option value="">All offices</option>{offices.map(office => <option key={office}>{office}</option>)}</FloatingSelect></div><div className={label}><FloatingSelect id="engp-period-filter" label="Reporting Period" value={filters.period_key || ''} onChange={event => apply({ period_key: event.target.value || undefined })} size="sm"><option value="">All periods</option>{periods.map(period => <option key={period.key} value={period.key}>{period.label}</option>)}</FloatingSelect></div></div>{canCreate && <button type="button" onClick={openCreate} className="shrink-0 rounded-xl bg-green-700 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-green-800">+ Add Report</button>}</div>
            <CrudTable compactEmpty={true} title={records.length > 0 ? config.label : undefined} subtitle={records.length > 0 ? `${rows.total ?? records.length} normalized record${(rows.total ?? records.length) === 1 ? '' : 's'}` : undefined} helperText={records.length > 0 ? 'Click any row to view details' : undefined} columns={columns} rows={records} rowKey="id" onRowClick={row => { setSelected(row); setModal('details'); }} emptyTitle="No ENGP reports" emptyDescription="No reports match the selected workflow and filters." pagination={records.length > 0 ? pagination : null} tableClassName="min-w-[1250px]" />
        </div>
        <CrudDetailsModal report open={modal === 'details' && Boolean(selected)} title={`${config.label} Details`} subtitle={`${selected?.office || ''} · ${selected?.period_label || ''}`} onClose={close} canEdit={canUpdate} canDelete={false} onEdit={() => openEdit(selected)} summary={selected && <CrudSummaryGrid items={[{ label: 'Reporting Period', value: selected.period_label || selected.period_key }, { label: 'Deadline', value: date(selected.deadline_submission) }, { label: 'Timeliness', render: () => badge(selected.timeliness_rating) }, { label: 'Submission Status', render: () => badge(selected.submission_status) }]} />} attachments={selected?.mov ? <FilePreviewPanel file={selected.mov} title="MOV / Attachment" heightClass="h-[360px]" /> : null}>
            <Details record={selected} />
        </CrudDetailsModal>
        <CrudFormModal open={modal === 'create' || modal === 'edit'} mode={modal === 'edit' ? 'edit' : 'create'} title={`${modal === 'edit' ? 'Edit' : 'Add'} ${config.label}`} subtitle="Period-based ENGP report submission record." onClose={close} onSubmit={submit} processing={form.processing} errors={form.errors} saveLabel={modal === 'edit' ? 'Update Report' : 'Save Report'} preview={<FilePreviewPanel file={preview} title="Live Document Preview" />}>
            <FormFields form={form} config={config} periods={periods} offices={offices} selected={selected} preview={preview} chooseMov={chooseMov} />
        </CrudFormModal>
    </AuthenticatedLayout>;
}

function FormFields({ form, config, periods, offices, selected, chooseMov, preview }) {
    const error = name => form.errors[name] && <span className="mt-1 block text-xs text-red-500">{form.errors[name]}</span>;
    const change = name => event => form.setData(name, event.target.value);

    return <>
        <CrudSection title="General / Report Information"><div className="grid gap-4 sm:grid-cols-2">
            <div className={label}><FloatingSelect id="engp-office" label="Office" required value={form.data.office} onChange={change('office')}><option value="">Select Office</option>{offices.map(office => <option key={office}>{office}</option>)}</FloatingSelect>{error('office')}</div>
            <div className={label}><FloatingInput id="engp-section" label="Name of Section" value={form.data.section_name || ''} onChange={change('section_name')} />{error('section_name')}</div>
            <div className={label}><FloatingInput id="engp-activity" label="Name of Activity" value={config.activity} readOnly /></div>
            <div className={label}><FloatingInput id="engp-document" label="Document Type" value={config.document} readOnly /></div>
            <div className={label}><FloatingInput id="engp-year" label="Reporting Year" required type="number" value={form.data.reporting_year} onChange={change('reporting_year')} />{error('reporting_year')}</div>
            <div className={label}><FloatingSelect id="engp-period" label="Reporting Period" required value={form.data.period_key} onChange={change('period_key')}><option value="">Select Period</option>{periods.map(period => <option key={period.key} value={period.key}>{period.label}</option>)}</FloatingSelect>{error('period_key')}</div>
        </div></CrudSection>
        <CrudSection title="Submission Deadline"><div className="rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900"><p className="text-[11px] font-bold uppercase text-gray-500">Deadline (System Derived)</p><p className="mt-1 font-bold dark:text-white">{date(form.data.deadline_submission)}</p></div></CrudSection>
        <CrudSection title="MOV / Attachment"><FileAttachmentPanel id="engp-mov" label="Report Attachment / MOV (Optional)" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" acceptedTypesHint="PDF, JPG, PNG, DOC, DOCX, XLS, or XLSX" maxSizeHint="Maximum 10 MB" existingFiles={selected?.mov ? [selected.mov] : []} selectedFiles={form.data.mov ? [form.data.mov] : []} activeFile={preview} onSelectFile={chooseMov} onChange={chooseMov} error={form.errors.mov} disabled={form.processing} canManage /><div className={label}><FloatingInput id="engp-mov-url" label="Legacy External MOV URL (Optional)" value={form.data.mov_external_url || ''} onChange={change('mov_external_url')} />{error('mov_external_url')}</div></CrudSection>
        <CrudSection title="Remarks"><div className={label}><FloatingTextarea id="engp-remarks" label="Remarks" rows="4" value={form.data.remarks || ''} onChange={change('remarks')} />{error('remarks')}</div></CrudSection>
    </>;
}

function Details({ record }) {
    if (!record) return null;
    return <div className="space-y-5"><CrudSection title="Report Information"><div className="grid gap-4 sm:grid-cols-2"><Detail label="Office">{show(record.office)}</Detail><Detail label="Name of Section">{show(record.section_name)}</Detail><Detail label="Name of Activity">{show(record.activity_name)}</Detail><Detail label="Document Type">{show(record.document_type)}</Detail></div></CrudSection><CrudSection title="CENRO Release Events"><div className="grid gap-4 sm:grid-cols-2">{(record.release_events || []).map(event => <Detail key={event.period_component} label={event.component_label}>{date(event.date_report_released_cenro)}</Detail>)}</div></CrudSection><CrudSection title="PENRO Submission"><div className="grid gap-4 sm:grid-cols-2"><Detail label="Date Received by PENRO">{date(record.date_received_penro)}</Detail><Detail label="Days Complied">{show(record.days_complied)}</Detail></div></CrudSection><CrudSection title="Remarks"><p>{record.remarks || 'None.'}</p></CrudSection></div>;
}

function Detail({ label: heading, children }) {
    const reportDetails = useReportDetails();
    const inSummary = /^(reporting period|semester|quarter|deadline|timeliness|status|submission status|report status)$/i.test(heading);
    if (reportDetails && inSummary) return null;
    return <div><span className="block text-xs text-gray-500">{heading}</span><span className="font-semibold text-gray-800 dark:text-gray-200">{children}</span></div>;
}
