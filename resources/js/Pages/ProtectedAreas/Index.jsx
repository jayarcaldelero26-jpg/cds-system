import { FloatingInput } from "@/Components/Form";import { Icon } from '@iconify/react';import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';
import ProtectedAreaForm from './Form';

const variants = {
  Active: 'active',
  Inactive: 'inactive',
  Proposed: 'pending'
};

const formatNumber = (value) =>
value ?
Number(value).toLocaleString(undefined, {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
}) :
'—';

const displayValue = (value) => value || '—';

function DetailItem({ label, value, className = '' }) {
  return (
    <div className={className}>
            <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {label}
            </p>
            <p className="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">
                {displayValue(value)}
            </p>
        </div>);

}

export function ProtectedAreaDetailActions({ areaId, canEdit, canDelete, onDelete }) {
  return <div className="flex items-center gap-2">
    {canEdit && <Link href={`/protected-areas/${areaId}/edit`} className="cds-compact-action inline-flex min-w-[92px] items-center justify-center gap-1.5 rounded-lg font-semibold text-green-800 hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300 dark:hover:bg-green-950/30" data-cds-action="true" data-cds-action-variant="primary"><Icon icon="lucide:pencil" width="14" height="14" aria-hidden="true" /> Edit</Link>}
    {canDelete && <button type="button" onClick={onDelete} className="cds-compact-action inline-flex min-w-[92px] items-center justify-center gap-1.5 rounded-lg border border-red-200 bg-red-50 font-semibold text-red-700 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300" data-cds-action="true" data-cds-action-variant="danger"><Icon icon="lucide:trash-2" width="14" height="14" aria-hidden="true" /> Delete</button>}
  </div>;
}

export default function Index({ protectedAreas, filters, officeOptions = [] }) {
  const { auth } = usePage().props;

  const [search, setSearch] = useState(filters.search || '');
  const [selectedArea, setSelectedArea] = useState(null);
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [protectedAreaToDelete, setProtectedAreaToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const detailsDialogRef = useRef(null);
  const detailsOpenerRef = useRef(null);

  useEffect(() => setSearch(filters.search || ''), [filters.search]);
  useEffect(() => {
    if (search === (filters.search || '')) return undefined;
    const timer = window.setTimeout(() => {
      router.get('/protected-areas', { search: search || undefined, sort: filters.sort, direction: filters.direction, page: 1 }, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
    return () => window.clearTimeout(timer);
  }, [search]);


  const visit = (params) =>
  router.get(
    '/protected-areas',
    {
      search: search || undefined,
      sort: filters.sort,
      direction: filters.direction,
      ...params
    },
    {
      preserveState: true,
      preserveScroll: true,
      replace: true
    }
  );

  const sortBy = (column) =>
  visit({
    sort: column,
    direction:
    filters.sort === column && filters.direction === 'asc' ?
    'desc' :
    'asc'
  });

  const sortableLabel = (label, key) =>
  <button
    type="button"
    onClick={(event) => {
      event.stopPropagation();
      sortBy(key);
    }}
    className="cds-table-sort-control inline-flex items-center gap-1 font-semibold">

            {label}
            <span aria-hidden="true">
                <Icon icon={filters.sort === key ? (filters.direction === 'asc' ? 'lucide:arrow-up' : 'lucide:arrow-down') : 'lucide:arrow-up-down'} width="14" height="14" />
            </span>
        </button>;


  const openDetails = (area) => {
    detailsOpenerRef.current = document.activeElement;
    setSelectedArea(area);
    setDetailsOpen(true);
  };

  const closeDetails = () => {
    setDetailsOpen(false);
    setSelectedArea(null);
  };

  useEffect(() => {
    if (!detailsOpen) return undefined;
    const onKeyDown = event => { if (event.key === 'Escape') { event.preventDefault(); closeDetails(); } };
    document.addEventListener('keydown', onKeyDown);
    detailsDialogRef.current?.focus();
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      detailsOpenerRef.current?.focus?.();
      detailsOpenerRef.current = null;
    };
  }, [detailsOpen]);

  const deleteProtectedArea = () => {
    if (!protectedAreaToDelete) return;

    setDeleting(true);

    router.delete(`/protected-areas/${protectedAreaToDelete.id}`, {
      onSuccess: () => {
        setProtectedAreaToDelete(null);
        setDetailsOpen(false);
        setSelectedArea(null);
      },
      onFinish: () => setDeleting(false)
    });
  };

  const clickableCell = (area, content, className = '') =>
  <div
    role="button"
    tabIndex={0}
    onClick={() => openDetails(area)}
    onKeyDown={(event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openDetails(area);
      }
    }}
    className={`cursor-pointer outline-none transition hover:text-green-800 focus-visible:rounded-md focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:text-green-300 ${className}`}>

            {content}
        </div>;


  const columns = [
  {
    key: 'name',
    ariaSort: filters.sort === 'name' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('Protected Area', 'name'),
    render: (area) =>
    clickableCell(
      area,
      <div className="min-w-[220px] py-0.5">
                        <span className="font-semibold text-gray-900 dark:text-white">
                            {area.name}
                        </span>

                        {area.short_name &&
        <span className="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                {area.short_name}
                            </span>
        }

                        <span className="mt-1 block text-[10px] font-medium uppercase tracking-wide text-gray-400 opacity-0 transition group-hover:opacity-100">
                            Click to view full details
                        </span>
                    </div>
    )
  },
  {
    key: 'category',
    ariaSort: filters.sort === 'category' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('Category', 'category'),
    render: (area) =>
    clickableCell(
      area,
      <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {displayValue(area.category)}
                    </span>
    )
  },
  {
    key: 'municipality',
    ariaSort: filters.sort === 'municipality' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('Municipality', 'municipality'),
    render: (area) =>
    clickableCell(
      area,
      <div className="max-w-[250px] text-gray-700 dark:text-gray-300">
                        {displayValue(area.municipality)}
                    </div>
    )
  },
  {
    key: 'area_hectares',
    ariaSort: filters.sort === 'area_hectares' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('Total Area (ha)', 'area_hectares'),
    render: (area) =>
    clickableCell(
      area,
      <span className="whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                        {area.area_hectares ?
        `${formatNumber(area.area_hectares)} ha` :
        '—'}
                    </span>
    )
  },
  {
    key: 'core_zone_hectares',
    label: 'Core Zone (ha)',
    render: (area) =>
    clickableCell(
      area,
      <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {area.core_zone_hectares ?
        `${formatNumber(area.core_zone_hectares)} ha` :
        '—'}
                    </span>
    )
  },
  {
    key: 'buffer_zone_hectares',
    label: 'Buffer Zone (ha)',
    render: (area) =>
    clickableCell(
      area,
      <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {area.buffer_zone_hectares ?
        `${formatNumber(area.buffer_zone_hectares)} ha` :
        '—'}
                    </span>
    )
  },
  {
    key: 'pamo',
    ariaSort: filters.sort === 'pamo' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('PAMO', 'pamo'),
    render: (area) =>
    clickableCell(
      area,
      <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {displayValue(area.pamo)}
                    </span>
    )
  },
  {
    key: 'pasu',
    ariaSort: filters.sort === 'pasu' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('PASu', 'pasu'),
    render: (area) =>
    clickableCell(
      area,
      <span className="max-w-[220px] text-gray-700 dark:text-gray-300">
                        {displayValue(area.pasu)}
                    </span>
    )
  },
  {
    key: 'status',
    ariaSort: filters.sort === 'status' ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none',
    label: sortableLabel('Status', 'status'),
    render: (area) =>
    clickableCell(
      area,
      <StatusBadge variant={variants[area.status]}>
                        {area.status}
                    </StatusBadge>
    )
  }];


  return (
    <AuthenticatedLayout title="Protected Area Management">
            <style>{`
                @keyframes popIn {
                    0% {
                        transform: scale(0.96) translateY(6px);
                        opacity: 0;
                    }
                    100% {
                        transform: scale(1) translateY(0);
                        opacity: 1;
                    }
                }

                .protected-area-modal {
                    animation: popIn 0.2s ease-out forwards;
                }

                .protected-area-scrollbar::-webkit-scrollbar {
                    width: 7px;
                    height: 7px;
                }

                .protected-area-scrollbar::-webkit-scrollbar-track {
                    background: transparent;
                }

                .protected-area-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(156, 163, 175, 0.55);
                    border-radius: 999px;
                }

            `}</style>

            <PageHeader
        title="Protected Area Management"
        description="Master database of protected areas managed by DENR PENRO Davao Oriental."
        />

            {auth.canCreateProtectedAreas && <div className="cds-action-row">
                <button type="button" onClick={() => setCreateOpen(true)} className="cds-page-action inline-flex items-center justify-center text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2" data-cds-action="true" data-cds-action-variant="primary">Add protected area</button>
            </div>}


            <Card
        className="mt-6 overflow-hidden border border-gray-200 shadow-sm dark:border-gray-800"
        padding="p-0">

                <div className="border-b border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40">

                    <div
            className="block flex-1 text-sm font-medium text-gray-700 dark:text-gray-200">



            <FloatingInput label="Search protected areas"
            id="protected-area-search"
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Name, municipality, category, or status" size="sm" />


                    </div>

                </div>

                <div className="border-b border-gray-100 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-bold text-gray-900 dark:text-white">
                                Protected Area Registry
                            </h2>
                            <p className="mt-0.5 text-xs text-green-700 dark:text-green-400">
                                💡 Click a record to view full details
                            </p>
                        </div>

                        <div className="hidden rounded-full bg-green-50 px-3 py-1.5 text-[11px] font-semibold text-green-700 dark:bg-green-950/40 dark:text-green-300 sm:block">
                            {protectedAreas.total ?? protectedAreas.data.length} record(s)
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <DataTable
            columns={columns}
            rows={protectedAreas.data}
            emptyTitle="No protected areas found"
            emptyDescription="Add a protected area or refine your search."
            caption="Protected areas" />

                </div>
            </Card>

            <div className="cds-pagination mt-5 flex items-center justify-between text-sm">
                {protectedAreas.prev_page_url ?
        <Link
          href={protectedAreas.prev_page_url}
          className="rounded-lg px-3 py-2 font-semibold text-green-800 transition hover:bg-green-50 hover:text-green-950 dark:text-green-400 dark:hover:bg-green-950/30">

                        ← Previous
                    </Link> :

        <span />
        }

                {protectedAreas.next_page_url ?
        <Link
          href={protectedAreas.next_page_url}
          className="rounded-lg px-3 py-2 font-semibold text-green-800 transition hover:bg-green-50 hover:text-green-950 dark:text-green-400 dark:hover:bg-green-950/30">

                        Next →
                    </Link> :

        <span />
        }
            </div>

            {/* FULL DETAILS MODAL */}
            {detailsOpen && selectedArea &&
      <div
        className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-[2px]"
        onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeDetails();
        }}>

                    <div ref={detailsDialogRef} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="protected-area-details-title" className="protected-area-modal relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl focus:outline-none dark:border-gray-800 dark:bg-gray-900">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between border-b border-gray-100 bg-gray-50/80 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40">
                            <div className="flex min-w-0 items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-green-100 text-lg text-green-700 dark:bg-green-950 dark:text-green-400">
                                    🌿
                                </div>

                                <div className="min-w-0">
                                    <h3 id="protected-area-details-title" className="truncate text-base font-bold text-gray-900 dark:text-white">
                                        Protected Area Full Details
                                    </h3>
                                    <p className="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {selectedArea.name || 'N/A'}
                                        {selectedArea.short_name ?
                  ` — ${selectedArea.short_name}` :
                  ''}
                                    </p>
                                </div>
                            </div>

                        </div>

                        {/* Modal Body */}
                        <div className="protected-area-scrollbar flex-1 space-y-5 overflow-y-auto p-5 sm:p-6">
                            {/* Summary Cards */}
                            <div className="cds-card-surface grid grid-cols-2 gap-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 sm:grid-cols-4">
                                <DetailItem
                label="Category"
                value={selectedArea.category} />


                                <DetailItem
                label="Year Established"
                value={selectedArea.year_established} />


                                <div>
                                    <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Status
                                    </p>

                                    <div className="mt-1">
                                        <StatusBadge
                    variant={variants[selectedArea.status]}>

                                            {selectedArea.status}
                                        </StatusBadge>
                                    </div>
                                </div>

                                <DetailItem
                label="Total Area"
                value={
                selectedArea.area_hectares ?
                `${formatNumber(
                  selectedArea.area_hectares
                )} ha` :
                null
                } />

                            </div>

                            {/* LOCATION */}
                            <section>
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-sm">📍</span>
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        Location & Geographic Coverage
                                    </h4>
                                </div>

                                <div className="cds-card-surface rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                                    <div className="mb-4 rounded-xl border border-green-200 bg-green-50/70 p-3 dark:border-green-900 dark:bg-green-950/30">
                                        <p className="text-[11px] font-medium uppercase tracking-wide text-green-700 dark:text-green-400">
                                            Region
                                        </p>
                                        <p className="mt-1 text-sm font-bold text-gray-900 dark:text-white">
                                            {displayValue(selectedArea.region)}
                                        </p>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <DetailItem
                    label="Province"
                    value={selectedArea.province} />


                                        <DetailItem
                    label="Municipality / City"
                    value={selectedArea.municipality} />

                                    </div>
                                </div>
                            </section>

                            {/* ZONATION */}
                            <section>
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-sm">🗺️</span>
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        Zonation & Area Indicators
                                    </h4>
                                </div>

                                <div className="cds-card-surface rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <DetailItem
                    label="Core Zone"
                    value={
                    selectedArea.core_zone_hectares ?
                    `${formatNumber(
                      selectedArea.core_zone_hectares
                    )} ha` :
                    null
                    } />


                                        <DetailItem
                    label="Buffer Zone"
                    value={
                    selectedArea.buffer_zone_hectares ?
                    `${formatNumber(
                      selectedArea.buffer_zone_hectares
                    )} ha` :
                    null
                    } />


                                        <div>
                                            <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                Total Area
                                            </p>
                                            <p className="mt-1 text-sm font-bold text-green-700 dark:text-green-400">
                                                {selectedArea.area_hectares ?
                      `${formatNumber(
                        selectedArea.area_hectares
                      )} ha` :
                      '—'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {/* MANAGEMENT */}
                            <section>
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-sm">🏢</span>
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        Management & Administration
                                    </h4>
                                </div>

                                <div className="cds-card-surface grid gap-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 sm:grid-cols-2">
                                    <DetailItem
                  label="PAMO"
                  value={selectedArea.pamo} />


                                    <DetailItem
                  label="PASu"
                  value={selectedArea.pasu} />


                                    <DetailItem
                  label="Legal Basis"
                  value={selectedArea.legal_basis}
                  className="sm:col-span-2" />

                                </div>
                            </section>

                            {/* DESCRIPTION */}
                            <section>
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-sm">📝</span>
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        Description & Remarks
                                    </h4>
                                </div>

                                <div className="space-y-3">
                                    <div className="cds-card-surface rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                                        <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Description
                                        </p>
                                        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-800 dark:text-gray-200">
                                            {selectedArea.description ||
                    'No description provided.'}
                                        </p>
                                    </div>

                                    <div className="cds-card-surface rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                                        <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Remarks
                                        </p>
                                        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-800 dark:text-gray-200">
                                            {selectedArea.remarks || 'No remarks.'}
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </div>

                        {/* Modal Footer */}
                        <div className="flex flex-col gap-3 border-t border-gray-100 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-800/40 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <ProtectedAreaDetailActions areaId={selectedArea.id} canEdit={auth.canUpdateProtectedAreas} canDelete={auth.canDeleteProtectedAreas} onDelete={() => setProtectedAreaToDelete(selectedArea)} />

                            <button
              type="button"
              onClick={closeDetails}
              className="inline-flex items-center justify-center rounded-xl bg-green-800 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-green-900" data-cds-action="true" data-cds-action-variant="primary">

                                Close Details
                            </button>
                        </div>
                    </div>
                </div>
      }

            <ConfirmDialog
        open={Boolean(protectedAreaToDelete)}
        variant="danger"
        title="Delete protected area?"
        message={`Remove ${protectedAreaToDelete?.name} from the active protected area registry? This record can be restored from the database if needed.`}
        confirmLabel="Delete"
        onCancel={() => setProtectedAreaToDelete(null)}
        onConfirm={deleteProtectedArea}
        processing={deleting} />

            {createOpen && <ProtectedAreaForm modal onClose={() => setCreateOpen(false)} title="Add Protected Area" officeOptions={officeOptions} />}

        </AuthenticatedLayout>);

}
