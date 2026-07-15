import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

const ratingVariants = {
    'Low': 'active',      // Green / Positive
    'Moderate': 'pending', // Yellow / Warning
    'High': 'inactive'     // Red / Critical
};

const statusVariants = {
    'Approved': 'active',
    'Under Review': 'pending'
};

const messages = {
    'ecotourism-monitoring-created': 'Ecotourism monitoring record created successfully.',
    'ecotourism-monitoring-updated': 'Ecotourism monitoring record updated successfully.',
    'ecotourism-monitoring-deleted': 'Ecotourism monitoring record deleted successfully.'
};

export default function Index({ monitorings, filters, protectedAreas, impactRatings }) {
    const { status } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [deleting, setDeleting] = useState(null);
    const [showSuccess, setShowSuccess] = useState(false);

    useEffect(() => setSearch(filters.search || ''), [filters.search]);

    useEffect(() => {
        if (status && messages[status]) {
            setShowSuccess(true);
        }
    }, [status]);

    const visit = (params) => router.get('/ecotourism-monitorings', { ...filters, search, ...params }, { preserveState: true, replace: true });
    const remove = () => router.delete(`/ecotourism-monitorings/${deleting.id}`, { onFinish: () => setDeleting(null) });

    const columns = [
        {
            key: 'protected_area_name',
            label: 'Protected Area/PAMO',
            render: (item) => <span className="font-medium text-gray-900 dark:text-white">{item.protected_area_name}</span>
        },
        { key: 'site_name', label: 'Ecotourism Site' },
        {
            key: 'monitoring_date',
            label: 'Monitoring Date',
            render: (item) => item.monitoring_date
        },
        {
            key: 'visitors_count',
            label: 'Visitors Count',
            render: (item) => Number(item.visitors_count).toLocaleString()
        },
        {
            key: 'impact_rating',
            label: 'Impact Rating',
            render: (item) => <StatusBadge variant={ratingVariants[item.impact_rating]}>{item.impact_rating}</StatusBadge>
        },
        {
            key: 'status',
            label: 'Status',
            render: (item) => <StatusBadge variant={statusVariants[item.status]}>{item.status}</StatusBadge>
        },
        {
            key: 'attachment',
            label: 'Attachment',
            render: (item) => item.attachment ? (
                <div className="flex items-center gap-2">
                    <a href={`/view-file/${item.attachment}`} target="_blank" rel="noopener noreferrer" className="text-green-700 hover:text-green-900 dark:text-green-400 text-sm font-medium">
                        View
                    </a>
                    <span className="text-gray-300 dark:text-gray-600">|</span>
                    <a href={`/storage/${item.attachment}`} download className="text-blue-700 hover:text-blue-900 dark:text-blue-400 text-sm font-medium">
                        Download
                    </a>
                </div>
            ) : <span className="text-gray-400 text-xs italic">No Attachment</span>
        },
        {
            key: 'actions',
            label: <span className="sr-only">Actions</span>,
            cellClassName: 'text-right',
            render: (item) => (
                <div className="flex justify-end gap-3">
                    <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/ecotourism-monitorings/${item.id}/edit`}>Edit</Link>
                    <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(item)}>Delete</button>
                </div>
            )
        }
    ];

    const selectClass = 'mt-1.5 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

    return (
        <AuthenticatedLayout title="Ecotourism Impact Monitoring">
            <PageHeader
                title="Ecotourism Impact Monitoring"
                description="Consolidate, review, and maintain assessment results of the Impact Monitoring of Ecotourism Activities."
                actions={
                    <Link href="/ecotourism-monitorings/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                        Add monitoring record
                    </Link>
                }
            />

            <Card className="mt-6" padding="p-0">
                <form onSubmit={(e) => { e.preventDefault(); visit({ page: 1 }); }} className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-4">
                    <label className="md:col-span-2">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Search</span>
                        <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search sites, issues, or PAMOs..." className={selectClass} />
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area</span>
                        <select className={selectClass} value={filters.protected_area_id || ''} onChange={(e) => visit({ protected_area_id: e.target.value, page: 1 })}>
                            <option value="">All protected areas</option>
                            {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Impact Rating</span>
                        <select className={selectClass} value={filters.impact_rating || ''} onChange={(e) => visit({ impact_rating: e.target.value, page: 1 })}>
                            <option value="">All ratings</option>
                            {impactRatings.map((rating) => <option key={rating} value={rating}>{rating} Impact</option>)}
                        </select>
                    </label>
                    <div className="flex items-end md:col-span-4">
                        <button type="submit" className="w-full sm:w-auto rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">Search Filter</button>
                    </div>
                </form>

                <DataTable columns={columns} rows={monitorings.data} emptyTitle="No monitoring records found" emptyDescription="Input assessment results or adjust your search filters." />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {monitorings.prev_page_url ? <Link href={monitorings.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link> : <span />}
                {monitorings.next_page_url ? <Link href={monitorings.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link> : <span />}
            </div>

            <ConfirmDialog open={Boolean(deleting)} title="Delete monitoring record?" message="Are you sure you want to delete this ecotourism monitoring record? This action cannot be undone." confirmLabel="Delete" onCancel={() => setDeleting(null)} onConfirm={remove} />

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
