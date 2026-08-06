import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';
import PrimaryButton from '../../Components/PrimaryButton';

const variants = {
    'Active': 'active',
    'Approved': 'active',
    'Expired': 'inactive',
    'For Update': 'pending',
    'Under Review': 'warning'
};

const messages = {
    'management-plan-created': 'Management plan created successfully.',
    'management-plan-updated': 'Management plan updated successfully.',
    'management-plan-deleted': 'Management plan deleted successfully.'
};

export default function Index({ managementPlans, filters, protectedAreas, planTypes, statuses, missingPlansSummary }) {
    const { props } = usePage();
    const auth = props.auth || {};
    const [search, setSearch] = useState(filters?.search || '');
    const [deleting, setDeleting] = useState(null);
    const [showSuccess, setShowSuccess] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Management plan updated successfully.');

    // STATE PARA SA EDIT MODAL
    const [editingPlan, setEditingPlan] = useState(null);

    const form = useForm({
        protected_area_id: '',
        plan_type: '',
        title: '',
        version: 'v1',
        prepared_year: new Date().getFullYear(),
        approval_date: '',
        valid_from: '',
        valid_until: '',
        status: 'Active',
        remarks: '',
        attachments: [],
        removed_attachments: [],
    });

    const [existingFiles, setExistingFiles] = useState([]);
    const [newFiles, setNewFiles] = useState([]);
    const [activePreview, setActivePreview] = useState(null);

    useEffect(() => {
        if (props.flash?.status && messages[props.flash.status]) {
            setSuccessMessage(messages[props.flash.status]);
            setShowSuccess(true);
        }
    }, [props.flash]);

    const openEditModal = (plan) => {
        setEditingPlan(plan);
        form.setData({
            protected_area_id: plan.protected_area_id || '',
            plan_type: plan.plan_type || '',
            title: plan.title || '',
            version: plan.version || 'v1',
            prepared_year: plan.prepared_year || new Date().getFullYear(),
            approval_date: plan.approval_date || '',
            valid_from: plan.valid_from || '',
            valid_until: plan.valid_until || '',
            status: plan.status || 'Active',
            remarks: plan.remarks || '',
            attachments: [],
            removed_attachments: [],
        });
        form.clearErrors();

        const files = plan.attachments ? (Array.isArray(plan.attachments) ? plan.attachments : [plan.attachments]) : [];
        setExistingFiles(files);
        setNewFiles([]);
        if (files.length > 0) {
            setActivePreview({ type: 'existing', index: 0 });
        } else {
            setActivePreview(null);
        }
    };

    const closeEditModal = () => {
        setEditingPlan(null);
        form.reset();
        form.clearErrors();
    };

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

        if (!activePreview && updatedNewFiles.length > 0) {
            setActivePreview({ type: 'new', index: updatedNewFiles.length - 1 });
        }
    };

    const handleRemoveExisting = (indexToRemove) => {
        const fileToRemove = existingFiles[indexToRemove];
        const updatedExisting = existingFiles.filter((_, idx) => idx !== indexToRemove);
        setExistingFiles(updatedExisting);

        form.setData('removed_attachments', [...form.data.removed_attachments, fileToRemove]);

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
                setActivePreview({ type: 'new', index: Math.max(0, updatedNew.length - 1) });
            } else if (existingFiles.length > 0) {
                setActivePreview({ type: 'existing', index: 0 });
            } else {
                setActivePreview(null);
            }
        }
    };

    const getActivePreviewUrl = () => {
        if (!activePreview) return null;
        if (activePreview.type === 'existing') {
            const path = existingFiles[activePreview.index];
            return path ? `/view-file/${path}` : null;
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

    const submitEdit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            _method: 'patch',
        }));

        form.post(`/management-plans/${editingPlan.id}`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                closeEditModal();
                setSuccessMessage('Management plan updated successfully.');
                setShowSuccess(true);
            }
        });
    };

    const currentPlanType = filters?.plan_type || '';
    let pageTitle = 'Management Plans';
    let pageDesc = 'Versioned management plans for DENR PENRO Mati protected areas.';

    if (currentPlanType === 'PAMP') pageTitle = 'Protected Area Management Plan (PAMP)';
    else if (currentPlanType === 'EMP') pageTitle = 'Ecotourism Management Plan (EMP)';
    else if (currentPlanType === 'CEPA') pageTitle = 'CEPA Plan';
    else if (currentPlanType === 'Other') pageTitle = 'Restoration Plan';

    useEffect(() => {
        setSearch(filters?.search || '');
    }, [filters?.search]);

    const visit = (params) => {
        router.get('/management-plans', { ...filters, search, ...params }, { preserveState: true, replace: true });
    };

    const remove = () => {
        router.delete(`/management-plans/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeleting(null);
                setSuccessMessage('Management plan deleted successfully.');
                setShowSuccess(true);
            }
        });
    };

    const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

    return (
        <AuthenticatedLayout title={pageTitle}>
            <style>{`
                @keyframes stroke { 100% { stroke-dashoffset: 0; } }
                @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.15, 1.15, 1); } }
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
                .checkmark-circle { animation: scale 0.3s ease-in-out 0.3s both; }
                .checkmark-check { stroke-dasharray: 50; stroke-dashoffset: 50; animation: stroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.15s forwards; }
            `}</style>

            <PageHeader
                title={pageTitle}
                description={pageDesc}
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/management-plans/summary" className="inline-flex items-center justify-center rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800">
                            📊 View Summary Report
                        </Link>
                        {auth.canCreateManagementPlans && (
                            <Link href="/management-plans/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                                Add management plan
                            </Link>
                        )}
                    </div>
                }
            />

            {missingPlansSummary && missingPlansSummary.length > 0 && (
                <div className="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {missingPlansSummary.map((summary) => (
                        <Card key={summary.id} className="border-l-4 border-l-amber-500 bg-white dark:bg-gray-800">
                            <h4 className="font-bold text-gray-900 dark:text-white text-base truncate mb-3">{summary.name}</h4>
                            <div className="space-y-2 text-xs">
                                {['PAMP', 'EMP', 'CEPA'].map((type) => (
                                    <div key={type} className="flex justify-between items-center">
                                        <span className="text-gray-500 dark:text-gray-400">{type}:</span>
                                        {summary[`has_active_${type.toLowerCase()}`] ? (
                                            <span className="px-2 py-0.5 rounded text-emerald-700 bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 font-medium">Approved</span>
                                        ) : (
                                            <span className="px-2 py-0.5 rounded text-amber-700 bg-amber-100 dark:bg-amber-950 dark:text-amber-300 font-semibold">No Active Plan</span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <Card className="mt-6" padding="p-0">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit({ page: 1 });
                    }}
                    className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-4"
                >
                    <label className="md:col-span-2">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Search</span>
                        <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Protected area, type, status, or year" className={selectClass} />
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area</span>
                        <select className={selectClass} value={filters?.protected_area_id || ''} onChange={(event) => visit({ protected_area_id: event.target.value, page: 1 })}>
                            <option value="">All protected areas</option>
                            {protectedAreas?.map((area) => (
                                <option key={area.id} value={area.id}>{area.name}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Plan Type</span>
                        <select className={selectClass} value={filters?.plan_type || ''} onChange={(event) => visit({ plan_type: event.target.value, page: 1 })}>
                            <option value="">All plan types</option>
                            {planTypes?.map((type) => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Status</span>
                        <select className={selectClass} value={filters?.status || ''} onChange={(event) => visit({ status: event.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {statuses?.map((item) => {
                                if (['Draft', 'Archived', 'Expired', 'For Updating'].includes(item)) return null;
                                let label = item === 'Active' ? 'Approved' : item;
                                return <option key={item} value={item}>{label}</option>;
                            })}
                        </select>
                    </label>

                    <div className="flex items-end">
                        <button type="submit" className="w-full rounded-ui bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">
                            Search
                        </button>
                    </div>
                </form>

                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                        <thead className="bg-gray-100 dark:bg-gray-700 text-xs uppercase text-gray-700 dark:text-gray-200">
                            <tr>
                                <th className="px-4 py-3">Protected Area</th>
                                <th className="px-4 py-3">Plan Type</th>
                                <th className="px-4 py-3">Year Formulated</th>
                                <th className="px-4 py-3">Date Adopted by PAMB</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3">Attachments</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                            {managementPlans?.data?.length > 0 ? (
                                managementPlans.data.map((plan) => {
                                    let displayStatus = plan.status === 'Active' ? 'Approved' : plan.status;
                                    let badgeVariant = variants[plan.status] || 'pending';
                                    const files = Array.isArray(plan.attachments) ? plan.attachments : (plan.attachments ? [plan.attachments] : []);

                                    return (
                                        <tr
                                            key={plan.id}
                                            onClick={() => { if (auth.canUpdateManagementPlans) openEditModal(plan); }}
                                            className="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition"
                                        >
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-white">{plan.protected_area_name}</td>
                                            <td className="px-4 py-3">{plan.plan_type}</td>
                                            <td className="px-4 py-3">{plan.prepared_year}</td>
                                            <td className="px-4 py-3">{plan.approval_date || '—'}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge variant={badgeVariant}>{displayStatus}</StatusBadge>
                                            </td>
                                            <td className="px-4 py-3">
                                                {files.length > 0 ? (
                                                    <span className="text-xs font-semibold text-green-700 dark:text-green-400">{files.length} file(s) attached</span>
                                                ) : (
                                                    <span className="text-gray-400 text-xs italic">None</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan="6" className="px-4 py-6 text-center text-gray-400 italic">
                                        No management plans found. Create a plan record or adjust the filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {managementPlans?.prev_page_url ? (
                    <Link href={managementPlans.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link>
                ) : (
                    <span />
                )}
                {managementPlans?.next_page_url ? (
                    <Link href={managementPlans.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link>
                ) : (
                    <span />
                )}
            </div>

            {/* EDIT MODAL */}
            {editingPlan && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto">
                    <div className="bg-white dark:bg-gray-800 rounded-2xl max-w-7xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden animate-pop-in">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <div>
                                <h3 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                    <span>📋</span> Management Plan Details & Document Preview
                                </h3>
                                <p className="text-xs text-gray-500 dark:text-gray-400">Update management plan indicators and review attached documents side-by-side.</p>
                            </div>
                            <button onClick={closeEditModal} className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 font-bold text-xl">✕</button>
                        </div>

                        <form onSubmit={submitEdit} className="flex flex-col flex-1 overflow-hidden">
                            <div className="grid grid-cols-1 xl:grid-cols-12 gap-6 p-6 overflow-y-auto flex-1">
                                <div className="xl:col-span-7 space-y-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="sm:col-span-2">
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area *</label>
                                            <select
                                                value={form.data.protected_area_id}
                                                onChange={(e) => form.setData('protected_area_id', e.target.value)}
                                                className={selectClass}
                                                required
                                            >
                                                <option value="">Select a protected area</option>
                                                {protectedAreas?.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Plan Type *</label>
                                            <select
                                                value={form.data.plan_type}
                                                onChange={(e) => form.setData('plan_type', e.target.value)}
                                                className={selectClass}
                                                required
                                            >
                                                <option value="">Select plan type</option>
                                                {planTypes?.map(type => <option key={type} value={type}>{type}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Year Formulated *</label>
                                            <input
                                                type="number"
                                                value={form.data.prepared_year}
                                                onChange={(e) => form.setData('prepared_year', e.target.value)}
                                                className={selectClass}
                                                required
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Title *</label>
                                            <input
                                                type="text"
                                                value={form.data.title}
                                                onChange={(e) => form.setData('title', e.target.value)}
                                                className={selectClass}
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Status *</label>
                                            <select
                                                value={form.data.status}
                                                onChange={(e) => form.setData('status', e.target.value)}
                                                className={selectClass}
                                                required
                                            >
                                                {statuses?.map(st => <option key={st} value={st}>{st === 'Active' ? 'Approved' : st}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">PAMB Adoption Date</label>
                                            <input
                                                type="date"
                                                value={form.data.approval_date}
                                                onChange={(e) => form.setData('approval_date', e.target.value)}
                                                className={selectClass}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Validity Start</label>
                                            <input
                                                type="date"
                                                value={form.data.valid_from}
                                                onChange={(e) => form.setData('valid_from', e.target.value)}
                                                className={selectClass}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Update Due</label>
                                            <input
                                                type="date"
                                                value={form.data.valid_until}
                                                onChange={(e) => form.setData('valid_until', e.target.value)}
                                                className={selectClass}
                                            />
                                        </div>
                                    </div>

                                    <div className="border-t border-gray-200 pt-4 dark:border-gray-700">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-2">📎 ATTACHMENTS & REMARKS</h4>

                                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Upload Additional Files (PDF)</label>
                                        <input
                                            type="file"
                                            multiple
                                            accept=".pdf"
                                            onChange={handleFileChange}
                                            className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 cursor-pointer border border-gray-300 dark:border-gray-700 rounded-xl dark:bg-gray-800"
                                        />

                                        {existingFiles.length > 0 && (
                                            <div className="mt-3">
                                                <p className="text-xs font-semibold text-gray-500 mb-1">Existing Files (Click to Preview):</p>
                                                <div className="flex flex-wrap gap-2">
                                                    {existingFiles.map((file, idx) => {
                                                        const fileName = file.split('/').pop();
                                                        const isSelected = activePreview?.type === 'existing' && activePreview?.index === idx;
                                                        return (
                                                            <div
                                                                key={idx}
                                                                onClick={() => setActivePreview({ type: 'existing', index: idx })}
                                                                className={`flex items-center gap-2 border px-3 py-1.5 rounded-lg text-xs font-semibold cursor-pointer transition ${
                                                                    isSelected ? 'bg-green-700 text-white border-green-700' : 'bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-700'
                                                                }`}
                                                            >
                                                                <span>📄 {fileName}</span>
                                                                <button
                                                                    type="button"
                                                                    onClick={(e) => { e.stopPropagation(); handleRemoveExisting(idx); }}
                                                                    className={`font-bold ml-1 h-4 w-4 flex items-center justify-center rounded-full ${isSelected ? 'text-white hover:bg-green-800' : 'text-red-500 hover:bg-red-100'}`}
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        )}

                                        {newFiles.length > 0 && (
                                            <div className="mt-3">
                                                <p className="text-xs font-semibold text-gray-500 mb-1">New Uploads (Click to Preview):</p>
                                                <div className="flex flex-wrap gap-2">
                                                    {newFiles.map((item, idx) => {
                                                        const isSelected = activePreview?.type === 'new' && activePreview?.index === idx;
                                                        return (
                                                            <div
                                                                key={idx}
                                                                onClick={() => setActivePreview({ type: 'new', index: idx })}
                                                                className={`flex items-center gap-2 border px-3 py-1.5 rounded-lg text-xs font-semibold cursor-pointer transition ${
                                                                    isSelected ? 'bg-green-700 text-white border-green-700' : 'bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-700'
                                                                }`}
                                                            >
                                                                <span>📄 {item.name}</span>
                                                                <button
                                                                    type="button"
                                                                    onClick={(e) => { e.stopPropagation(); handleRemoveNew(idx); }}
                                                                    className={`font-bold ml-1 h-4 w-4 flex items-center justify-center rounded-full ${isSelected ? 'text-white hover:bg-green-800' : 'text-red-500 hover:bg-red-100'}`}
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        )}

                                        <div className="mt-3">
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">Remarks</label>
                                            <textarea
                                                rows="2"
                                                value={form.data.remarks}
                                                onChange={(e) => form.setData('remarks', e.target.value)}
                                                className={selectClass}
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div className="xl:col-span-5 flex flex-col h-[550px] bg-gray-50 dark:bg-gray-900 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                                    <div className="flex items-center justify-between mb-2">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">👁️ LIVE DOCUMENT PREVIEW</h4>
                                        {getActivePreviewUrl() && (
                                            <a href={getActivePreviewUrl()} target="_blank" rel="noopener noreferrer" className="text-xs font-semibold text-green-700 dark:text-green-400 hover:underline">
                                                Fullscreen ↗
                                            </a>
                                        )}
                                    </div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-3 truncate">
                                        {activePreview ? `Viewing: ${getActivePreviewName()}` : 'No file selected for preview.'}
                                    </p>
                                    <div className="flex-1 w-full bg-white dark:bg-gray-950 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-800 flex items-center justify-center">
                                        {getActivePreviewUrl() ? (
                                            <iframe src={getActivePreviewUrl()} title="Live Preview" className="w-full h-full border-0" />
                                        ) : (
                                            <div className="text-center p-6 text-gray-400">
                                                <span className="text-3xl mb-2 block">📁</span>
                                                <p className="text-xs">Select or upload a PDF file to preview.</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between px-6 py-4 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700">
                                <button
                                    type="button"
                                    onClick={() => { setDeleting(editingPlan); closeEditModal(); }}
                                    className="px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 rounded-lg transition"
                                >
                                    Delete Record
                                </button>
                                <div className="flex gap-3">
                                    <button type="button" onClick={closeEditModal} className="px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 rounded-lg transition dark:text-gray-300 dark:hover:bg-gray-800">
                                        Cancel
                                    </button>
                                    <PrimaryButton type="submit" disabled={form.processing}>
                                        {form.processing ? 'Saving Changes...' : 'Save Changes'}
                                    </PrimaryButton>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* UNIFORM DELETE CONFIRMATION MODAL (PULA ANG TEMA) */}
            {deleting && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                    <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">
                        <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">⚠️</div>
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Delete management plan?</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Remove {deleting?.title} from the active registry? This plan record is soft deleted.</p>
                        <div className="flex gap-3">
                            <button type="button" onClick={() => setDeleting(null)} className="flex-1 px-4 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold transition hover:bg-gray-200">Cancel</button>
                            <button type="button" onClick={remove} className="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition shadow-sm">Yes, Delete</button>
                        </div>
                    </div>
                </div>
            )}

            {/* SUCCESS POPUP WITH CHECKMARK ANIMATION */}
            {showSuccess && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
                    <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center animate-pop-in">
                        <div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-950 mb-4 shadow-sm">
                            <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor">
                                <path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Success!</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">{successMessage}</p>
                        <button
                            type="button"
                            onClick={() => setShowSuccess(false)}
                            className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition text-sm"
                        >
                            Continue
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
