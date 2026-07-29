import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import FormField from '../../Components/FormField';
import FormSection from '../../Components/FormSection';
import PageHeader from '../../Components/PageHeader';
import PrimaryButton from '../../Components/PrimaryButton';
import { useState } from 'react';

const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

export default function Form({ title, managementPlan, protectedAreas, planTypes, statuses }) {
    const isEdit = Boolean(managementPlan);

    // Kuhaa ang plan_type gikan sa URL params kung ga-create bag-o aron dynamic ang title
    const urlParams = new URLSearchParams(window.location.search);
    const planTypeQuery = urlParams.get('plan_type');
    const currentPlanType = managementPlan?.plan_type || planTypeQuery || '';

    // Dynamic Header Title base sa gi-open nga plan type
    let headerTitle = title || 'Management Plan';
    if (currentPlanType === 'PAMP') headerTitle = 'Protected Area Management Plan (PAMP)';
    else if (currentPlanType === 'EMP') headerTitle = 'Ecotourism Management Plan (EMP)';
    else if (currentPlanType === 'CEPA') headerTitle = 'CEPA Plan';
    else if (currentPlanType === 'Other') headerTitle = 'Restoration Plan';

    const form = useForm({
        protected_area_id: managementPlan?.protected_area_id || '',
        plan_type: currentPlanType,
        title: managementPlan?.title || '',
        version: managementPlan?.version || 'v1',
        prepared_year: managementPlan?.prepared_year || new Date().getFullYear(),
        approval_date: managementPlan?.approval_date || '',
        valid_from: managementPlan?.valid_from || '',
        valid_until: managementPlan?.valid_until || '',
        status: managementPlan?.status || 'Active',
        remarks: managementPlan?.remarks || '',
        attachment: null
    });

    const [previewUrl, setPreviewUrl] = useState(
        managementPlan?.attachment ? `/view-file/${managementPlan.attachment}` : null
    );

    const handleFileChange = (event) => {
        const file = event.target.files[0];
        if (file) {
            form.setData('attachment', file);
            setPreviewUrl(URL.createObjectURL(file));
        } else {
            form.setData('attachment', null);
            setPreviewUrl(managementPlan?.attachment ? `/view-file/${managementPlan.attachment}` : null);
        }
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.transform((data) => ({
                ...data,
                _method: 'patch',
            }));

            form.post(`/management-plans/${managementPlan.id}`, {
                forceFormData: true,
            });
        } else {
            form.post('/management-plans', {
                forceFormData: true
            });
        }
    };

    const allowedStatuses = ['Active', 'For Update', 'Under Review'];
    const activeStatuses = statuses ? statuses.filter(st => allowedStatuses.includes(st)) : allowedStatuses;

    const select = (id, label, options) => (
        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor={id}>
            {label}
            <select id={id} className={selectClass} value={form.data[id]} onChange={(event) => form.setData(id, event.target.value)}>
                {options}
            </select>
            {form.errors[id] && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors[id]}</p>}
        </label>
    );

    return (
        <AuthenticatedLayout title={headerTitle}>
            <PageHeader
                title={headerTitle}
                description={isEdit ? 'Update this management plan record and view attached documents.' : 'Register a new management plan version and preview documents.'}
                actions={<Link href="/management-plans" className="text-sm font-semibold text-white hover:text-green-200 transition">← Back to management plans</Link>}
            />

            <div className="mt-6 grid gap-6 xl:grid-cols-12 items-start">
                <Card className="xl:col-span-7">
                    <form onSubmit={submit} className="space-y-6">
                        <FormSection title="Plan Details" description="Select protected area and plan details.">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <label htmlFor="protected-area-id" className="block text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area</label>
                                    <select
                                        id="protected-area-id"
                                        required
                                        aria-label="Protected Area"
                                        className={selectClass}
                                        value={form.data.protected_area_id}
                                        onChange={(event) => form.setData('protected_area_id', event.target.value)}
                                    >
                                        <option value="">Select a protected area</option>
                                        {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                                    </select>
                                    {form.errors.protected_area_id && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.protected_area_id}</p>}
                                </div>

                                {select('plan_type', 'Plan Type', <><option value="">Select a plan type</option>{planTypes.map((type) => <option key={type}>{type}</option>)}</>)}

                                <FormField id="title" label="Title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} error={form.errors.title} required />

                                <FormField id="prepared_year" label="Year Formulated" type="number" min="1800" max={new Date().getFullYear() + 10} value={form.data.prepared_year} onChange={(event) => form.setData('prepared_year', event.target.value)} error={form.errors.prepared_year} required />

                                {select('status', 'Status', activeStatuses.map((st) => (
                                    <option key={st} value={st}>
                                        {st === 'Active' ? 'Approved' : st}
                                    </option>
                                )))}
                            </div>
                        </FormSection>

                        <FormSection title="Approval and Validity">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <FormField id="approval_date" label="PAMB Adoption" type="date" value={form.data.approval_date} onChange={(event) => form.setData('approval_date', event.target.value)} error={form.errors.approval_date} />
                                <FormField id="valid_from" label="Validity Start" type="date" value={form.data.valid_from} onChange={(event) => form.setData('valid_from', event.target.value)} error={form.errors.valid_from} />
                                <FormField id="valid_until" label="Update Due" type="date" value={form.data.valid_until} onChange={(event) => form.setData('valid_until', event.target.value)} error={form.errors.valid_until} />
                            </div>
                        </FormSection>

                        <FormSection title="Attachment and Remarks">
                            <div className="grid gap-4">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor="attachment">
                                    Attachment (PDF)
                                    <input id="attachment" type="file" accept=".pdf" className={selectClass} onChange={handleFileChange} />
                                    {form.errors.attachment && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.attachment}</p>}
                                </label>

                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor="remarks">
                                    Remarks
                                    <textarea id="remarks" rows="3" className={selectClass} value={form.data.remarks} onChange={(event) => form.setData('remarks', event.target.value)} />
                                    {form.errors.remarks && <p className="mt-1.5 text-sm text-red-700">{form.errors.remarks}</p>}
                                </label>
                            </div>
                        </FormSection>

                        <div className="flex flex-wrap gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <PrimaryButton type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : isEdit ? 'Save changes' : 'Create management plan'}</PrimaryButton>
                            <Link href="/management-plans" className="inline-flex items-center rounded-ui px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</Link>
                        </div>
                    </form>
                </Card>

                <Card className="xl:col-span-5 flex flex-col h-[750px] sticky top-28">
                    <h3 className="text-base font-semibold text-gray-900 dark:text-white mb-2">Document Live Preview</h3>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">Preview of the attached management plan document.</p>

                    <div className="flex-1 w-full bg-gray-100 dark:bg-gray-900 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 flex items-center justify-center">
                        {previewUrl ? (
                            <iframe
                                src={previewUrl}
                                title="Management Plan Preview"
                                className="w-full h-full border-0"
                            />
                        ) : (
                            <div className="text-center p-6 text-gray-400 dark:text-gray-500">
                                <svg className="mx-auto h-12 w-12 stroke-current opacity-40 mb-2" fill="none" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p className="text-sm font-medium">No PDF document attached yet.</p>
                                <p className="text-xs mt-1">Upload a PDF file on the left to preview it here.</p>
                            </div>
                        )}
                    </div>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
