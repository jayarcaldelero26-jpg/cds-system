import { FloatingSelect, FloatingInput, FloatingTextarea } from "@/Components/Form";import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import FileAttachmentPanel from '@/Components/Crud/FileAttachmentPanel';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { formatReportValue } from '@/Utils/dateFormatters';
import Tooltip from '@/Components/Tooltip';

const emptyReport = { protected_area_id: '', target_office: '', activity_name: '', document_type: '', semester: '1st Semester', date_conducted: '', date_accomplished: '', date_report_released_cenro: '', date_received_penro: '', date_endorsed_regional: '', mov: null, delete_mov: false, remarks: '' };
const badgeClass = (value) => ({ Outstanding: 'bg-emerald-500 text-white', 'Very Satisfactory': 'bg-green-600 text-white', Satisfactory: 'bg-amber-400 text-amber-950', Unsatisfactory: 'bg-orange-500 text-white', Poor: 'bg-red-600 text-white', 'Pending Submission by CENRO': 'bg-blue-600 text-white', 'Ongoing Preparation at CENRO Level': 'bg-blue-600 text-white', 'Report Not Yet Submitted': 'bg-red-600 text-white', 'Report Submitted': 'bg-green-600 text-white', 'No Activity Conducted': 'bg-gray-500 text-white', 'No Data': 'bg-gray-500 text-white', 'No Rating': 'bg-gray-500 text-white' })[value] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
const dateValue = (value) => value ? String(value).slice(0, 10) : '';
const display = (value) => formatReportValue(value);
const protectedAreaTableLabel = (protectedArea) => {
  const fullName = protectedArea?.name?.trim() || '';
  const shortName = protectedArea?.short_name?.trim();

  if (shortName) return { label: shortName, fullName: fullName || shortName };

  const parentheticalAcronym = fullName.match(/\(([^()]+)\)\s*$/)?.[1]?.trim();
  return { label: parentheticalAcronym || fullName || '—', fullName: fullName || '—' };
};
const Badge = ({ value }) => <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${badgeClass(value)}`}>{display(value)}</span>;
const Detail = ({ label, children }) => <div><span className="block text-xs text-gray-500">{label}:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{children}</span></div>;

export default function ReportSubmissionTracker({ submissions, protectedAreas, filters, moduleLabel = 'BMS', submissionRoutes, filterPrefix = 'report_', permissions = null }) {
  if (!submissionRoutes || typeof submissionRoutes.store !== 'string' || typeof submissionRoutes.index !== 'string' || typeof submissionRoutes.update !== 'function' || typeof submissionRoutes.destroy !== 'function' || typeof submissionRoutes.mov !== 'function') {
    throw new Error(`${moduleLabel} ReportSubmissionTracker requires store, index, update, destroy, and MOV routes.`);
  }
  const { auth = {} } = usePage().props;
  const canCreate = permissions?.create ?? Boolean(auth.canCreateBms);
  const canUpdate = permissions?.update ?? Boolean(auth.canUpdateBms);
  const canDelete = permissions?.delete ?? Boolean(auth.canDeleteBms);
  const semesterFilter = `${filterPrefix}semester`;
  const protectedAreaFilter = `${filterPrefix}protected_area_id`;
  const pageFilter = `${filterPrefix}page`;
  const rows = submissions?.data || [];
  const [modal, setModal] = useState(null);
  const [selectedReport, setSelectedReport] = useState(null);
  const [preview, setPreview] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleteProcessing, setDeleteProcessing] = useState(false);
  const form = useForm(emptyReport);

  useEffect(() => () => {if (preview?.temporary) URL.revokeObjectURL(preview.url);}, [preview]);

  const currentMov = (report) => report?.mov_url ? { url: submissionRoutes.mov(report), name: report.mov_file_name || 'Current MOV attachment', type: '', temporary: false } : null;
  const resetFormState = () => {setPreview(null);form.reset();form.clearErrors();};
  const closeAll = () => {setModal(null);setSelectedReport(null);resetFormState();};
  const openDetails = (report) => {setSelectedReport(report);setModal('details');};
  const openCreate = () => {setSelectedReport(null);resetFormState();form.setData('semester', filters?.[semesterFilter] || '1st Semester');setModal('create');};
  const openEdit = (report) => {
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
  const selectMov = (file) => {
    form.setData({ ...form.data, mov: file, delete_mov: false });
    setPreview(file ? { url: URL.createObjectURL(file), name: file.name, type: file.type, temporary: true } : currentMov(selectedReport));
  };
  const submit = (event) => {
    event.preventDefault();
    const options = { forceFormData: true, preserveScroll: true, onSuccess: closeAll };
    if (modal === 'edit') {
      form.transform((data) => ({ ...data, _method: 'put' }));
      form.post(submissionRoutes.update(selectedReport.id), options);
      return;
    }

    form.transform((data) => data);
    form.post(submissionRoutes.store, options);
  };
  const applyFilters = (changes) => router.get(submissionRoutes.index, { [protectedAreaFilter]: filters?.[protectedAreaFilter] || '', [semesterFilter]: filters?.[semesterFilter] || '', ...(filterPrefix ? { tracker: 1 } : {}), ...changes }, { preserveState: true, preserveScroll: true, replace: true });
  const requestDelete = (report) => setDeleteTarget(report);
  const confirmDelete = () => {
    if (!deleteTarget || !canDelete || deleteProcessing) return;
    setDeleteProcessing(true);
    router.delete(submissionRoutes.destroy(deleteTarget.id), {
      preserveScroll: true,
      onSuccess: () => {setDeleteTarget(null);closeAll();},
      onFinish: () => setDeleteProcessing(false)
    });
  };

  const label = 'block text-xs font-semibold text-gray-700 dark:text-gray-300';
  const error = (name) => form.errors[name] && <span className="mt-1 block text-xs text-red-500">{form.errors[name]}</span>;
  const change = (name) => (event) => form.setData(name, event.target.value);
  const calculated = selectedReport ? [['Deadline for Submission to PENRO', selectedReport.deadline_submission], ['Number of Days Complied', selectedReport.number_days_complied], ['Timeliness', selectedReport.timeliness], ['Status of Submission', selectedReport.submission_status], ['Total Number of Days Delayed at PENRO', selectedReport.total_days_delayed_penro]].filter(([, value]) => value !== null && value !== undefined && value !== '') : [];

  const columns = [
  {
    key: 'protected_area',
    label: 'Name of PA',
    render: (row) => {
      const protectedArea = protectedAreaTableLabel(row.protected_area);
      return <Tooltip content={protectedArea.fullName}><span className="block max-w-32 truncate font-semibold text-gray-900 dark:text-white">{protectedArea.label}</span></Tooltip>;
    }
  },
  { key: 'activity_name', label: 'Name of Activity', render: (row) => <span className="block min-w-40 max-w-72 whitespace-normal leading-5">{display(row.activity_name)}</span> },
  { key: 'date_conducted', label: 'Date Conducted', render: (row) => <span className="block min-w-32 max-w-56 whitespace-normal leading-5">{display(row.date_conducted)}</span> },
  { key: 'document_type', label: 'Type of Report', render: (row) => display(row.document_type) },
  { key: 'semester', label: 'Semester', render: (row) => display(row.semester) },
  { key: 'date_accomplished', label: 'Date Accomplished', render: (row) => display(dateValue(row.date_accomplished)) },
  { key: 'timeliness', label: 'Timeliness', render: (row) => <Badge value={row.timeliness} /> },
  { key: 'submission_status', label: 'Status of Submission', render: (row) => <span className="block max-w-52 whitespace-normal leading-5"><Badge value={row.submission_status} /></span> }];


  const pagination = submissions?.links?.length > 3 ? <div className="flex flex-wrap gap-1">{submissions.links.map((link, index) => <button key={index} type="button" disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })} className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${link.active ? 'bg-green-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-green-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'} disabled:cursor-not-allowed disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div> : null;
  const detailsMov = currentMov(selectedReport);

  return <div className="space-y-4">
        <div className="flex flex-col gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-end sm:justify-between">
            <div className="flex flex-wrap gap-3"><div className="text-xs font-bold text-gray-600 dark:text-gray-300"><FloatingSelect id="reportsubmissiontracker-semester" label="Semester" value={filters?.[semesterFilter] || ''} onChange={(event) => applyFilters({ [semesterFilter]: event.target.value, [pageFilter]: 1 })}><option value="">All Semesters</option><option>1st Semester</option><option>2nd Semester</option></FloatingSelect></div><div className="text-xs font-bold text-gray-600 dark:text-gray-300"><FloatingSelect id="reportsubmissiontracker-protected-area" label="Protected Area" value={filters?.[protectedAreaFilter] || ''} onChange={(event) => applyFilters({ [protectedAreaFilter]: event.target.value, [pageFilter]: 1 })}><option value="">All Protected Areas</option>{protectedAreas.map((pa) => <option key={pa.id} value={pa.id}>{pa.name}</option>)}</FloatingSelect></div></div>
            {canCreate && <button type="button" onClick={openCreate} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800">+ Add Report</button>}
        </div>

        <CrudTable title={`${moduleLabel} Report Submission Tracker`} subtitle={`${submissions?.total ?? rows.length} report submission${(submissions?.total ?? rows.length) === 1 ? '' : 's'}`} helperText="Click any row to view full details" caption={`${moduleLabel} report submission tracker`} columns={columns} rows={rows} rowKey="id" onRowClick={openDetails} emptyTitle="No report submissions found" emptyDescription={`No ${moduleLabel} report submissions match the selected filters.`} pagination={pagination} />

        <CrudDetailsModal open={modal === 'details' && Boolean(selectedReport)} title={`${moduleLabel} Report Submission Full Details`} subtitle={selectedReport ? `${selectedReport.protected_area?.name || 'No protected area'} · ${selectedReport.semester || 'No reporting period'}` : ''} onClose={closeAll} canEdit={canUpdate} onEdit={() => openEdit(selectedReport)} editLabel="Edit This Submission" summary={selectedReport && <CrudSummaryGrid items={[
    { label: 'Reporting Period', value: selectedReport.semester || '—' },
    { label: 'Report Status', render: () => <Badge value={selectedReport.submission_status} /> },
    { label: 'Deadline', value: selectedReport.deadline_submission || '—' },
    { label: 'Timeliness Rating', render: () => <Badge value={selectedReport.timeliness} /> }]
    } />} attachments={selectedReport && <FilePreviewPanel file={detailsMov} title="MOV / Attachment" heightClass="h-[480px]" />}>
            {selectedReport && <div className="space-y-6">
                <CrudSection title="Report Information"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"><Detail label="Target Office">{display(selectedReport.target_office)}</Detail><Detail label="Protected Area">{display(selectedReport.protected_area?.name)}</Detail><Detail label="Name of Activity">{display(selectedReport.activity_name)}</Detail><Detail label="Type of Document">{display(selectedReport.document_type)}</Detail><Detail label="Semester">{display(selectedReport.semester)}</Detail><Detail label="Date Conducted">{display(selectedReport.date_conducted)}</Detail></div></CrudSection>
                <CrudSection title="Submission / Compliance Details"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"><Detail label="Date Accomplished">{display(dateValue(selectedReport.date_accomplished))}</Detail><Detail label="Deadline for Submission to PENRO">{display(selectedReport.deadline_submission)}</Detail><Detail label="Date Report Released by CENRO Records">{display(dateValue(selectedReport.date_report_released_cenro))}</Detail><Detail label="Date Received by PENRO Records">{display(dateValue(selectedReport.date_received_penro))}</Detail><Detail label="Date Endorsed to Regional Office">{display(dateValue(selectedReport.date_endorsed_regional))}</Detail><Detail label="Total Number of Days Delayed at PENRO">{display(selectedReport.total_days_delayed_penro)}</Detail></div></CrudSection>
                <CrudSection title="Timeliness"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-3"><Detail label="Number of Days Complied">{display(selectedReport.number_days_complied)}</Detail><Detail label="Timeliness Rating"><Badge value={selectedReport.timeliness} /></Detail><Detail label="Submission Status"><Badge value={selectedReport.submission_status} /></Detail></div></CrudSection>
                <CrudSection title="Remarks"><p className="whitespace-pre-wrap text-xs text-gray-800 dark:text-gray-200">{selectedReport.remarks || 'None.'}</p></CrudSection>
            </div>}
        </CrudDetailsModal>

        <CrudFormModal open={modal === 'create' || modal === 'edit'} mode={modal === 'edit' ? 'edit' : 'create'} icon="📋" title={modal === 'edit' ? `Edit ${moduleLabel} Report Submission` : `Add ${moduleLabel} Report Submission`} subtitle={modal === 'edit' ? 'Update report details and review the MOV side-by-side.' : 'Record report compliance details and supporting MOV.'} onClose={backFromForm} onSubmit={submit} processing={form.processing} errors={form.errors} canDelete={modal === 'edit' && canDelete} onDelete={() => requestDelete(selectedReport)} saveLabel={modal === 'edit' ? 'Save Changes' : 'Save Report'} preview={<FilePreviewPanel file={preview} title="Live Document Preview" />}>
            <CrudSection title="General / Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className={label}><FloatingInput id="reportsubmissiontracker-target-office" label="Target Office" value={form.data.target_office} onChange={change('target_office')} />{error('target_office')}</div>
                <div className={label}><FloatingSelect id="reportsubmissiontracker-name-of-pa" label="Name of PA" value={form.data.protected_area_id} onChange={change('protected_area_id')}><option value="">Select Protected Area</option>{protectedAreas.map((pa) => <option key={pa.id} value={pa.id}>{pa.name}</option>)}</FloatingSelect>{error('protected_area_id')}</div>
                <div className={label}><FloatingInput id="reportsubmissiontracker-name-of-activity" label="Name of Activity" value={form.data.activity_name} onChange={change('activity_name')} />{error('activity_name')}</div>
                <div className={label}><FloatingSelect id="reportsubmissiontracker-type-of-document" label="Type of Document" value={form.data.document_type} onChange={change('document_type')}><option value="">Select Type of Document</option>{form.data.document_type && !['Final Report', 'Progress Report'].includes(form.data.document_type) && <option value={form.data.document_type}>{form.data.document_type} (Legacy)</option>}<option>Final Report</option><option>Progress Report</option></FloatingSelect>{error('document_type')}</div>
                <div className={label}><FloatingSelect id="reportsubmissiontracker-semester" label="Semester" value={form.data.semester} onChange={change('semester')}><option value="">Select Semester</option><option>1st Semester</option><option>2nd Semester</option></FloatingSelect>{error('semester')}</div>
                <div className={label}><FloatingInput id="reportsubmissiontracker-date-conducted" label="Date Conducted" value={form.data.date_conducted} onChange={change('date_conducted')} placeholder="Enter date or coverage period" />{error('date_conducted')}</div>
            </div></CrudSection>
            <CrudSection title="Submission Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['date_accomplished', 'Date Accomplished'], ['date_report_released_cenro', 'Date Report Released by CENRO Records'], ['date_received_penro', 'Date Received by PENRO Records'], ['date_endorsed_regional', 'Date Endorsed to Regional Office']].map(([name, text]) => <div key={name} className={label}><FloatingInput id={`bms-report-${name}`} label={text} type="date" value={form.data[name]} onChange={change(name)} />{error(name)}</div>)}</div>{calculated.length > 0 && <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">{calculated.map(([text, value]) => <div key={text} className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900"><p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-gray-500">{text}</p><Badge value={value} /></div>)}</div>}</CrudSection>
            <CrudSection title="Attachment / MOV & Remarks"><div className="space-y-5"><FileAttachmentPanel id="bms-report-mov" label="MOV Attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" acceptedTypesHint="PDF, JPG, PNG, DOC, or DOCX" maxSizeHint="Maximum 10 MB" existingFiles={selectedReport?.mov_url && !form.data.delete_mov ? [currentMov(selectedReport)] : []} selectedFiles={form.data.mov ? [form.data.mov] : []} activeFile={preview} onSelectFile={setPreview} onChange={selectMov} onRemoveExisting={() => {form.setData('delete_mov', true);setPreview(null);}} error={form.errors.mov} disabled={form.processing} canManage={modal === 'create' ? canCreate : canUpdate} /><div className={label}><FloatingTextarea id="reportsubmissiontracker-remarks" label="Remarks" rows="4" value={form.data.remarks} onChange={change('remarks')} />{error('remarks')}</div></div></CrudSection>
        </CrudFormModal>

        <ConfirmDialog open={Boolean(deleteTarget) && canDelete} variant="danger" title="Delete Report Submission?" message={`Delete the report submission for “${deleteTarget?.activity_name || 'this activity'}”? This cannot be undone.`} confirmLabel="Delete Record" onConfirm={confirmDelete} onCancel={() => setDeleteTarget(null)} processing={deleteProcessing} />
    </div>;
}
