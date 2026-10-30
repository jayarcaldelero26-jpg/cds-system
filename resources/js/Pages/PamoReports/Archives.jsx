import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput } from '@/Components/Form';
import CrudTable from '@/Components/Crud/CrudTable';
import LaravelPaginationLinks from '@/Components/Crud/LaravelPaginationLinks';
import { formatManila } from '@/Utils/dateFormatters';

export default function Archives({ archives = {}, assignedProtectedArea, filters = {} }) {
    const [search, setSearch] = useState(filters.search || '');
    const rows = archives.data || [];

    useEffect(() => {
        setSearch(filters.search || '');
    }, [filters.search]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams((window.location.search || '').slice(1));
            if ((params.get('search') || '') === search.trim()) return;
            router.get(route('pamo-archives.index'), search.trim() ? { search: search.trim() } : {}, {
                preserveState: true, preserveScroll: true, replace: true, only: ['archives', 'filters'],
            });
        }, 220);
        return () => window.clearTimeout(timer);
    }, [search]);

    const columns = [
        { key: 'report', label: 'Report / Activity', render: row => <div><p className="font-semibold text-slate-900 dark:text-white">{row.report}</p>{row.activity_name && <p className="mt-0.5 text-xs text-slate-500">{row.activity_name}{row.document_type ? ` · ${row.document_type}` : ''}</p>}</div> },
        { key: 'reporting_period', label: 'Reporting Period', render: row => row.reporting_period || 'Not provided' },
        { key: 'archived_at', label: 'Archive Checkpoint', render: row => formatManila(row.archived_at) },
        { key: 'status', label: 'Availability', render: row => <span className="text-xs text-slate-600 dark:text-slate-300">{row.status}</span> },
        { key: 'actions', label: 'Actions', render: row => <a className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-xs font-semibold text-emerald-800 hover:bg-slate-50 dark:border-slate-600 dark:text-emerald-300 dark:hover:bg-slate-800" href={row.file_url}>Open protected copy</a> },
    ];

    return <AuthenticatedLayout title="Archives">
        <PageHeader title="Archives" description={`Retained official report copies for ${assignedProtectedArea?.name || 'your assigned Protected Area'}, available within your authorized scope. Availability is checked when a copy is opened.`} />
        <div className="mt-5">
            <FilterToolbar chips={[]} onClear={() => setSearch('')}>
                <FloatingInput id="pamo-archive-search" label="Search report, activity, period, or file" value={search} onChange={event => setSearch(event.target.value)} />
            </FilterToolbar>
        </div>
        <div className="mt-4">
            <CrudTable compactEmpty preserveFrameWhenEmpty title="Archived report copies" subtitle={`${archives.total ?? rows.length} retained ${(archives.total ?? rows.length) === 1 ? 'copy' : 'copies'}`} caption="PAMO protected archive copies" columns={columns} rows={rows} rowKey="id" emptyTitle="No archived copies" emptyDescription="Only copies recorded by the existing archive checkpoint appear here." pagination={archives.links?.length > 3 ? <LaravelPaginationLinks links={archives.links} /> : null} />
        </div>
    </AuthenticatedLayout>;
}
