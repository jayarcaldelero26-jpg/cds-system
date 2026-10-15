import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput } from '@/Components/Form';
import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';
import Button from '@/Components/Button';
import CrudTable from '@/Components/Crud/CrudTable';
import LaravelPaginationLinks from '@/Components/Crud/LaravelPaginationLinks';
import PamoPambDraftWorkspace from '@/Components/PamoPambDraftWorkspace';
import { formatManila } from '@/Utils/dateFormatters';

export default function Index({ workflows = [], rows = {}, assignedProtectedArea, pamoContext, filters = {} }) {
    const draftWorkspace = useRef(null);
    const [search, setSearch] = useState(filters.search || '');
    const [workflowFilter, setWorkflowFilter] = useState(filters.workflow || '');
    const entries = rows.data || [];

    useEffect(() => {
        setSearch(filters.search || '');
        setWorkflowFilter(filters.workflow || '');
    }, [filters.search, filters.workflow]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            const params = new URLSearchParams((window.location.search || '').slice(1));
            if ((params.get('search') || '') === search.trim() && (params.get('workflow') || '') === workflowFilter) return;
            router.get(route('pamo-reports.index'), {
                search: search.trim() || undefined,
                workflow: workflowFilter || undefined,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['rows', 'filters'],
            });
        }, 220);
        return () => window.clearTimeout(timer);
    }, [search, workflowFilter]);

    const columns = [
        {
            key: 'workflow_label',
            label: 'Report / Activity',
            render: row => <div>
                <p className="font-semibold text-slate-900 dark:text-white">{row.workflow_label}</p>
                {row.activity_name && <p className="mt-0.5 text-xs text-slate-500">{row.activity_name}{row.document_type ? ' · ' + row.document_type : ''}</p>}
            </div>,
        },
        { key: 'started_at', label: 'Date Created', render: row => formatManila(row.started_at) },
        { key: 'updated_at', label: 'Last Updated', render: row => formatManila(row.updated_at) },
        {
            key: 'status',
            label: 'Status',
            render: () => <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950/50 dark:text-amber-200">Draft</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: row => <div className="flex flex-wrap gap-2" onClick={event => event.stopPropagation()}>
                <Button type="button" size="compact" variant="primary" onClick={() => draftWorkspace.current?.submitDraft(row)} className="shrink-0 rounded-xl px-4 py-2 text-xs">Submit Report</Button>
            </div>,
        },
    ];

    const clearFilters = () => {
        setSearch('');
        setWorkflowFilter('');
    };
    const selectedWorkflow = workflows.find(item => item.key === workflowFilter);
    const chips = [
        ...(search.trim() ? [{ key: 'search', label: 'Search', value: search.trim(), onRemove: () => setSearch('') }] : []),
        ...(selectedWorkflow ? [{ key: 'workflow', label: 'Report', value: selectedWorkflow.label, onRemove: () => setWorkflowFilter('') }] : []),
    ];
    const workflowOptions = [
        { id: '', label: 'All report modules' },
        ...workflows.map(item => ({ id: item.key, label: item.label })),
    ];

    return <AuthenticatedLayout title="Reports">
        <PageHeader title="Reports" description={'Private saved drafts for ' + (assignedProtectedArea?.name || 'your assigned Protected Area') + '.'} />
        <div className="mt-5 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
            <div className="min-w-0">
                <FloatingInput id="pamo-reports-search" label="Search drafts" value={search} onChange={event => setSearch(event.target.value)} />
            </div>
            <FilterToolbar label="Filters" className="w-full sm:w-auto" triggerClassName="!h-11 !rounded-xl" chips={chips} onClear={clearFilters}>
                <ScopedOptionSelect
                    id="pamo-reports-workflow-filter"
                    label="Report module"
                    value={workflowFilter}
                    options={workflowOptions}
                    onChange={setWorkflowFilter}
                    placeholder="Search report modules"
                />
            </FilterToolbar>
            <Button type="button" size="default" variant="primary" className="h-11 w-full shrink-0 rounded-xl px-5 sm:w-auto" onClick={() => draftWorkspace.current?.startNew()}>＋ New draft</Button>
        </div>
        <div className="mt-4">
            <CrudTable
                compactEmpty
                preserveFrameWhenEmpty
                title="Saved drafts"
                subtitle={(rows.total ?? entries.length) + ' private draft' + ((rows.total ?? entries.length) === 1 ? '' : 's')}
                caption="PAMO saved report drafts"
                columns={columns}
                rows={entries}
                rowKey="id"
                onRowClick={row => draftWorkspace.current?.openDraft(row)}
                emptyTitle="No draft reports"
                emptyDescription="Choose New draft when you are ready to prepare a report."
                pagination={rows.links?.length > 3 ? <LaravelPaginationLinks links={rows.links} /> : null}
            />
        </div>
        <PamoPambDraftWorkspace ref={draftWorkspace} workflow={workflows[0]} workflows={workflows} drafts={entries} assignedProtectedArea={assignedProtectedArea} pamoContext={pamoContext} />
    </AuthenticatedLayout>;
}
