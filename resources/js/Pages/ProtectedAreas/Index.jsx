import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

const statusMessages = { 'protected-area-created': 'Protected area created successfully.', 'protected-area-updated': 'Protected area updated successfully.', 'protected-area-deleted': 'Protected area deleted successfully.' };
const variants = { Active: 'active', Inactive: 'inactive', Proposed: 'pending' };

export default function Index({ protectedAreas, filters }) {
    const { auth, status } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [protectedAreaToDelete, setProtectedAreaToDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);
    useEffect(() => setSearch(filters.search || ''), [filters.search]);
    const visit = (params) => router.get('/protected-areas', { search, sort: filters.sort, direction: filters.direction, ...params }, { preserveState: true, replace: true });
    const sortBy = (column) => visit({ sort: column, direction: filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc' });
    const sortableLabel = (label, key) => <button type="button" onClick={() => sortBy(key)} className="inline-flex items-center gap-1 hover:text-green-800 dark:hover:text-green-300">{label}<span aria-hidden="true">{filters.sort === key ? (filters.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button>;
    const deleteProtectedArea = () => { setDeleting(true); router.delete(`/protected-areas/${protectedAreaToDelete.id}`, { onFinish: () => { setDeleting(false); setProtectedAreaToDelete(null); } }); };
    const columns = [
        { key: 'name', label: sortableLabel('Protected Area', 'name'), render: (area) => <div><span className="font-medium text-gray-900 dark:text-white">{area.name}</span>{area.short_name && <span className="block text-xs text-gray-500 dark:text-gray-400">{area.short_name}</span>}</div> },
        { key: 'category', label: sortableLabel('Category', 'category') },
        { key: 'municipality', label: sortableLabel('Municipality', 'municipality') },
        { key: 'pamo', label: sortableLabel('PAMO', 'pamo'), render: (area) => area.pamo || <span className="text-gray-400">—</span> },
        { key: 'pasu', label: sortableLabel('PASu', 'pasu'), render: (area) => area.pasu || <span className="text-gray-400">—</span> },
        { key: 'status', label: sortableLabel('Status', 'status'), render: (area) => <StatusBadge variant={variants[area.status]}>{area.status}</StatusBadge> },
        { key: 'actions', label: <span className="sr-only">Actions</span>, cellClassName: 'text-right', render: (area) => <div className="flex justify-end gap-3">{auth.canUpdateProtectedAreas && <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/protected-areas/${area.id}/edit`}>Edit</Link>}{auth.canDeleteProtectedAreas && <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setProtectedAreaToDelete(area)}>Delete</button>}</div> },
    ];

    return <AuthenticatedLayout title="Protected Area Management"><PageHeader title="Protected Area Management" description="Master database of protected areas managed by DENR PENRO Mati." actions={auth.canCreateProtectedAreas && <Link href="/protected-areas/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Add protected area</Link>} />{statusMessages[status] && <p className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200" role="status">{statusMessages[status]}</p>}<Card className="mt-6" padding="p-0"><form onSubmit={(event) => { event.preventDefault(); visit({ page: 1 }); }} className="border-b border-gray-200 p-4 dark:border-gray-700 sm:flex sm:items-end sm:gap-3"><label className="block flex-1 text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor="protected-area-search">Search protected areas<input id="protected-area-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, municipality, category, or status" className="mt-1.5 block w-full rounded-ui border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white" /></label><button type="submit" className="mt-3 rounded-ui bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900 sm:mt-0">Search</button></form><DataTable columns={columns} rows={protectedAreas.data} emptyTitle="No protected areas found" emptyDescription="Add a protected area or refine your search." caption="Protected areas" /></Card><div className="mt-5 flex items-center justify-between text-sm">{protectedAreas.prev_page_url ? <Link href={protectedAreas.prev_page_url} className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">← Previous</Link> : <span />}{protectedAreas.next_page_url ? <Link href={protectedAreas.next_page_url} className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Next →</Link> : <span />}</div><ConfirmDialog open={Boolean(protectedAreaToDelete)} title="Delete protected area?" message={`Remove ${protectedAreaToDelete?.name} from the active protected area registry? This record can be restored from the database if needed.`} confirmLabel="Delete protected area" onCancel={() => setProtectedAreaToDelete(null)} onConfirm={deleteProtectedArea} processing={deleting} /></AuthenticatedLayout>;
}
