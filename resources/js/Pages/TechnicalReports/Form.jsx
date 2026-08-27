import { FileInput } from "@/Components/Crud/FileInput";import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import FilePreviewPanel from '../../Components/Crud/FilePreviewPanel';
import PageHeader from '../../Components/PageHeader';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '../../Components/Form';

const empty = { protected_area_id: '', target_office: '', activity_name: '', report_type: '', semester: '1st Semester', date_conducted: '', date_accomplished: '', date_report_released_cenro: '', date_received_penro: '', date_endorsed_regional: '', attachment: null, remove_attachment: false, remarks: '' };
const badgeClass = (value) => ({ Outstanding: 'bg-emerald-500 text-white', 'Very Satisfactory': 'bg-green-600 text-white', Satisfactory: 'bg-amber-400 text-amber-950', Unsatisfactory: 'bg-orange-500 text-white', Poor: 'bg-red-600 text-white', 'No Rating': 'bg-gray-500 text-white', 'Pending Submission by CENRO': 'bg-blue-600 text-white', 'Ongoing Preparation at CENRO Level': 'bg-blue-600 text-white', 'Report Not Yet Submitted': 'bg-red-600 text-white', 'Report Submitted': 'bg-green-600 text-white', 'No Activity Conducted': 'bg-gray-500 text-white', 'No Data': 'bg-gray-500 text-white' })[value] || 'bg-gray-100 text-gray-700';

export default function Form({ technicalReport, protectedAreas, reportTypes }) {
  const isEdit = Boolean(technicalReport);
  const form = useForm({ ...empty, ...(technicalReport || {}), attachment: null, remove_attachment: false });
  const existingFile = technicalReport?.attachment || null;
  const [preview, setPreview] = useState(existingFile);
  const objectUrlRef = useRef(null);

  useEffect(() => () => {if (objectUrlRef.current) URL.revokeObjectURL(objectUrlRef.current);}, []);

  const selectFile = (file) => {
    if (objectUrlRef.current) URL.revokeObjectURL(objectUrlRef.current);
    objectUrlRef.current = file ? URL.createObjectURL(file) : null;
    form.setData((data) => ({ ...data, attachment: file, remove_attachment: false }));
    setPreview(file ? { name: file.name, type: file.type, size: file.size, url: objectUrlRef.current } : existingFile);
  };
  const removeFile = () => {
    if (objectUrlRef.current) URL.revokeObjectURL(objectUrlRef.current);
    objectUrlRef.current = null;
    if (form.data.attachment) {
      form.setData((data) => ({ ...data, attachment: null, remove_attachment: false }));
      setPreview(existingFile);
    } else {
      form.setData((data) => ({ ...data, remove_attachment: Boolean(existingFile) }));
      setPreview(null);
    }
  };
  const submit = (event) => {
    event.preventDefault();
    if (isEdit) {
      form.transform((data) => ({ ...data, _method: 'patch' }));
      form.post(route('technical-reports.update', technicalReport.id), { forceFormData: true });
    } else {
      form.transform((data) => data);
      form.post(route('technical-reports.store'), { forceFormData: true });
    }
  };
  const field = (name, label, type = 'text') => <FloatingInput id={`technical-${name}`} label={label} type={type} value={form.data[name] || ''} onChange={(event) => form.setData(name, event.target.value)} error={form.errors[name]} />;
  const calculations = technicalReport ? [['Deadline', technicalReport.deadline_submission], ['Days Complied', technicalReport.number_days_complied], ['Timeliness', technicalReport.timeliness], ['Submission Status', technicalReport.submission_status], ['PENRO Delay', technicalReport.total_days_delayed_penro]] : [];

  return <AuthenticatedLayout title={isEdit ? 'Edit General Report' : 'Add General Report'}>
        <PageHeader title={isEdit ? 'Edit General / Other Report' : 'Add General / Other Report'} description="Track submission compliance using the 7-working-day General/Other Reports standard." actions={<Link href={route('technical-reports.index')} className="rounded-xl bg-white/10 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-white/20">← Back to tracker</Link>} />
        <div className="mt-6 grid items-start gap-6 xl:grid-cols-12">
            <Card className="xl:col-span-7"><form onSubmit={submit} className="space-y-7">
                <Section title="General Information & Report Details"><div className="grid gap-4 sm:grid-cols-2">
                    {field('target_office', 'Target Office')}
                    <FloatingSelect id="technical-pa" label="Protected Area" required value={form.data.protected_area_id} onChange={(event) => form.setData('protected_area_id', event.target.value)} error={form.errors.protected_area_id}><option value="">Select protected area</option>{protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect>
                    {field('activity_name', 'Name of Activity')}
                    <FloatingSelect id="technical-type" label="Type of Document" required value={form.data.report_type} onChange={(event) => form.setData('report_type', event.target.value)} error={form.errors.report_type}><option value="">Select Type of Document</option>{form.data.report_type && !reportTypes.includes(form.data.report_type) && <option value={form.data.report_type}>{form.data.report_type} (Legacy)</option>}{reportTypes.map((type) => <option key={type}>{type}</option>)}</FloatingSelect>
                    <FloatingSelect id="technical-semester" label="Semester" required value={form.data.semester || ''} onChange={(event) => form.setData('semester', event.target.value)} error={form.errors.semester}><option value="">Select Semester</option><option>1st Semester</option><option>2nd Semester</option></FloatingSelect>
                    <div className="sm:col-span-2">{field('date_conducted', 'Date Conducted / Coverage Period')}</div>
                </div></Section>
                <Section title="Submission Timeline"><div className="grid gap-4 sm:grid-cols-2">{field('date_accomplished', 'Date Accomplished', 'date')}{field('date_report_released_cenro', 'Date Report Released by CENRO Records', 'date')}{field('date_received_penro', 'Date Received by PENRO Records', 'date')}{field('date_endorsed_regional', 'Date Endorsed to Regional Office', 'date')}</div></Section>
                <Section title="Calculated Compliance"><div className="grid gap-3 sm:grid-cols-2">{calculations.length ? calculations.map(([label, value]) => <div key={label} className="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900"><p className="text-xs font-bold uppercase tracking-wide text-gray-500">{label}</p><span className={`mt-2 inline-flex rounded-full px-3 py-1 text-xs font-bold ${badgeClass(value)}`}>{value ?? '—'}</span></div>) : <p className="text-sm text-gray-500 sm:col-span-2">Compliance values are calculated by the server after the report is saved.</p>}</div></Section>
                <Section title="MOV & Remarks"><div className="space-y-4"><div className="block text-sm font-medium text-gray-700 dark:text-gray-300"><p className="mb-1 text-xs font-semibold text-amber-700 dark:text-amber-300">{(!isEdit || !existingFile) && 'An MOV / supporting document is required.'}</p><FileInput id="form-mov-supporting-document-pdf-doc-docx-xls-or-xlsx-maximum-20-mb" type="file" required={!isEdit || !existingFile} accept=".pdf,.doc,.docx,.xls,.xlsx" onChange={(event) => selectFile(event.target.files?.[0] || null)} /></div>{preview && <div className="flex items-center justify-between rounded-xl border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"><button type="button" onClick={() => setPreview(preview)} className="truncate font-semibold">{preview.name}</button><button type="button" onClick={removeFile} className="font-bold text-red-600">Remove</button></div>}{form.errors.attachment && <span className="text-xs text-red-600">{form.errors.attachment}</span>}<FloatingTextarea id="technical-remarks" label="Remarks" rows="4" value={form.data.remarks || ''} onChange={(event) => form.setData('remarks', event.target.value)} error={form.errors.remarks} /></div></Section>
                <div className="flex justify-end gap-3 border-t pt-4"><Link href={route('technical-reports.index')} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</Link><button type="submit" disabled={form.processing} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50">{form.processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Save Report'}</button></div>
            </form></Card>
            <div className="sticky top-6 xl:col-span-5"><FilePreviewPanel file={preview} title="MOV / Supporting Document" heightClass="h-[650px]" /></div>
        </div>
    </AuthenticatedLayout>;
}

function Section({ title, children }) {
  return <section className="space-y-4"><div className="border-b border-green-100 pb-2 dark:border-green-900"><h2 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">{title}</h2></div>{children}</section>;
}
