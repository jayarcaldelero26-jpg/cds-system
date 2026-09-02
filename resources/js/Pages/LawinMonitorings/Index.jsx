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
  'Approved': 'active',
  'Under Review': 'pending'
};

export default function Index({ monitorings = { data: [] }, filters = {}, cenroList = [], statuses = [] }) {
  const [search, setSearch] = useState(filters?.search || '');
  const [deleting, setDeleting] = useState(null);

  useEffect(() => setSearch(filters?.search || ''), [filters?.search]);


  const visit = (params) => router.get('/lawin-monitorings', { ...filters, search, ...params }, { preserveState: true, replace: true });
  const remove = () => router.delete(`/lawin-monitorings/${deleting.id}`, { onFinish: () => setDeleting(null) });

  const columns = [
  {
    key: 'cenro',
    label: 'CENRO / Station',
    headerTooltip: 'CENRO means Community Environment and Natural Resources Office.',
    render: (item) => <span className="font-medium text-gray-900 dark:text-white">{item?.cenro}</span>
  },
  {
    key: 'patrol_date',
    label: 'Patrol Date',
    render: (item) => item?.patrol_date
  },
  {
    key: 'patrol_distance',
    label: 'Distance (km)',
    render: (item) => `${Number(item?.patrol_distance || 0).toFixed(2)} km`
  },
  {
    key: 'patrol_hours',
    label: 'Duration (hrs)',
    render: (item) => `${Number(item?.patrol_hours || 0).toFixed(1)} hrs`
  },
  {
    key: 'patrol_members_count',
    label: 'Patrollers',
    render: (item) => `${item?.patrol_members_count || 0} pax`
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
    render: (item) => <StatusBadge variant={statusVariants[item?.status]}>{item?.status}</StatusBadge>
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
      const { auth } = usePage().props;

      return (
        <div className="flex justify-end gap-3">
                        <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/lawin-monitorings/${item?.id}/edit`}>Edit</Link>

                        {auth?.canDeleteLawinMonitoring &&
          <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(item)}>Delete</button>
          }
                    </div>);

    }
  }];



  // 🚀 Fallback arrays para protected ka sa runtime nulls (Apil ang CENRO Mati!)
  const safeCenroList = Array.isArray(cenroList) && cenroList.length > 0 ?
  cenroList :
  ['CENRO Lupon', 'CENRO Mati', 'CENRO Manay', 'CENRO Baganga', 'PENRO Main Office'];

  const safeStatuses = Array.isArray(statuses) && statuses.length > 0 ?
  statuses :
  ['Under Review', 'Approved'];

  const safeRows = monitorings?.data || [];

  return (
    <AuthenticatedLayout title="LAWIN Monitoring">
            <PageHeader
        title="LAWIN Monitoring System"
        description="Manage and track patrol activities, distances covered, hours rendered, and threats detected by forest patrollers."
        actions={
        <Link href="/lawin-monitorings/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                        Record patrol activity
                    </Link>
        } />


            <Card className="mt-6" padding="p-0">
                <form onSubmit={(e) => {e.preventDefault();visit({ page: 1 });}} className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-4">
                    <div className="md:col-span-2">

            <FloatingInput variant="legacy" id="index-search" label="Search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search threats, remarks, or CENRO..." size="sm" />
                    </div>
                    <div>

            <FloatingSelect variant="legacy" id="index-cenro-station" label="CENRO / Station" value={filters?.cenro || ''} onChange={(e) => visit({ cenro: e.target.value, page: 1 })}>
                            <option value="">All CENRO / Stations</option>
                            {safeCenroList.map((cenro) => <option key={cenro} value={cenro}>{cenro}</option>)}
                        </FloatingSelect>
                    </div>
                    <div>

            <FloatingSelect variant="legacy" id="index-status" label="Status" value={filters?.status || ''} onChange={(e) => visit({ status: e.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {safeStatuses.map((st) => <option key={st} value={st}>{st}</option>)}
                        </FloatingSelect>
                    </div>
                    <div className="flex items-end md:col-span-4">
                        <button type="submit" className="w-full sm:w-auto rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">Search Filter</button>
                    </div>
                </form>

                <DataTable columns={columns} rows={safeRows} emptyTitle="No patrol activities logged" emptyDescription="Input raw patrol reports or adjust your filters." />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {monitorings?.prev_page_url ? <Link href={monitorings.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link> : <span />}
                {monitorings?.next_page_url ? <Link href={monitorings.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link> : <span />}
            </div>

            <ConfirmDialog open={Boolean(deleting)} title="Delete patrol record?" message="Are you sure you want to delete this LAWIN monitoring record? This action cannot be undone." confirmLabel="Delete" onCancel={() => setDeleting(null)} onConfirm={remove} />

        </AuthenticatedLayout>);

}
