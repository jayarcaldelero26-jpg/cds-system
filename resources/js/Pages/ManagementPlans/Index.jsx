import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

// Map status internally to badge variants
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
    const { auth, status } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [deleting, setDeleting] = useState(null);
    const [showSuccess, setShowSuccess] = useState(false);

    // DYNAMIC TITLE LOGIC BASE SA GIPILI NGA PLAN TYPE
    const currentPlanType = filters.plan_type || '';
    let pageTitle = 'Management Plans';
    let pageDesc = 'Versioned management plans for DENR PENRO Mati protected areas.';

    if (currentPlanType === 'PAMP') {
        pageTitle = 'Protected Area Management Plan (PAMP)';
    } else if (currentPlanType === 'EMP') {
        pageTitle = 'Ecotourism Management Plan (EMP)';
    } else if (currentPlanType === 'CEPA') {
        pageTitle = 'CEPA Plan';
    } else if (currentPlanType === 'Other') {
        pageTitle = 'Restoration Plan';
    }

    useEffect(() => {
        setSearch(filters.search || '');
    }, [filters.search]);

    useEffect(() => {
        if (status && messages[status]) {
            setShowSuccess(true);
        }
    }, [status]);

    const visit = (params) => {
        router.get('/management-plans', { ...filters, search, ...params }, { preserveState: true, replace: true });
    };

    const remove = () => {
        router.delete(`/management-plans/${deleting.id}`, { onFinish: () => setDeleting(null) });
    };

    const columns = [
        {
            key: 'protected_area_name',
            label: 'Protected Area',
            render: (plan) => <span className="font-medium text-gray-900 dark:text-white">{plan.protected_area_name}</span>
        },
        { key: 'plan_type', label: 'Plan Type' },
        { key: 'prepared_year', label: 'Year Formulated' },
        {
            key: 'approval_date',
            label: 'Date Adopted by PAMB',
            render: (plan) => plan.approval_date || '—'
        },
        // STATUS UG AUTO-EXPIRE LOGIC (Direct String Comparison)
        {
            key: 'status',
            label: 'Status',
            render: (plan) => {
                let displayStatus = plan.status === 'Active' ? 'Approved' : plan.status;
                let badgeVariant = variants[plan.status] || 'pending';

                if (plan.valid_until) {
                    // Kuhaon ang local date karon sa format nga YYYY-MM-DD
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0');
                    const day = String(now.getDate()).padStart(2, '0');
                    const todayFormatted = `${year}-${month}-${day}`;

                    // Diretso i-compare ang strings (e.g., "2026-07-23" >= "2026-07-23")
                    if (todayFormatted >= plan.valid_until) {
                        displayStatus = 'Expired';
                        badgeVariant = 'inactive';
                    }
                }

                return (
                    <div className="flex items-center">
                        <StatusBadge variant={badgeVariant}>{displayStatus}</StatusBadge>
                    </div>
                );
            }
        },
        {
            key: 'attachment',
            label: 'Attachment',
            render: (plan) => plan.attachment ? (
                <div className="flex items-center gap-2">
                    <a href={`/view-file/${plan.attachment}`} target="_blank" rel="noopener noreferrer" className="text-green-700 hover:text-green-900 dark:text-green-400 text-sm font-medium">
                        View
                    </a>
                    <span className="text-gray-300 dark:text-gray-600">|</span>
                    <a href={`/storage/${plan.attachment}`} download className="text-blue-700 hover:text-blue-900 dark:text-blue-400 text-sm font-medium">
                        Download
                    </a>
                </div>
            ) : <span className="text-gray-400 text-xs italic">None</span>
        },
        {
            key: 'actions',
            label: <span className="sr-only">Actions</span>,
            cellClassName: 'text-right',
            render: (plan) => (
                <div className="flex justify-end gap-3">
                    {auth.canUpdateManagementPlans && (
                        <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/management-plans/${plan.id}/edit`}>
                            Edit
                        </Link>
                    )}
                    {auth.canDeleteManagementPlans && (
                        <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(plan)}>
                            Delete
                        </button>
                    )}
                </div>
            )
        }
    ];

    const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

    return (
       <AuthenticatedLayout title={pageTitle}>
            <PageHeader
                title={pageTitle}
                description={pageDesc}
                actions={
                    <div className="flex items-center gap-3">
                        <Link
                            href="/management-plans/summary"
                            className="inline-flex items-center justify-center rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800"
                        >
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
                        <select className={selectClass} value={filters.protected_area_id || ''} onChange={(event) => visit({ protected_area_id: event.target.value, page: 1 })}>
                            <option value="">All protected areas</option>
                            {protectedAreas.map((area) => (
                                <option key={area.id} value={area.id}>{area.name}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Plan Type</span>
                        <select className={selectClass} value={filters.plan_type || ''} onChange={(event) => visit({ plan_type: event.target.value, page: 1 })}>
                            <option value="">All plan types</option>
                            {planTypes.map((type) => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Status</span>
                        <select className={selectClass} value={filters.status || ''} onChange={(event) => visit({ status: event.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {statuses.map((item) => {
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

                <DataTable
                    columns={columns}
                    rows={managementPlans.data}
                    emptyTitle="No management plans found"
                    emptyDescription="Create a plan record or adjust the filters."
                />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {managementPlans.prev_page_url ? (
                    <Link href={managementPlans.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link>
                ) : (
                    <span />
                )}
                {managementPlans.next_page_url ? (
                    <Link href={managementPlans.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link>
                ) : (
                    <span />
                )}
            </div>

            <ConfirmDialog
                open={Boolean(deleting)}
                title="Delete management plan?"
                message={`Remove ${deleting?.title} from the active registry? This plan record is soft deleted.`}
                confirmLabel="Delete management plan"
                onCancel={() => setDeleting(null)}
                onConfirm={remove}
            />

            {/* SUCCESS MODAL */}
            {showSuccess && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
                    <style>{`
                        @keyframes stroke { 100% { stroke-dashoffset: 0; } }
                        @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.15, 1.15, 1); } }
                        @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                        .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
                        .checkmark-circle { animation: scale 0.3s ease-in-out 0.3s both; }
                        .checkmark-check { stroke-dasharray: 50; stroke-dashoffset: 50; animation: stroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.15s forwards; }
                    `}</style>
                    <div className="bg-white dark:bg-gray-800 rounded-xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center animate-pop-in">
                        <div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-950 mb-4 shadow-sm">
                            <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor">
                                <path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 font-sans">Success!</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">{messages[status]}</p>
                        <button type="button" onClick={() => setShowSuccess(false)} className="w-full inline-flex justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition active:scale-95">Okay</button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
