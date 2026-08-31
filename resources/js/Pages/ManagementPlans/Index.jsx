import { FloatingInput, FloatingTextarea, FloatingSelect } from "@/Components/Form";import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import CrudTable from '@/Components/Crud/CrudTable';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import { useReportDetails } from '@/Components/Crud/ReportDetailsContext';
import PageHeader from '@/Components/PageHeader';
import { formatReportDate } from '@/Utils/dateFormatters';
import PlanInformation from './PlanInformation';
import TimelinessBadge, { isTimelinessValue } from '@/Components/TimelinessBadge';

const show = (value) => value === null || value === undefined || value === '' ? '—' : value;
const badgeClass = (value) => ({ 'Report Submitted': 'bg-emerald-600 text-white', 'Completed': 'bg-emerald-600 text-white', 'Report Not Yet Submitted': 'bg-red-600 text-white', 'Ongoing Preparation at CENRO Level': 'bg-blue-600 text-white', 'Pending Submission by CENRO': 'bg-blue-600 text-white', 'Pending Receipt by PENRO': 'bg-blue-600 text-white', 'Pending Regional Endorsement': 'bg-blue-600 text-white' })[value] || 'bg-gray-500 text-white';
const Badge = ({ value }) => isTimelinessValue(value) ? <TimelinessBadge value={value} /> : <span className={`inline-flex max-w-52 whitespace-normal rounded-full px-2.5 py-1 text-xs font-bold leading-5 ${badgeClass(value)}`}>{show(value)}</span>;
const Detail = ({ label, children }) => {
  const reportDetails = useReportDetails();
  const inSummary = /^(plan|plan type|reporting period|semester|quarter|deadline|deadline for submission to penro|timeliness(?: rating)?|submission status|status of submission|report status|number of days complied|days complied)$/i.test(label);
  if (reportDetails && inSummary) return null;
  return <div><span className="block text-xs text-gray-500">{label}:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{children}</span></div>;
};
const actionClass = 'rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 disabled:cursor-not-allowed disabled:opacity-50';

export default function Index(props) {
  const { auth = {} } = usePage().props;
  const { url } = usePage();
  const canCreate = Boolean(auth.canCreateManagementPlans);
  const [creatingType, setCreatingType] = useState(url.includes('create=1'));
  const typeForm = useForm({ name: '', description: '' });
  const selectedType = props.selectedPlanType;
  useEffect(() => {if (url.includes('create=1')) setCreatingType(true);}, [url]);

  if (selectedType) {
    return <AuthenticatedLayout title={`${selectedType.name} Management Plan Workspace`}>
            <PageHeader title={`${selectedType.name} Report Submission Tracker`} description={selectedType.description || `Plan information and report submissions for ${selectedType.name}.`} actions={<div className="flex flex-wrap gap-2"><Link href={route('management-plans.index')} className={`${actionClass} bg-white/10 text-white hover:bg-white/20`}>Back to All Plans</Link></div>} />
            <div className="mt-5"><PlanInformation selectedPlanType={selectedType} planProfile={props.planProfile} protectedAreas={props.protectedAreas || []} approvalStatuses={props.approvalStatuses || []} documentCategories={props.documentCategories || {}} /></div>
            {canCreate && <div className="mt-4 flex justify-end rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><Link href={route('management-plans.types.reports.create', selectedType.slug)} className={`${actionClass} bg-green-700 text-white shadow-sm hover:bg-green-800`}>+ Add Report</Link></div>}
            <ReportTracker {...props} canCreate={canCreate} />
        </AuthenticatedLayout>;
  }

  const submitType = (event) => {event.preventDefault();typeForm.post(route('management-plans.types.store'), { preserveScroll: true, onSuccess: () => setCreatingType(false) });};
  const planTypes = Array.isArray(props.planTypes) ? props.planTypes : [];
  return <AuthenticatedLayout title="Management Plans">
        <PageHeader title="Management Plans" description="Create and open plan-specific management plan workspaces." actions={canCreate && <button type="button" onClick={() => setCreatingType(true)} className={`${actionClass} bg-green-700 text-white shadow-md hover:bg-green-800`}>+ Create Plan</button>} />
        {planTypes.length ? <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{planTypes.map((type) => <Link key={type.id} href={route('management-plans.types.show', type.slug)} className="group rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-green-300 hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-green-800"><h2 className="text-base font-bold text-gray-900 group-hover:text-green-700 dark:text-white dark:group-hover:text-green-400">{type.name}</h2><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{type.description || 'Management plan workspace'}</p><div className="mt-4 flex flex-wrap gap-2">{type.has_profile ? <><span className="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">Approval: {type.approval_status}</span><span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">{type.completeness_completed} / {type.completeness_total} Complete</span></> : <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">Plan information not added</span>}<span className="rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-700 dark:bg-green-950 dark:text-green-300">{type.management_plans_count || 0} Report Submissions</span></div><p className="mt-5 text-xs font-semibold text-green-700 dark:text-green-400">Open →</p></Link>)}</div> : <div className="mt-6 rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900"><h2 className="text-base font-bold text-gray-900 dark:text-white">No management plans have been created yet.</h2><p className="mt-2 text-sm text-gray-500 dark:text-gray-400">{canCreate ? 'Create a plan to make its workspace available.' : 'No management plans are currently available.'}</p>{canCreate && <button type="button" onClick={() => setCreatingType(true)} className={`${actionClass} mt-5 bg-green-700 text-white shadow-md hover:bg-green-800`}>+ Create Plan</button>}</div>}
        <CrudFormModal open={creatingType && canCreate} icon="📁" title="Create Management Plan" subtitle="Create a plan that will appear in the Management Plan sidebar." onClose={() => {setCreatingType(false);typeForm.clearErrors();}} onSubmit={submitType} processing={typeForm.processing} errors={typeForm.errors} saveLabel="Create Plan" maxWidth="max-w-xl"><CrudSection title="Plan Information"><div className="space-y-4"><div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><FloatingInput id="index-plan-name" label="Plan Name" value={typeForm.data.name} onChange={(event) => typeForm.setData('name', event.target.value)} />{typeForm.errors.name && <span className="mt-1 block text-xs text-red-500">{typeForm.errors.name}</span>}</div><div className="block text-xs font-semibold text-gray-700 dark:text-gray-300"><FloatingTextarea id="index-description" label="Description" rows="4" value={typeForm.data.description} onChange={(event) => typeForm.setData('description', event.target.value)} />{typeForm.errors.description && <span className="mt-1 block text-xs text-red-500">{typeForm.errors.description}</span>}</div></div></CrudSection></CrudFormModal>
    </AuthenticatedLayout>;
}

function ReportTracker({ selectedPlanType, managementPlans = {}, filters = {}, protectedAreas = [], canCreate = false }) {
  const { auth = {} } = usePage().props;
  const canView = Boolean(auth.canViewManagementPlans);
  const canUpdate = Boolean(auth.canUpdateManagementPlans);
  const canDelete = Boolean(auth.canDeleteManagementPlans);
  const rows = Array.isArray(managementPlans.data) ? managementPlans.data : [];
  const links = Array.isArray(managementPlans.links) ? managementPlans.links : [];
  const [selected, setSelected] = useState(null);
  const [activeAttachment, setActiveAttachment] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [deleteProcessing, setDeleteProcessing] = useState(false);
  useEffect(() => {if (!selected) return;const refreshed = rows.find((row) => row.id === selected.id);if (refreshed) {setSelected(refreshed);setActiveAttachment((current) => refreshed.attachments?.find((file) => file.path === current?.path) || refreshed.attachments?.[0] || null);}}, [managementPlans.data]);
  const visit = (changes) => router.get(route('management-plans.types.show', selectedPlanType.slug), { ...filters, search: undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
  const openDetails = (report) => {setSelected(report);setActiveAttachment(report.attachments?.[0] || null);};
  const closeDetails = () => {setSelected(null);setActiveAttachment(null);};
  const remove = () => {if (!deleting || deleteProcessing) return;router.delete(route('management-plans.types.reports.destroy', [selectedPlanType.slug, deleting.id]), { preserveScroll: true, onStart: () => setDeleteProcessing(true), onSuccess: () => {setDeleting(null);closeDetails();}, onFinish: () => setDeleteProcessing(false) });};
  const columns = [
  { key: 'protected_area_name', label: 'Name of PA', tooltip: (row) => row.protected_area_name, render: (row) => <span className="font-semibold text-gray-900 dark:text-white">{show(row.protected_area_name)}</span> },
  { key: 'activity_name', label: 'Name of Activity', tooltip: (row) => row.activity_name || row.title, render: (row) => show(row.activity_name || row.title) },
  { key: 'date_conducted', label: 'Date Conducted', render: (row) => show(row.date_conducted) },
  { key: 'document_type', label: 'Type of Report', render: (row) => show(row.document_type) },
  { key: 'semester', label: 'Semester', render: (row) => show(row.semester) },
  { key: 'date_accomplished', label: 'Date Accomplished', render: (row) => formatReportDate(row.date_accomplished) },
  { key: 'timeliness', label: 'Timeliness', render: (row) => <Badge value={row.timeliness} /> },
  { key: 'submission_status', label: 'Status of Submission', render: (row) => <Badge value={row.submission_status} /> }];

  const pagination = links.length > 3 ? <div className="flex flex-wrap gap-1">{links.map((link, index) => <button key={`${link.label}-${index}`} type="button" disabled={!link.url || link.active} onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })} className={`rounded-lg px-3 py-1.5 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${link.active ? 'bg-green-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-green-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'} disabled:cursor-not-allowed disabled:opacity-50`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div> : null;
  const filtersUi = <div className="grid gap-3 sm:grid-cols-2"><Filter label="Reporting Period" value={filters.semester || ''} onChange={(value) => visit({ semester: value || undefined, page: 1 })} options={[['1st Semester', '1st Semester'], ['2nd Semester', '2nd Semester']]} /><Filter label="Protected Area" value={filters.protected_area_id || ''} onChange={(value) => visit({ protected_area_id: value || undefined, page: 1 })} options={protectedAreas.map((area) => [area.id, area.name])} /></div>;
  const attachmentPanel = selected ? <div className="space-y-3">{selected.attachments?.length ? <div className="flex flex-wrap gap-2">{selected.attachments.map((file) => <button key={file.path} type="button" onClick={() => setActiveAttachment(file)} className={`rounded-lg border px-3 py-2 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${activeAttachment?.path === file.path ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'}`}>{file.name}</button>)}</div> : <p className="text-xs text-gray-500">No attachments.</p>}<FilePreviewPanel file={activeAttachment} title="Management Plan Attachment" heightClass="h-[480px]" /></div> : null;

  return <div className="mt-4"><CrudTable compactEmpty={true} title={rows.length > 0 ? `${selectedPlanType.name} Report Submission Tracker` : undefined} subtitle={rows.length > 0 ? `${managementPlans.total ?? rows.length} report submissions` : undefined} helperText={rows.length > 0 ? 'Click any row to view full details' : undefined} caption="Report submission tracker" columns={columns} rows={rows} rowKey="id" onRowClick={openDetails} filters={filtersUi} pagination={rows.length > 0 ? pagination : null} emptyTitle="No report submissions found" emptyDescription={`No report submissions have been added to ${selectedPlanType.name}.`} tableClassName="min-w-[1100px]" /><CrudDetailsModal open={canView && Boolean(selected)} icon="📋" title={`${selectedPlanType.name} Report Submission Details`} subtitle={selected?.protected_area_name || ''} onClose={closeDetails} canEdit={canUpdate} canDelete={canDelete} onEdit={() => selected && router.visit(route('management-plans.types.reports.edit', [selectedPlanType.slug, selected.id]))} onDelete={() => setDeleting(selected)} editLabel="Edit This Report" deleteLabel="Delete Report" summary={selected && <CrudSummaryGrid items={[{ label: 'Plan', value: selectedPlanType.name }, { label: 'Semester', value: show(selected.semester) }, { label: 'Timeliness', render: () => <Badge value={selected.timeliness} /> }, { label: 'Submission Status', render: () => <Badge value={selected.submission_status} /> }]} />} attachments={attachmentPanel}><ReportDetails report={selected} /></CrudDetailsModal><ConfirmDialog open={canDelete && Boolean(deleting)} variant="danger" title="Delete Report Submission?" message={`Delete “${deleting?.activity_name || 'this report'}”?`} confirmLabel="Delete Report" processing={deleteProcessing} onConfirm={remove} onCancel={() => !deleteProcessing && setDeleting(null)} /></div>;
}

function ReportDetails({ report }) {
  if (!report) return null;
  return <div className="space-y-6"><CrudSection title="General / Report Information"><div className="grid gap-4 text-xs sm:grid-cols-2"><Detail label="Plan">{show(report.plan_type)}</Detail><Detail label="Target Office">{show(report.target_office)}</Detail><Detail label="Protected Area">{show(report.protected_area_name)}</Detail><Detail label="Name of Activity">{show(report.activity_name)}</Detail><Detail label="Type of Document">{show(report.document_type)}</Detail><Detail label="Semester">{show(report.semester)}</Detail><Detail label="Date Conducted">{show(report.date_conducted)}</Detail></div></CrudSection><CrudSection title="Submission Timeline"><div className="grid gap-4 text-xs sm:grid-cols-2"><Detail label="Date Accomplished">{formatReportDate(report.date_accomplished)}</Detail><Detail label="Deadline">{formatReportDate(report.deadline_submission)}</Detail><Detail label="Released by CENRO">{formatReportDate(report.date_report_released_cenro)}</Detail><Detail label="Received by PENRO">{formatReportDate(report.date_received_penro)}</Detail><Detail label="Days Complied">{show(report.number_days_complied)}</Detail><Detail label="Timeliness"><Badge value={report.timeliness} /></Detail><Detail label="Submission Status"><Badge value={report.submission_status} /></Detail><Detail label="Endorsed to Regional Office">{formatReportDate(report.date_endorsed_regional)}</Detail><Detail label="PENRO Delay">{show(report.total_days_delayed_penro)}</Detail></div></CrudSection><CrudSection title="Remarks"><p className="whitespace-pre-wrap text-xs text-gray-800 dark:text-gray-200">{report.remarks || 'None.'}</p></CrudSection></div>;
}

function Filter({ label, value, onChange, options = [] }) {return <div><FloatingSelect id={`management-plan-filter-${label.toLowerCase().replace(/\s+/g, '-')}`} label={label} size="sm" value={value} onChange={(event) => onChange(event.target.value)}><option value="">All</option>{options.map(([key, text]) => <option key={key} value={key}>{text}</option>)}</FloatingSelect></div>;}
