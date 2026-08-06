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
        attachments: [],
        removed_attachments: [],
    });

    // Existing files gikan sa database (kung naa sa edit mode)
    const [existingFiles, setExistingFiles] = useState(
        managementPlan?.attachments ? (Array.isArray(managementPlan.attachments) ? managementPlan.attachments : [managementPlan.attachments]) : []
    );

    // Bag-ong gi-upload nga files sa user
    const [newFiles, setNewFiles] = useState([]);

    // Tracking kung hain ang gi-preview karon ('existing-{index}' o 'new-{index}')
    const [activePreview, setActivePreview] = useState(() => {
        const initialExist = managementPlan?.attachments ? (Array.isArray(managementPlan.attachments) ? managementPlan.attachments : [managementPlan.attachments]) : [];
        if (initialExist.length > 0) return { type: 'existing', index: 0 };
        return null;
    });

    const handleFileChange = (event) => {
        const files = Array.from(event.target.files);
        const mappedNewFiles = files.map(file => ({
            file,
            name: file.name,
            url: URL.createObjectURL(file),
        }));

        const updatedNewFiles = [...newFiles, ...mappedNewFiles];
        setNewFiles(updatedNewFiles);
        form.setData('attachments', updatedNewFiles.map(item => item.file));

        // Kung wala pay active preview, i-set sa pinakabag-ong gi-upload
        if (!activePreview && updatedNewFiles.length > 0) {
            setActivePreview({ type: 'new', index: updatedNewFiles.length - 1 });
        }
    };

    const handleRemoveExisting = (indexToRemove) => {
        const fileToRemove = existingFiles[indexToRemove];
        const updatedExisting = existingFiles.filter((_, idx) => idx !== indexToRemove);
        setExistingFiles(updatedExisting);

        // I-record sa removed_attachments aron ma-delete sa server
        const updatedRemoved = [...form.data.removed_attachments, fileToRemove];
        form.setData('removed_attachments', updatedRemoved);

        // Adjust active preview kung ang gitangtang mao ang gi-preview karon
        if (activePreview?.type === 'existing' && activePreview.index === indexToRemove) {
            if (updatedExisting.length > 0) {
                setActivePreview({ type: 'existing', index: Math.max(0, indexToRemove - 1) });
            } else if (newFiles.length > 0) {
                setActivePreview({ type: 'new', index: 0 });
            } else {
                setActivePreview(null);
            }
        }
    };

    const handleRemoveNew = (indexToRemove) => {
        const updatedNew = newFiles.filter((_, idx) => idx !== indexToRemove);
        setNewFiles(updatedNew);
        form.setData('attachments', updatedNew.map(item => item.file));

        if (activePreview?.type === 'new' && activePreview.index === indexToRemove) {
            if (updatedNew.length > 0) {
                setActivePreview({ type: 'new', index: Math.max(0, indexToRemove - 1) });
            } else if (existingFiles.length > 0) {
                setActivePreview({ type: 'existing', index: 0 });
            } else {
                setActivePreview(null);
            }
        }
    };

    // Kuhaa ang URL sa active preview karon para sa iframe
    const getActivePreviewUrl = () => {
        if (!activePreview) return null;
        if (activePreview.type === 'existing') {
            const filePath = existingFiles[activePreview.index];
            return filePath ? `/view-file/${filePath}` : null;
        }
        if (activePreview.type === 'new') {
            return newFiles[activePreview.index]?.url || null;
        }
        return null;
    };

    const getActivePreviewName = () => {
        if (!activePreview) return '';
        if (activePreview.type === 'existing') {
            const path = existingFiles[activePreview.index];
            return path ? path.split('/').pop() : '';
        }
        if (activePreview.type === 'new') {
            return newFiles[activePreview.index]?.name || '';
        }
        return '';
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
                description={isEdit ? 'Update this management plan record and review attached documents side-by-side.' : 'Register a new management plan version and preview documents.'}
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

                        <FormSection title="Attachments and Remarks">
                            <div className="grid gap-4">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor="attachment">
                                    Attachments (Multiple PDF)
                                    <input id="attachment" type="file" multiple accept=".pdf" className="block w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 cursor-pointer border border-gray-300 dark:border-gray-700 rounded-xl dark:bg-gray-800 shadow-xs mt-1.5" onChange={handleFileChange} />
                                    {form.errors.attachments && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.attachments}</p>}
                                </label>

                                {/* LISTAHAN SA EXISTING FILES NGA NAA NA SA DATABASE */}
                                {existingFiles.length > 0 && (
                                    <div>
                                        <p className="text-xs font-semibold text-gray-500 mb-1.5">Existing Files (Click to Preview):</p>
                                        <div className="flex flex-wrap gap-2">
                                            {existingFiles.map((file, index) => {
                                                const fileName = file.split('/').pop();
                                                const isSelected = activePreview?.type === 'existing' && activePreview?.index === index;
                                                return (
                                                    <div
                                                        key={index}
                                                        onClick={() => setActivePreview({ type: 'existing', index })}
                                                        className={`flex items-center gap-2 border px-3 py-1.5 rounded-lg text-xs font-semibold cursor-pointer transition shadow-xs ${
                                                            isSelected
                                                                ? 'bg-green-700 text-white border-green-700 shadow-sm'
                                                                : 'bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-700 hover:bg-gray-100'
                                                        }`}
                                                    >
                                                        <span className="truncate max-w-[160px]" title={fileName}>📄 {fileName}</span>
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                handleRemoveExisting(index);
                                                            }}
                                                            className={`font-bold ml-1 h-4 w-4 flex items-center justify-center rounded-full transition ${
                                                                isSelected ? 'text-white hover:bg-green-800' : 'text-red-500 hover:bg-red-100'
                                                            }`}
                                                            title="Tangtangon ang file"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* LISTAHAN SA BAG-ONG GI-UPLOAD NGA FILES */}
                                {newFiles.length > 0 && (
                                    <div>
                                        <p className="text-xs font-semibold text-gray-500 mb-1.5">New Uploads (Click to Preview):</p>
                                        <div className="flex flex-wrap gap-2">
                                            {newFiles.map((item, index) => {
                                                const isSelected = activePreview?.type === 'new' && activePreview?.index === index;
                                                return (
                                                    <div
                                                        key={index}
                                                        onClick={() => setActivePreview({ type: 'new', index })}
                                                        className={`flex items-center gap-2 border px-3 py-1.5 rounded-lg text-xs font-semibold cursor-pointer transition shadow-xs ${
                                                            isSelected
                                                                ? 'bg-green-700 text-white border-green-700 shadow-sm'
                                                                : 'bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-700 hover:bg-gray-100'
                                                        }`}
                                                    >
                                                        <span className="truncate max-w-[160px]" title={item.name}>📄 {item.name}</span>
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                handleRemoveNew(index);
                                                            }}
                                                            className={`font-bold ml-1 h-4 w-4 flex items-center justify-center rounded-full transition ${
                                                                isSelected ? 'text-white hover:bg-green-800' : 'text-red-500 hover:bg-red-100'
                                                            }`}
                                                            title="Tangtangon ang file"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

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

                {/* RIGHT SIDE: LIVE DOCUMENT PREVIEW SWITCHER */}
                <Card className="xl:col-span-5 flex flex-col h-[780px] sticky top-6">
                    <div className="flex items-center justify-between mb-1">
                        <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 flex items-center gap-2">
                            <span>👁️</span> LIVE DOCUMENT PREVIEW
                        </h3>
                        {getActivePreviewUrl() && (
                            <a
                                href={getActivePreviewUrl()}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs font-semibold text-green-700 dark:text-green-400 hover:underline"
                            >
                                Fullscreen ↗
                            </a>
                        )}
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
                        {activePreview ? `Viewing: ${getActivePreviewName()}` : 'Preview of attached management plan documents.'}
                    </p>

                    <div className="flex-1 w-full bg-gray-50 dark:bg-gray-900 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 flex items-center justify-center">
                        {getActivePreviewUrl() ? (
                            <iframe
                                src={getActivePreviewUrl()}
                                title={getActivePreviewName()}
                                className="w-full h-full border-0"
                            />
                        ) : (
                            <div className="text-center p-6 text-gray-400 dark:text-gray-500">
                                <span className="text-4xl mb-3 block">📁</span>
                                <h4 className="text-sm font-semibold text-gray-800 dark:text-white">No file selected for preview</h4>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xs mx-auto">
                                    Upload or select PDF files on the left to preview them here live.
                                </p>
                            </div>
                        )}
                    </div>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
