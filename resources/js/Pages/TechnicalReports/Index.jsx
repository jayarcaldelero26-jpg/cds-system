import { FloatingSelect } from "@/Components/Form";import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import CrudTable from '@/Components/Crud/CrudTable';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import { useReportDetails } from '@/Components/Crud/ReportDetailsContext';
import PageHeader from '@/Components/PageHeader';
import { formatReportValue } from '@/Utils/dateFormatters';
import TimelinessBadge, { isTimelinessValue } from '@/Components/TimelinessBadge';

const badgeClass = (value) => ({ 'Pending Submission by CENRO': 'bg-blue-600 text-white', 'Pending Receipt by PENRO': 'bg-blue-600 text-white', 'Pending Regional Endorsement': 'bg-blue-600 text-white', 'Completed': 'bg-green-600 text-white', 'Ongoing Preparation at CENRO Level': 'bg-blue-600 text-white', 'Report Not Yet Submitted': 'bg-red-600 text-white', 'Report Submitted': 'bg-green-600 text-white', 'No Activity Conducted': 'bg-gray-500 text-white', 'No Data': 'bg-gray-500 text-white' })[value] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
const show = (value) => formatReportValue(value);
const Badge = ({ value }) => isTimelinessValue(value) ? <TimelinessBadge value={value} /> : <span className={`inline-flex max-w-52 whitespace-normal rounded-full px-2.5 py-1 text-xs font-bold ${badgeClass(value)}`}>{show(value)}</span>;
const Detail = ({ label, children }) => {
  const reportDetails = useReportDetails();
  const inSummary = /^(reporting period|semester|quarter|deadline|deadline for submission to penro|timeliness(?: rating)?|submission status|status of submission|report status|number of days complied|days complied)$/i.test(label);
  if (reportDetails && inSummary) return null;
  return <div><span className="block text-xs text-gray-500">{label}:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{children}</span></div>;
};

export default function Index({ technicalReports, filters = {}, protectedAreas }) {
  const { auth = {} } = usePage().props;
  const [selected, setSelected] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const visit = (changes) => router.get(route('technical-reports.index'), { ...filters, search: undefined, target_office: undefined, year: undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
  const remove = () => router.delete(route('technical-reports.destroy', deleting.id), { preserveScroll: true, onSuccess: () => {setDeleting(null);setSelected(null);} });
  const columns = [
  { key: 'target_office', label: 'Target Office', render: (row) => show(row.target_office) },
  { key: 'protected_area_name', label: 'Name of PA', render: (row) => <span className="font-semibold text-gray-900 dark:text-white">{show(row.protected_area_name)}</span> },
  { key: 'activity_name', label: 'Name of Activity', render: (row) => <span className="block min-w-40 max-w-72 whitespace-normal">{show(row.activity_name)}</span> },
  { key: 'report_type', label: 'Type of Document', render: (row) => show(row.report_type) },
  { key: 'semester', label: 'Semester', render: (row) => show(row.semester) },
  { key: 'date_conducted', label: 'Date Conducted', render: (row) => show(row.date_conducted) },
  { key: 'date_accomplished', label: 'Date Accomplished', render: (row) => show(row.date_accomplished) },
  { key: 'deadline_submission', label: 'Deadline', render: (row) => show(row.deadline_submission) },
  { key: 'date_received_penro', label: 'Received by PENRO', render: (row) => show(row.date_received_penro) },
  { key: 'number_days_complied', label: 'Days Complied', render: (row) => show(row.number_days_complied) },
  { key: 'timeliness', label: 'Timeliness', render: (row) => <Badge value={row.timeliness} /> },
  { key: 'submission_status', label: 'Status of Submission', render: (row) => <Badge value={row.submission_status} /> }];

  const pagination = technicalReports.links?.length > 3 ? <div className="flex flex-wrap gap-1">{technicalReports.links.map((link, index) => <button key={index} type="button" disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })} className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${link.active ? 'bg-green-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-green-50 dark:bg-gray-700 dark:text-gray-200'} disabled:cursor-not-allowed disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div> : null;
  const filtersUi = <div className="grid gap-3 sm:grid-cols-2"><Filter label="Reporting Period" value={filters.semester || ''} onChange={(value) => visit({ semester: value })} options={['1st Semester', '2nd Semester'].map((semester) => [semester, semester])} /><Filter label="Protected Area" value={filters.protected_area_id || ''} onChange={(value) => visit({ protected_area_id: value })} options={protectedAreas.map((area) => [area.id, area.name])} /></div>;

  return <AuthenticatedLayout title="General / Other Reports">
        <div className="mt-6 space-y-4"><div className="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-end sm:justify-between"><div className="min-w-0 flex-1">{filtersUi}</div>{auth.canCreateTechnicalReports && <Link href={route('technical-reports.create')} className="shrink-0 rounded-xl bg-green-700 px-4 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800">+ Add Report</Link>}</div><CrudTable compactEmpty={true} title={technicalReports.data.length > 0 ? "General / Other Report Submission Tracker" : undefined} subtitle={technicalReports.data.length > 0 ? `${technicalReports.total ?? technicalReports.data.length} report submissions` : undefined} helperText={technicalReports.data.length > 0 ? 'Click any row to view full details' : undefined} caption="General and other report submission tracker" columns={columns} rows={technicalReports.data} rowKey="id" onRowClick={setSelected} emptyTitle="No report submissions found" emptyDescription="No general reports match the selected filters." pagination={technicalReports.data.length > 0 ? pagination : null} tableClassName="min-w-[1800px]" /></div>
        <CrudDetailsModal open={Boolean(selected)} title="General / Other Report Submission Full Details" subtitle={selected ? `${selected.protected_area_name || 'No protected area'} · ${selected.activity_name || 'No activity'}` : ''} onClose={() => setSelected(null)} canEdit={Boolean(auth.canUpdateTechnicalReports)} canDelete={Boolean(auth.canDeleteTechnicalReports)} onEdit={() => router.visit(route('technical-reports.edit', selected.id))} onDelete={() => setDeleting(selected)} editLabel="Edit This Submission" deleteLabel="Delete Submission" summary={selected && <CrudSummaryGrid items={[{ label: 'Report Status', render: () => <Badge value={selected.submission_status} /> }, { label: 'Deadline', value: show(selected.deadline_submission) }, { label: 'Timeliness Rating', render: () => <Badge value={selected.timeliness} /> }, { label: 'PENRO Delay', value: show(selected.total_days_delayed_penro) }]} />} attachments={selected && <FilePreviewPanel file={selected.attachment} title="MOV / Attachment" heightClass="h-[480px]" />}>
            {selected && <div className="space-y-6"><CrudSection title="Report Information"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"><Detail label="Target Office">{show(selected.target_office)}</Detail><Detail label="Protected Area">{show(selected.protected_area_name)}</Detail><Detail label="Name of Activity">{show(selected.activity_name)}</Detail><Detail label="Type of Document">{show(selected.report_type)}</Detail><Detail label="Date Conducted">{show(selected.date_conducted)}</Detail><Detail label="Date Accomplished">{show(selected.date_accomplished)}</Detail></div></CrudSection><CrudSection title="Submission / Compliance Details"><div className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"><Detail label="Deadline for Submission to PENRO">{show(selected.deadline_submission)}</Detail><Detail label="Date Report Released by CENRO Records">{show(selected.date_report_released_cenro)}</Detail><Detail label="Date Received by PENRO Records">{show(selected.date_received_penro)}</Detail><Detail label="Date Endorsed to Regional Office">{show(selected.date_endorsed_regional)}</Detail><Detail label="Number of Days Complied">{show(selected.number_days_complied)}</Detail><Detail label="Total Number of Days Delayed at PENRO">{show(selected.total_days_delayed_penro)}</Detail></div></CrudSection><CrudSection title="Remarks"><p className="whitespace-pre-wrap text-xs text-gray-800 dark:text-gray-200">{selected.remarks || 'None.'}</p></CrudSection></div>}
        </CrudDetailsModal>
        <ConfirmDialog open={Boolean(deleting)} variant="danger" title="Delete Report Submission?" message={`Delete “${deleting?.activity_name || 'this report'}”? This cannot be undone.`} confirmLabel="Delete Record" onCancel={() => setDeleting(null)} onConfirm={remove} />
    </AuthenticatedLayout>;
}

function Filter({ label, value, onChange, options }) {return <div><FloatingSelect id={`technical-filter-${label.toLowerCase().replace(/\s+/g, '-')}`} label={label} size="sm" value={value} onChange={(event) => onChange(event.target.value)}><option value="">All</option>{options.map(([key, text]) => <option key={key} value={key}>{text}</option>)}</FloatingSelect></div>;}
