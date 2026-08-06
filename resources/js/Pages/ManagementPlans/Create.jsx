import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import PageHeader from '../../Components/PageHeader';
import PrimaryButton from '../../Components/PrimaryButton';
import { useState } from 'react';

const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

export default function Create({ protectedAreas, planTypes, statuses }) {
    const getInitialPlanType = () => {
        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            return params.get('type') || '';
        }
        return '';
    };

    const { data, setData, post, processing, errors } = useForm({
        protected_area_id: '',
        plan_type: getInitialPlanType(),
        title: '',
        version: 'v1',
        prepared_year: new Date().getFullYear(),
        status: 'Active',
        approval_date: '',
        valid_from: '',
        valid_until: '',
        attachments: [],
        remarks: '',
    });

    const [attachedFiles, setAttachedFiles] = useState([]);
    const [activePreviewIndex, setActivePreviewIndex] = useState(0);

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files);
        const newFilesWithPreviews = files.map(file => ({
            file,
            name: file.name,
            url: URL.createObjectURL(file),
        }));
        const updatedFiles = [...attachedFiles, ...newFilesWithPreviews];
        setAttachedFiles(updatedFiles);
        setData('attachments', updatedFiles.map(item => item.file));
        setActivePreviewIndex(updatedFiles.length - 1);
    };

    const handleRemoveFile = (indexToRemove) => {
        const updatedFiles = attachedFiles.filter((_, index) => index !== indexToRemove);
        setAttachedFiles(updatedFiles);
        setData('attachments', updatedFiles.map(item => item.file));
        if (activePreviewIndex >= updatedFiles.length) {
            setActivePreviewIndex(Math.max(0, updatedFiles.length - 1));
        }
    };

    const submit = (e) => {
        e.preventDefault();
        post('/management-plans', {
            forceFormData: true,
        });
    };

    const allowedStatuses = ['Active', 'For Update', 'Under Review'];
    const activeStatuses = statuses ? statuses.filter(st => allowedStatuses.includes(st)) : allowedStatuses;

    const filteredPlanTypes = planTypes
        ? planTypes.filter(type => !['ECC', 'CNC'].includes(String(type).toUpperCase()))
        : [];

    return (
        <AuthenticatedLayout title="Add Management Plan">
            <PageHeader
                title="Create Management Plan"
                description="Add a new versioned management plan for a protected area and preview documents."
                actions={
                    <Link href="/management-plans" className="text-sm font-semibold text-white hover:text-green-200 transition">
                        ← Back to management plans
                    </Link>
                }
            />

            <div className="mt-6 grid gap-6 xl:grid-cols-12 items-start w-full">

                {/* LEFT SIDE: FORM INPUTS */}
                <div className="xl:col-span-7 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <form onSubmit={submit} className="space-y-6">

                        <div>
                            <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-1 flex items-center gap-2">
                                <span>📌</span> PLAN DETAILS & STATUS
                            </h3>
                            <p className="text-xs text-gray-500 dark:text-gray-400">Select an existing protected area and identify the plan details.</p>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area *</label>
                                <select
                                    required
                                    value={data.protected_area_id}
                                    onChange={(e) => setData('protected_area_id', e.target.value)}
                                    className={selectClass}
                                >
                                    <option value="">Select a protected area</option>
                                    {protectedAreas.map((area) => (
                                        <option key={area.id} value={area.id}>{area.name}</option>
                                    ))}
                                </select>
                                {errors.protected_area_id && <p className="mt-1.5 text-sm text-red-700">{errors.protected_area_id}</p>}
                            </div>

                            <div className="sm:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Status *</label>
                                <select
                                    required
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                    className={selectClass}
                                >
                                    {activeStatuses.map((st) => (
                                        <option key={st} value={st}>
                                            {st === 'Active' ? 'Approved' : st}
                                        </option>
                                    ))}
                                </select>
                                {errors.status && <p className="mt-1.5 text-sm text-red-700">{errors.status}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Plan Type *</label>
                                <select
                                    required
                                    value={data.plan_type}
                                    onChange={(e) => setData('plan_type', e.target.value)}
                                    className={selectClass}
                                >
                                    <option value="">Select a plan type</option>
                                    {filteredPlanTypes.map((type) => (
                                        <option key={type} value={type}>{type}</option>
                                    ))}
                                </select>
                                {errors.plan_type && <p className="mt-1.5 text-sm text-red-700">{errors.plan_type}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Year Formulated *</label>
                                <input
                                    type="number"
                                    value={data.prepared_year}
                                    onChange={(e) => setData('prepared_year', e.target.value)}
                                    className={selectClass}
                                    required
                                />
                                {errors.prepared_year && <p className="mt-1.5 text-sm text-red-700">{errors.prepared_year}</p>}
                            </div>

                            <div className="sm:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Title *</label>
                                <input
                                    type="text"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    className={selectClass}
                                    placeholder="Enter management plan title..."
                                    required
                                />
                                {errors.title && <p className="mt-1.5 text-sm text-red-700">{errors.title}</p>}
                            </div>
                        </div>

                        <div className="border-t border-gray-200 pt-4 dark:border-gray-700">
                            <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-1 flex items-center gap-2">
                                <span>📅</span> APPROVAL AND VALIDITY
                            </h3>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Date Adopted by PAMB</label>
                                <input
                                    type="date"
                                    value={data.approval_date}
                                    onChange={(e) => setData('approval_date', e.target.value)}
                                    className={selectClass}
                                />
                                {errors.approval_date && <p className="mt-1.5 text-sm text-red-700">{errors.approval_date}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Validity Start</label>
                                <input
                                    type="date"
                                    value={data.valid_from}
                                    onChange={(e) => setData('valid_from', e.target.value)}
                                    className={selectClass}
                                />
                                {errors.valid_from && <p className="mt-1.5 text-sm text-red-700">{errors.valid_from}</p>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Update Due</label>
                                <input
                                    type="date"
                                    value={data.valid_until}
                                    onChange={(e) => setData('valid_until', e.target.value)}
                                    className={selectClass}
                                />
                                {errors.valid_until && <p className="mt-1.5 text-sm text-red-700">{errors.valid_until}</p>}
                            </div>
                        </div>

                        <div className="border-t border-gray-200 pt-4 dark:border-gray-700">
                            <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-1 flex items-center gap-2">
                                <span>📎</span> ATTACHMENTS AND REMARKS (MULTIPLE)
                            </h3>
                        </div>

                        <div className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Attachments (PDF)</label>
                                <input
                                    type="file"
                                    accept=".pdf"
                                    multiple
                                    onChange={handleFileChange}
                                    className="block w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 cursor-pointer border border-gray-300 dark:border-gray-700 rounded-xl dark:bg-gray-800 shadow-xs mt-1.5"
                                />
                                {errors.attachments && <p className="mt-1.5 text-sm text-red-700">{errors.attachments}</p>}

                                {attachedFiles.length > 0 && (
                                    <div className="flex flex-wrap gap-2 pt-3">
                                        {attachedFiles.map((item, index) => (
                                            <div
                                                key={index}
                                                onClick={() => setActivePreviewIndex(index)}
                                                className={`flex items-center gap-2 border px-3 py-1.5 rounded-lg text-xs font-semibold cursor-pointer transition shadow-xs ${
                                                    activePreviewIndex === index
                                                        ? 'bg-green-700 text-white border-green-700 shadow-sm'
                                                        : 'bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-700 hover:bg-gray-100'
                                                }`}
                                            >
                                                <span className="truncate max-w-[160px]" title={item.name}>📄 {item.name}</span>
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleRemoveFile(index);
                                                    }}
                                                    className={`font-bold ml-1 h-4 w-4 flex items-center justify-center rounded-full transition ${
                                                        activePreviewIndex === index
                                                            ? 'text-white hover:bg-green-800'
                                                            : 'text-red-500 hover:bg-red-100'
                                                    }`}
                                                    title="Tangtangon ang file"
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Remarks</label>
                                <textarea
                                    rows="3"
                                    value={data.remarks}
                                    onChange={(e) => setData('remarks', e.target.value)}
                                    className={selectClass}
                                    placeholder="Add any additional notes..."
                                />
                                {errors.remarks && <p className="mt-1.5 text-sm text-red-700">{errors.remarks}</p>}
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <PrimaryButton type="submit" disabled={processing}>
                                {processing ? 'Saving...' : 'Create management plan'}
                            </PrimaryButton>
                            <Link href="/management-plans" className="inline-flex items-center rounded-ui px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">
                                Cancel
                            </Link>
                        </div>
                    </form>
                </div>

                {/* RIGHT SIDE: LIVE PDF PREVIEW SWITCHER */}
                <div className="xl:col-span-5 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex flex-col h-[780px] sticky top-6">
                    <div className="flex items-center justify-between mb-1">
                        <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 flex items-center gap-2">
                            <span>👁️</span> LIVE DOCUMENT PREVIEW
                        </h3>
                        {attachedFiles[activePreviewIndex] && (
                            <a
                                href={attachedFiles[activePreviewIndex].url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs font-semibold text-green-700 dark:text-green-400 hover:underline"
                            >
                                Fullscreen ↗
                            </a>
                        )}
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
                        {attachedFiles[activePreviewIndex] ? `Viewing: ${attachedFiles[activePreviewIndex].name}` : 'Preview of attached management plan documents.'}
                    </p>

                    <div className="flex-1 w-full bg-gray-50 dark:bg-gray-900 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 flex items-center justify-center">
                        {attachedFiles.length > 0 && attachedFiles[activePreviewIndex] ? (
                            <iframe
                                src={attachedFiles[activePreviewIndex].url}
                                title={attachedFiles[activePreviewIndex].name}
                                className="w-full h-full border-0"
                            />
                        ) : (
                            <div className="text-center p-6 text-gray-400 dark:text-gray-500">
                                <span className="text-4xl mb-3 block">📁</span>
                                <h4 className="text-sm font-semibold text-gray-800 dark:text-white">No file selected for preview</h4>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xs mx-auto">
                                    Upload PDF files on the left to preview them here live.
                                </p>
                            </div>
                        )}
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
