import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import FileAttachmentPanel from '@/Components/Crud/FileAttachmentPanel';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import ConfirmDialog from '@/Components/ConfirmDialog';

const emptyReport = { protected_area_id: '', target_office: '', activity_name: '', document_type: '', semester: '1st Semester', date_conducted: '', date_accomplished: '', date_report_released_cenro: '', date_received_penro: '', date_endorsed_regional: '', mov: null, delete_mov: false, remarks: '' };
const badgeClass = value => ({ Outstanding: 'bg-emerald-500 text-white', 'Very Satisfactory': 'bg-green-600 text-white', Satisfactory: 'bg-amber-400 text-amber-950', Unsatisfactory: 'bg-orange-500 text-white', Poor: 'bg-red-600 text-white', 'Pending Submission by CENRO': 'bg-blue-600 text-white', 'Ongoing Preparation at CENRO Level': 'bg-blue-600 text-white', 'Report Not Yet Submitted': 'bg-red-600 text-white', 'Report Submitted': 'bg-green-600 text-white', 'No Activity Conducted': 'bg-gray-500 text-white', 'No Data': 'bg-gray-500 text-white' }[value] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200');
const dateValue = value => value ? String(value).slice(0, 10) : '';
const display = value => value === null || value === undefined || value === '' ? '—' : value;
const protectedAreaTableLabel = protectedArea => {
    const fullName = protectedArea?.name?.trim() || '';
    const shortName = protectedArea?.short_name?.trim();

    if (shortName) return { label: shortName, fullName: fullName || shortName };

    const parentheticalAcronym = fullName.match(/\(([^()]+)\)\s*$/)?.[1]?.trim();
    return { label: parentheticalAcronym || fullName || '—', fullName: fullName || '—' };
};
const Badge = ({ value }) => <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${badgeClass(value)}`}>{display(value)}</span>;
const Detail = ({ label, children }) => <div><span className="block text-xs text-gray-500">{label}:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{children}</span></div>;

export default function ReportSubmissionTracker({ submissions, protectedAreas, filters }) {
    const { auth = {} } = usePage().props;
    const canCreate = Boolean(auth.canCreateBms);
    const canUpdate = Boolean(auth.canUpdateBms);
    const canDelete = Boolean(auth.canDeleteBms);
    const rows = submissions?.data || [];
    const [modal, setModal] = useState(null);
    const [selectedReport, setSelectedReport] = useState(null);
    const [preview, setPreview] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const form = useForm(emptyReport);

    useEffect(() => () => { if (preview?.temporary) URL.revokeObjectURL(preview.url); }, [preview]);

    const currentMov = report => report?.mov_url ? { url: report.mov_url, name: report.mov_file_name || 'Current MOV attachment', type: '', temporary: false } : null;
    const resetFormState = () => { setPreview(null); form.reset(); form.clearErrors(); };
    const closeAll = () => { setModal(null); setSelectedReport(null); resetFormState(); };
    const openDetails = report => { setSelectedReport(report); setModal('details'); };
    const openCreate = () => { setSelectedReport(null); resetFormState(); form.setData('semester', filters?.report_semester || '1st Semester'); setModal('create'); };
    const openEdit = report => {
        setSelectedReport(report);
        form.clearErrors();
        form.setData({ protected_area_id: report.protected_area_id || '', target_office: report.target_office || '', activity_name: report.activity_name || '', document_type: report.document_type || '', semester: report.semester, date_conducted: report.date_conducted || '', date_accomplished: dateValue(report.date_accomplished), date_report_released_cenro: dateValue(report.date_report_released_cenro), date_received_penro: dateValue(report.date_received_penro), date_endorsed_regional: dateValue(report.date_endorsed_regional), mov: null, delete_mov: false, remarks: report.remarks || '' });
        setPreview(currentMov(report));
        setModal('edit');
    };
    const backFromForm = () => {
        resetFormState();
        setModal(selectedReport ? 'details' : null);
    };
    const selectMov = file => {
        form.setData({ ...form.data, mov: file, delete_mov: false });
        setPreview(file ? { url: URL.createObjectURL(file), name: file.name, type: file.type, temporary: true } : currentMov(selectedReport));
    };
    const submit = event => {
        event.preventDefault();
        const options = { forceFormData: true, preserveScroll: true, onSuccess: closeAll };
        modal === 'edit'
            ? form.transform(data => ({ ...data, _method: 'put' })).post(route('bms.report-submissions.update', selectedReport.id), options)
            : form.transform(data => data).post(route('bms.report-submissions.store'), options);
    };
    const applyFilters = changes => router.get(route('bms.index'), { report_protected_area_id: filters?.report_protected_area_id || '', report_semester: filters?.report_semester || '', tracker: 1, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const requestDelete = report => setDeleteTarget(report);
    const confirmDelete = () => {
        if (!deleteTarget || !canDelete || deleteProcessing) return;
        setDeleteProcessing(true);
        router.delete(route('bms.report-submissions.destroy', deleteTarget.id), {
            preserveScroll: true,
            onSuccess: () => { setDeleteTarget(null); closeAll(); },
            onFinish: () => setDeleteProcessing(false),
        });
    };

    const input = 'mt-1.5 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-green-600 focus:ring-green-600 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
    const label = 'block text-xs font-semibold text-gray-700 dark:text-gray-300';
    const error = name => form.errors[name] && <span className="mt-1 block text-xs text-red-500">{form.errors[name]}</span>;
    const change = name => event => form.setData(name, event.target.value);
    const calculated = selectedReport ? [['Deadline for Submission to PENRO', selectedReport.deadline_submission], ['Number of Days Complied', selectedReport.number_days_complied], ['Timeliness', selectedReport.timeliness], ['Status of Submission', selectedReport.submission_status], ['Total Number of Days Delayed at PENRO', selectedReport.total_days_delayed_penro]].filter(([, value]) => value !== null && value !== undefined && value !== '') : [];

    const columns = [
        {
            key: 'protected_area',
            label: 'Name of PA',
            render: row => {
                const protectedArea = protectedAreaTableLabel(row.protected_area);
                return <span title={protectedArea.fullName} className="block max-w-32 truncate font-semibold text-gray-900 dark:text-white">{protectedArea.label}</span>;
            },
        },
        { key: 'activity_name', label: 'Name of Activity', render: row => <span className="block min-w-40 max-w-72 whitespace-normal leading-5">{display(row.activity_name)}</span> },
        { key: 'date_conducted', label: 'Date Conducted', render: row => <span className="block min-w-32 max-w-56 whitespace-normal leading-5">{display(row.date_conducted)}</span> },
        { key: 'document_type', label: 'Type of Report', render: row => display(row.document_type) },
        { key: 'date_accomplished', label: 'Date Accomplished', render: row => display(dateValue(row.date_accomplished)) },
        { key: 'timeliness', label: 'Timeliness', render: row => <Badge value={row.timeliness} /> },
        { key: 'submission_status', label: 'Status of Submission', render: row => <span className="block max-w-52 whitespace-normal leading-5"><Badge value={row.submission_status} /></span> },
    ];

    const pagination = submissions?.links?.length > 3 ? <div className="flex flex-wrap gap-1">{submissions.links.map((link, index) => <button key={index} type="button" disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })} className={`rounded-lg px-3 py-1.5 text-xs font-bold ${link.active ? 'bg-green-700 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-200'} disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div> : null;
    const detailsMov = currentMov(selectedReport);

    return <div className="space-y-4">
        <div className="flex flex-col gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-end sm:justify-between">
            <div className="flex flex-wrap gap-3"><label className="text-xs font-bold text-gray-600 dark:text-gray-300">Semester<select value={filters?.report_semester || ''} onChange={event => applyFilters({ report_semester: event.target.value, report_page: 1 })} className="mt-1 block rounded-xl border-gray-300 text-sm dark:bg-gray-900"><option value="">All Semesters</option><option>1st Semester</option><option>2nd Semester</option></select></label><label className="text-xs font-bold text-gray-600 dark:text-gray-300">Protected Area<select value={filters?.report_protected_area_id || ''} onChange={event => applyFilters({ report_protected_area_id: event.target.value, report_page: 1 })} className="mt-1 block min-w-56 rounded-xl border-gray-300 text-sm dark:bg-gray-900"><option value="">All Protected Areas</option>{protectedAreas.map(pa => <option key={pa.id} value={pa.id}>{pa.name}</option>)}</select></label></div>
            {canCreate && <button type="button" onClick={openCreate} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800">+ Add Report</button>}
        </div>

        <CrudTable title="BMS Report Submission Tracker" subtitle={`${submissions?.total ?? rows.length} report submission${(submissions?.total ?? rows.length) === 1 ? '' : 's'}`} helperText="Click any row to view full details" caption="BMS report submission tracker" columns={columns} rows={rows} rowKey="id" onRowClick={openDetails} emptyTitle="No report submissions found" emptyDescription="No BMS report submissions match the selected filters." pagination={pagination} />

        <CrudDetailsModal open={modal === 'details' && Boolean(selectedReport)} title="BMS Report Submission Full Details" subtitle={selectedReport ? `${selectedReport.protected_area?.name || 'No protected area'} · ${selectedReport.semester || 'No reporting period'}` : ''} onClose={closeAll} canEdit={canUpdate} onEdit={() => openEdit(selectedReport)} editLabel="Edit This Submission" summary={selectedReport && <CrudSummaryGrid items={[
            { label: 'Reporting Period', value: selectedReport.semester || '—' },
            { label: 'Report Status', render: () => <Badge value={selectedReport.submission_status} /> },
            { label: 'Deadline', value: selectedReport.deadline_submission || '—' },
            { label: 'Timeliness Rating', render: () => <Badge value={selectedReport.timeliness} /> },
        ]} />} attachments={selectedReport && <FilePreviewPanel file={detailsMov} title="MOV / Attachment" heightClass="h-[480px]" />}>
            {selectedReport && <div className="space-y-6">
                <CrudSection title="Report Information"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"><Detail label="Target Office">{display(selectedReport.target_office)}</Detail><Detail label="Protected Area">{display(selectedReport.protected_area?.name)}</Detail><Detail label="Name of Activity">{display(selectedReport.activity_name)}</Detail><Detail label="Type of Document">{display(selectedReport.document_type)}</Detail><Detail label="Semester">{display(selectedReport.semester)}</Detail><Detail label="Date Conducted">{display(selectedReport.date_conducted)}</Detail></div></CrudSection>
                <CrudSection title="Submission / Compliance Details"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"><Detail label="Date Accomplished">{display(dateValue(selectedReport.date_accomplished))}</Detail><Detail label="Deadline for Submission to PENRO">{display(selectedReport.deadline_submission)}</Detail><Detail label="Date Report Released by CENRO Records">{display(dateValue(selectedReport.date_report_released_cenro))}</Detail><Detail label="Date Received by PENRO Records">{display(dateValue(selectedReport.date_received_penro))}</Detail><Detail label="Date Endorsed to Regional Office">{display(dateValue(selectedReport.date_endorsed_regional))}</Detail><Detail label="Total Number of Days Delayed at PENRO">{display(selectedReport.total_days_delayed_penro)}</Detail></div></CrudSection>
                <CrudSection title="Timeliness"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-3"><Detail label="Number of Days Complied">{display(selectedReport.number_days_complied)}</Detail><Detail label="Timeliness Rating"><Badge value={selectedReport.timeliness} /></Detail><Detail label="Submission Status"><Badge value={selectedReport.submission_status} /></Detail></div></CrudSection>
                <CrudSection title="Remarks"><p className="whitespace-pre-wrap text-xs text-gray-800 dark:text-gray-200">{selectedReport.remarks || 'None.'}</p></CrudSection>
            </div>}
        </CrudDetailsModal>

        <CrudFormModal open={modal === 'create' || modal === 'edit'} mode={modal === 'edit' ? 'edit' : 'create'} icon="📋" title={modal === 'edit' ? 'Edit BMS Report Submission' : 'Add BMS Report Submission'} subtitle={modal === 'edit' ? 'Update report details and review the MOV side-by-side.' : 'Record report compliance details and supporting MOV.'} onClose={backFromForm} onSubmit={submit} processing={form.processing} errors={form.errors} canDelete={modal === 'edit' && canDelete} onDelete={() => requestDelete(selectedReport)} saveLabel={modal === 'edit' ? 'Save Changes' : 'Save Report'} preview={<FilePreviewPanel file={preview} title="Live Document Preview" />}>
            <CrudSection title="General / Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label className={label}>Target Office<input value={form.data.target_office} onChange={change('target_office')} className={input} />{error('target_office')}</label>
                <label className={label}>Name of PA<select value={form.data.protected_area_id} onChange={change('protected_area_id')} className={input}><option value="">Select Protected Area</option>{protectedAreas.map(pa => <option key={pa.id} value={pa.id}>{pa.name}</option>)}</select>{error('protected_area_id')}</label>
                <label className={label}>Name of Activity<input value={form.data.activity_name} onChange={change('activity_name')} className={input} />{error('activity_name')}</label>
                <label className={label}>Type of Document<input value={form.data.document_type} onChange={change('document_type')} className={input} />{error('document_type')}</label>
                <label className={label}>Semester<select value={form.data.semester} onChange={change('semester')} className={input}><option>1st Semester</option><option>2nd Semester</option></select>{error('semester')}</label>
                <label className={label}>Date Conducted<input value={form.data.date_conducted} onChange={change('date_conducted')} className={input} placeholder="Enter date or coverage period" />{error('date_conducted')}</label>
            </div></CrudSection>
            <CrudSection title="Submission Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['date_accomplished', 'Date Accomplished'], ['date_report_released_cenro', 'Date Report Released by CENRO Records'], ['date_received_penro', 'Date Received by PENRO Records'], ['date_endorsed_regional', 'Date Endorsed to Regional Office']].map(([name, text]) => <label key={name} className={label}>{text}<input type="date" value={form.data[name]} onChange={change(name)} className={input} />{error(name)}</label>)}</div>{calculated.length > 0 && <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">{calculated.map(([text, value]) => <div key={text} className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900"><p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-gray-500">{text}</p><Badge value={value} /></div>)}</div>}</CrudSection>
            <CrudSection title="Attachment / MOV & Remarks"><div className="space-y-5"><FileAttachmentPanel id="bms-report-mov" label="MOV Attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" acceptedTypesHint="PDF, JPG, PNG, DOC, or DOCX" maxSizeHint="Maximum 10 MB" existingFiles={selectedReport?.mov_url && !form.data.delete_mov ? [currentMov(selectedReport)] : []} selectedFiles={form.data.mov ? [form.data.mov] : []} activeFile={preview} onSelectFile={setPreview} onChange={selectMov} onRemoveExisting={() => { form.setData('delete_mov', true); setPreview(null); }} error={form.errors.mov} disabled={form.processing} canManage={modal === 'create' ? canCreate : canUpdate} /><label className={label}>Remarks<textarea rows="4" value={form.data.remarks} onChange={change('remarks')} className={input} />{error('remarks')}</label></div></CrudSection>
        </CrudFormModal>

        <ConfirmDialog open={Boolean(deleteTarget) && canDelete} variant="danger" title="Delete Report Submission?" message={`Delete the report submission for “${deleteTarget?.activity_name || 'this activity'}”? This cannot be undone.`} confirmLabel="Delete Record" onConfirm={confirmDelete} onCancel={() => setDeleteTarget(null)} processing={deleteProcessing} />
    </div>;
}
