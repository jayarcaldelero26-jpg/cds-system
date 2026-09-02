import { FloatingInput, FloatingSelect } from "@/Components/Form";import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';
import Tooltip from '../../Components/Tooltip';

const statusVariants = {
  'Resolved': 'active', // Green
  'Ongoing': 'pending', // Yellow
  'Pending': 'inactive' // Red
};

export default function Index({ issues, filters, protectedAreas, statuses }) {
  const [search, setSearch] = useState(filters.search || '');
  const [deleting, setDeleting] = useState(null);

  useEffect(() => setSearch(filters.search || ''), [filters.search]);

  const visit = (params) => router.get('/issue-monitorings', { ...filters, search, ...params }, { preserveState: true, replace: true });
  const remove = () => router.delete(`/issue-monitorings/${deleting.id}`, { onFinish: () => setDeleting(null) });

  const columns = [
  {
    key: 'protected_area_name',
    label: 'Protected Area/PAMO',
    render: (item) => <span className="font-medium text-gray-900 dark:text-white">{item.protected_area_name}</span>
  },
  {
    key: 'issue_description',
    label: 'Issue / Concern',
    render: (item) =>
    <Tooltip content={item.issue_description}><div className="max-w-xs truncate">
                    {item.issue_description}
                </div></Tooltip>

  },
  {
    key: 'date_observed',
    label: 'Date Observed',
    render: (item) => item.date_observed
  },
  {
    key: 'status',
    label: 'Status',
    render: (item) => <StatusBadge variant={statusVariants[item.status]}>{item.status}</StatusBadge>
  },
  {
    key: 'attachment',
    label: 'Attachment',
    render: () => <span className="text-gray-400 text-xs italic">Attachment unavailable</span>
  },
  {
    key: 'actions',
    label: <span className="sr-only">Actions</span>,
    cellClassName: 'text-right',
    render: (item) => {
      // 🚀 GI-FIX: Diri nato tawgon ang auth para luwas sa reference error ug dili mo-white screen!
      const { auth } = usePage().props;

      return (
        <div className="flex justify-end gap-3">
                        <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/issue-monitorings/${item.id}/edit`}>Edit</Link>

                        {/* 🛡️ SECURITY WRAPPER: CDS Admin ra gyud ang makakita sa Delete button */}
                        {auth?.canDeleteIssueMonitoring &&
          <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(item)}>Delete</button>
          }
                    </div>);

    }
  }];



  return (
    <AuthenticatedLayout title="Issues Monitoring">
            <PageHeader
        title="Issues Monitoring"
        description="Track, assess, and monitor findings, recommendations, and actions taken on environmental and administrative issues."
        actions={
        <Link href="/issue-monitorings/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                        Record new issue
                    </Link>
        } />


            <Card className="mt-6" padding="p-0">
                <form onSubmit={(e) => {e.preventDefault();visit({ page: 1 });}} className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-4">
                    <div className="md:col-span-2">

            <FloatingInput variant="legacy" id="index-search" label="Search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search issues, findings, or PAMOs..." size="sm" />
                    </div>
                    <div>

            <FloatingSelect variant="legacy" id="index-protected-area" label="Protected Area" value={filters.protected_area_id || ''} onChange={(e) => visit({ protected_area_id: e.target.value, page: 1 })}>
                            <option value="">All protected areas</option>
                            {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </FloatingSelect>
                    </div>
                    <div>

            <FloatingSelect variant="legacy" id="index-status" label="Status" value={filters.status || ''} onChange={(e) => visit({ status: e.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {statuses.map((status) => <option key={status} value={status}>{status}</option>)}
                        </FloatingSelect>
                    </div>
                    <div className="flex items-end md:col-span-4">
                        <button type="submit" className="w-full sm:w-auto rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">Search Filter</button>
                    </div>
                </form>

                <DataTable columns={columns} rows={issues.data} emptyTitle="No issues tracked" emptyDescription="Log issues and concerns from PAMOs to begin monitoring." />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {issues.prev_page_url ? <Link href={issues.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link> : <span />}
                {issues.next_page_url ? <Link href={issues.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link> : <span />}
            </div>

            <ConfirmDialog open={Boolean(deleting)} title="Delete issue record?" message="Are you sure you want to delete this issue record? This action cannot be undone." confirmLabel="Delete" onCancel={() => setDeleting(null)} onConfirm={remove} />

        </AuthenticatedLayout>);

}
