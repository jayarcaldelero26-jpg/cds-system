import { FloatingInput, FloatingSelect, FloatingTextarea } from "@/Components/Form";import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import CrudSection from '../../Components/Crud/CrudSection';
import PageHeader from '../../Components/PageHeader';
import { formatReportDate } from '../../Utils/dateFormatters';
import ManagementPlanAttachments, { useManagementPlanAttachments } from './Attachments';

const labelClass = 'block text-xs font-semibold text-gray-700 dark:text-gray-300';
const badgeClass = (value) => ({ Outstanding: 'bg-emerald-500 text-white', 'Very Satisfactory': 'bg-green-600 text-white', Satisfactory: 'bg-amber-400 text-amber-950', Unsatisfactory: 'bg-orange-500 text-white', Poor: 'bg-red-600 text-white', 'Report Submitted': 'bg-emerald-600 text-white', 'Report Not Yet Submitted': 'bg-red-600 text-white', 'Ongoing Preparation at CENRO Level': 'bg-blue-600 text-white', 'Pending Submission by CENRO': 'bg-blue-600 text-white' })[value] || 'bg-gray-500 text-white';
const Badge = ({ value }) => <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${badgeClass(value)}`}>{value || 'No Data'}</span>;

export default function Form({ title, managementPlan, managementPlanType, protectedAreas = [] }) {
  const isEdit = Boolean(managementPlan);
  const form = useForm({
    target_office: managementPlan?.target_office || '', protected_area_id: managementPlan?.protected_area_id || '', activity_name: managementPlan?.activity_name || '', document_type: managementPlan?.document_type || '', semester: managementPlan?.semester || '', date_conducted: managementPlan?.date_conducted || '', date_accomplished: managementPlan?.date_accomplished || '', date_report_released_cenro: managementPlan?.date_report_released_cenro || '', date_received_penro: managementPlan?.date_received_penro || '', date_endorsed_regional: managementPlan?.date_endorsed_regional || '', remarks: managementPlan?.remarks || '', attachments: [], removed_attachments: []
  });
  const attachments = useManagementPlanAttachments(managementPlan?.attachments || [], (newFiles, removedPaths) => form.setData((data) => ({ ...data, attachments: newFiles, removed_attachments: removedPaths })));
  const error = (name) => form.errors[name] && <span className="mt-1 block text-xs text-red-500">{form.errors[name]}</span>;
  const change = (name) => (event) => form.setData(name, event.target.value);
  const calculated = managementPlan ? [['Deadline for Submission to PENRO', formatReportDate(managementPlan.deadline_submission)], ['Number of Days Complied', managementPlan.number_days_complied], ['Timeliness', managementPlan.timeliness], ['Status of Submission', managementPlan.submission_status], ['Total Number of Days Delayed at PENRO', managementPlan.total_days_delayed_penro]] : [];
  const trackerRoute = route('management-plans.types.show', managementPlanType.slug);
  const submit = (event) => {event.preventDefault();const options = { forceFormData: true, preserveScroll: true };if (isEdit) {form.transform((data) => ({ ...data, _method: 'patch' }));form.post(route('management-plans.types.reports.update', [managementPlanType.slug, managementPlan.id]), options);} else {form.transform((data) => data);form.post(route('management-plans.types.reports.store', managementPlanType.slug), options);}};

  return <AuthenticatedLayout title={title || (isEdit ? 'Edit Management Plan Submission' : 'Add Management Plan Submission')}>
        <PageHeader title={isEdit ? `Edit ${managementPlanType.name} Report Submission` : `Add ${managementPlanType.name} Report Submission`} description={isEdit ? 'Update submission details and review supporting documents side-by-side.' : `Record a report submission for ${managementPlanType.name}.`} actions={<Link href={trackerRoute} className="rounded-xl bg-white/10 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">← Back to {managementPlanType.name}</Link>} />
        <div className="mt-6 grid items-start gap-6 xl:grid-cols-12">
            <Card className="xl:col-span-7"><form onSubmit={submit} className="space-y-5">
                <div className="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-xs text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300"><span className="font-semibold">Plan:</span> <span className="font-bold">{managementPlanType.name}</span></div>
                <CrudSection title="General / Report Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className={labelClass}><FloatingInput id="form-target-office" label="Target Office" value={form.data.target_office} onChange={change('target_office')} />{error('target_office')}</div>
                    <div className={labelClass}><FloatingSelect id="form-name-of-pa" label="Name of PA" value={form.data.protected_area_id} onChange={change('protected_area_id')}><option value="">Select Protected Area</option>{protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect>{error('protected_area_id')}</div>
                    <div className={labelClass}><FloatingInput id="form-name-of-activity" label="Name of Activity" value={form.data.activity_name} onChange={change('activity_name')} />{error('activity_name')}</div>
                    <div className={labelClass}><FloatingSelect id="form-type-of-document" label="Type of Document" value={form.data.document_type} onChange={change('document_type')}><option value="">Select Type of Document</option><option value="Final Report">Final Report</option><option value="Progress Report">Progress Report</option></FloatingSelect>{error('document_type')}</div>
                    <div className={labelClass}><FloatingSelect id="form-semester" label="Semester" value={form.data.semester} onChange={change('semester')}><option value="">Select Semester</option><option>1st Semester</option><option>2nd Semester</option></FloatingSelect>{error('semester')}</div>
                    <div className={labelClass}><FloatingInput id="form-date-conducted" label="Date Conducted" value={form.data.date_conducted} onChange={change('date_conducted')} placeholder="e.g. March 10–12, 2026" />{error('date_conducted')}</div>
                </div></CrudSection>
                <CrudSection title="Submission Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['date_accomplished', 'Date Accomplished'], ['date_report_released_cenro', 'Date Report Released by CENRO Records'], ['date_received_penro', 'Date Received by PENRO Records'], ['date_endorsed_regional', 'Date Endorsed to Regional Office']].map(([name, text]) => <div key={name} className={labelClass}><FloatingInput id={`management-plan-${name}`} label={text} type="date" value={form.data[name]} onChange={change(name)} />{error(name)}</div>)}</div>{calculated.length > 0 && <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">{calculated.map(([text, value]) => <div key={text} className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900"><p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-gray-500">{text}</p><Badge value={value} /></div>)}</div>}</CrudSection>
                <CrudSection title="Attachments & Remarks"><div className="space-y-5"><ManagementPlanAttachments manager={attachments} error={form.errors.attachments || form.errors['attachments.0'] || form.errors.removed_attachments} previewClassName="xl:hidden" /><div className={labelClass}><FloatingTextarea id="form-remarks" label="Remarks" rows="4" value={form.data.remarks} onChange={change('remarks')} />{error('remarks')}</div></div></CrudSection>
                <div className="sticky bottom-0 flex justify-end gap-3 border-t border-gray-200 bg-white py-4 dark:border-gray-700 dark:bg-gray-900"><Link href={trackerRoute} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</Link><button type="submit" disabled={form.processing} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-50">{form.processing ? isEdit ? 'Updating…' : 'Saving…' : isEdit ? 'Update Report' : 'Save Report'}</button></div>
            </form></Card>
            <div className="sticky top-6 hidden xl:col-span-5 xl:block"><ManagementPlanAttachments manager={attachments} canRemoveExisting={false} previewOnly /></div>
        </div>
    </AuthenticatedLayout>;
}
