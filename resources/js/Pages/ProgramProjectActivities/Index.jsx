import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

const statusVariants = {
    'Completed': 'active',
    'Ongoing': 'pending',
    'Proposed': 'warning',
    'Terminated': 'danger'
};

const messages = {
    'ppa-created': 'PPA record created successfully.',
    'ppa-updated': 'PPA record updated successfully.',
    'ppa-deleted': 'PPA record deleted successfully.'
};

export default function Index({ ppas, filters, protectedAreas, categories, statuses }) {
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

    const visit = (params) => router.get('/program-project-activities', { ...filters, search, ...params }, { preserveState: true, replace: true });
    const remove = () => router.delete(`/program-project-activities/${deleting.id}`, { onFinish: () => setDeleting(null) });

    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
    };

    const columns = [
        {
            key: 'title',
            label: 'Title / Name',
            render: (item) => (
                <div>
                    <span className="font-semibold text-gray-900 dark:text-white block">{item.title}</span>
                    <span className="text-[11px] text-gray-500 dark:text-gray-400">{item.protected_area_name}</span>
                </div>
            )
        },
        {
            key: 'category',
            label: 'Category',
            render: (item) => <span className="text-sm font-medium px-2 py-1 bg-gray-100 dark:bg-gray-800 rounded">{item.category}</span>
        },
        {
            key: 'budget',
            label: 'Budget',
            render: (item) => <span className="font-mono text-sm font-semibold">{formatCurrency(item.budget)}</span>
        },
        {
            key: 'source_of_fund',
            label: 'Source of Fund',
            render: (item) => item.source_of_fund || <span className="text-gray-400 italic">Unspecified</span>
        },
        {
            key: 'schedule',
            label: 'Schedule',
            render: (item) => item.start_date ? `${item.start_date} to ${item.end_date || 'Present'}` : <span className="text-gray-400 italic">Not set</span>
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
                </div>
            ) : <span className="text-gray-400 text-xs italic">No Attachment</span>
        },
        {
            key: 'actions',
            label: <span className="sr-only">Actions</span>,
            cellClassName: 'text-right',
            render: (item) => {
                // 🚀 DIREKTA NGA TAWGON ANG AUTH DINHI ARON WALAY ERROR O WHITE SCREEN!
                const { auth } = usePage().props;

                return (
                    <div className="flex justify-end gap-3">
                        <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/program-project-activities/${item.id}/edit`}>Edit</Link>

                        {/* 🛡️ SECURITY WRAPPER: CDS Admin ra gyud ang makakita sa Delete button */}
                        {auth?.canDeletePPA && (
                            <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(item)}>Delete</button>
                        )}
                    </div>
                );
            }
        }
    ];

    const selectClass = 'mt-1.5 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

    return (
        <AuthenticatedLayout title="PPA Management">
            <PageHeader
                title="Programs, Projects & Activities (PPAs)"
                description="Monitor physical development, implementation status, and capital outlays of conservation-led projects."
                actions={
                    <Link href="/program-project-activities/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                        Record new PPA
                    </Link>
                }
            />

            <Card className="mt-6" padding="p-0">
                <form onSubmit={(e) => { e.preventDefault(); visit({ page: 1 }); }} className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-4">
                    <label className="md:col-span-2">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Search PPAs</span>
                        <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search title, source of fund, remarks..." className={selectClass} />
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area</span>
                        <select className={selectClass} value={filters.protected_area_id || ''} onChange={(e) => visit({ protected_area_id: e.target.value, page: 1 })}>
                            <option value="">All protected areas</option>
                            {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Category</span>
                        <select className={selectClass} value={filters.category || ''} onChange={(e) => visit({ category: e.target.value, page: 1 })}>
                            <option value="">All categories</option>
                            {categories.map((cat) => <option key={cat} value={cat}>{cat}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Status</span>
                        <select className={selectClass} value={filters.status || ''} onChange={(e) => visit({ status: e.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {statuses.map((stat) => <option key={stat} value={stat}>{stat}</option>)}
                        </select>
                    </label>
                    <div className="flex items-end md:col-span-4">
                        <button type="submit" className="w-full sm:w-auto rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">Search Filter</button>
                    </div>
                </form>

                <DataTable columns={columns} rows={ppas.data} emptyTitle="No PPAs registered" emptyDescription="Create and register new activities or adjust your filters." />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {ppas.prev_page_url ? <Link href={ppas.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link> : <span />}
                {ppas.next_page_url ? <Link href={ppas.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link> : <span />}
            </div>

            <ConfirmDialog open={Boolean(deleting)} title="Delete PPA record?" message="Are you sure you want to delete this PPA monitoring record? This action cannot be undone." confirmLabel="Delete" onCancel={() => setDeleting(null)} onConfirm={remove} />

            {/* SUCCESS MODAL */}
            {showSuccess && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
                    <div className="bg-white dark:bg-gray-800 rounded-xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center">
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Success!</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">{messages[status]}</p>
                        <button type="button" onClick={() => setShowSuccess(false)} className="w-full inline-flex justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition">Okay</button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
