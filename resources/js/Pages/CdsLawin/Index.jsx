import { FloatingInput } from "@/Components/Form";import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';
import Tooltip from '../../Components/Tooltip';

const statusVariants = {
  'Approved': 'active',
  'Under Review': 'pending'
};

export default function Index({ monitorings = { data: [] }, filters = {} }) {
  const [search, setSearch] = useState(filters?.search || '');
  const [deleting, setDeleting] = useState(null);

  useEffect(() => setSearch(filters?.search || ''), [filters?.search]);


  const visit = (params) => router.get('/cds-lawin', { ...filters, search, ...params }, { preserveState: true, replace: true });
  const remove = () => router.delete(`/cds-lawin/${deleting.id}`, { onFinish: () => setDeleting(null) });

  const columns = [
  {
    key: 'patrol_area',
    label: 'Patrol Area / Protected Area',
    render: (item) => <span className="font-medium text-gray-900 dark:text-white">{item?.patrol_area}</span>
  },
  {
    key: 'patrol_date',
    label: 'Patrol Date',
    render: (item) => item?.patrol_date
  },
  {
    key: 'ecoregion',
    label: 'Ecoregion',
    render: (item) => item?.ecoregion || 'N/A'
  },
  {
    key: 'team_leader',
    label: 'Team Leader',
    render: (item) => item?.team_leader || 'N/A'
  },
  {
    key: 'team_members_count',
    label: 'Patrollers',
    render: (item) => `${item?.team_members_count || 0} pax`
  },
  {
    key: 'threats_observed',
    label: 'Threats Observed',
    render: (item) =>
    <Tooltip content={item?.threats_observed || 'None'}><div className="max-w-xs truncate text-xs">
                    {item?.threats_observed || <span className="text-gray-400 italic">No threats logged</span>}
                </div></Tooltip>

  },
  {
    key: 'status',
    label: 'Status',
    render: (item) => <StatusBadge variant={statusVariants[item?.status]}>{item?.status || 'Under Review'}</StatusBadge>
  },
  {
    key: 'attachment',
    label: 'Attachment',
    render: (item) => item?.attachment ?
    <div className="flex items-center gap-2">
                    <a href={`/view-file/${item.attachment}`} target="_blank" rel="noopener noreferrer" className="text-green-700 hover:text-green-900 dark:text-green-400 text-sm font-medium">
                        View
                    </a>
                    <span className="text-gray-300 dark:text-gray-600">|</span>
                    <a href={`/storage/${item.attachment}`} download className="text-blue-700 hover:text-blue-900 dark:text-blue-400 text-sm font-medium">
                        Download
                    </a>
                </div> :
    <span className="text-gray-400 text-xs italic">No Attachment</span>
  },
  {
    key: 'actions',
    label: <span className="sr-only">Actions</span>,
    cellClassName: 'text-right',
    render: (item) =>
    <div className="flex justify-end gap-3">
                    <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/cds-lawin/${item?.id}/edit`}>Edit</Link>
                    <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(item)}>Delete</button>
                </div>

  }];


  const safeRows = monitorings?.data || [];

  return (
    <AuthenticatedLayout title="CDS LAWIN Monitoring">
            <PageHeader
        title="CDS LAWIN Monitoring (Protected Area)"
        description="Manage and track patrol activities and threats detected within protected areas."
        actions={
        <Link href={route('cds-lawin.create')} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                        Record patrol activity
                    </Link>
        } />


            <Card className="mt-6" padding="p-0">
                <form onSubmit={(e) => {e.preventDefault();visit({ page: 1 });}} className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-3">
                    <div className="md:col-span-2">

            <FloatingInput id="index-search" label="Search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search area, ecoregion, team leader, or threats..." size="sm" />
                    </div>
                    <div className="flex items-end">
                        <button type="submit" className="w-full rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">Search Filter</button>
                    </div>
                </form>

                <DataTable columns={columns} rows={safeRows} emptyTitle="No patrol activities logged" emptyDescription="Input raw patrol reports or adjust your filters." />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {monitorings?.prev_page_url ? <Link href={monitorings.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link> : <span />}
                {monitorings?.next_page_url ? <Link href={monitorings.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link> : <span />}
            </div>

            <ConfirmDialog open={Boolean(deleting)} title="Delete patrol record?" message="Are you sure you want to delete this CDS LAWIN monitoring record? This action cannot be undone." confirmLabel="Delete" onCancel={() => setDeleting(null)} onConfirm={remove} />

        </AuthenticatedLayout>);

}
