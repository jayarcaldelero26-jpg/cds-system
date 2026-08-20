import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

const statusMessages = {
    'protected-area-created': 'Protected area created successfully.',
    'protected-area-updated': 'Protected area updated successfully.',
    'protected-area-deleted': 'Protected area deleted successfully.',
};

const variants = {
    Active: 'active',
    Inactive: 'inactive',
    Proposed: 'pending',
};

const formatNumber = (value) =>
    value
        ? Number(value).toLocaleString(undefined, {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })
        : '—';

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
        </div>
    );
}

export default function Index({ protectedAreas, filters }) {
    const { auth, status } = usePage().props;

    const [search, setSearch] = useState(filters.search || '');
    const [selectedArea, setSelectedArea] = useState(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [protectedAreaToDelete, setProtectedAreaToDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);
    const [successMessage, setSuccessMessage] = useState('');

    useEffect(() => setSearch(filters.search || ''), [filters.search]);

    useEffect(() => {
        if (!statusMessages[status]) {
            return;
        }

        setSuccessMessage(statusMessages[status]);
        setShowSuccess(true);
    }, [status]);

    const visit = (params) =>
        router.get(
            '/protected-areas',
            {
                search,
                sort: filters.sort,
                direction: filters.direction,
                ...params,
            },
            {
                preserveState: true,
                replace: true,
            }
        );

    const sortBy = (column) =>
        visit({
            sort: column,
            direction:
                filters.sort === column && filters.direction === 'asc'
                    ? 'desc'
                    : 'asc',
        });

    const sortableLabel = (label, key) => (
        <button
            type="button"
            onClick={(event) => {
                event.stopPropagation();
                sortBy(key);
            }}
            className="inline-flex items-center gap-1 font-semibold transition hover:text-green-100"
        >
            {label}
            <span aria-hidden="true">
                {filters.sort === key
                    ? filters.direction === 'asc'
                        ? '↑'
                        : '↓'
                    : '↕'}
            </span>
        </button>
    );

    const openDetails = (area) => {
        setSelectedArea(area);
        setDetailsOpen(true);
    };

    const closeDetails = () => {
        setDetailsOpen(false);
        setSelectedArea(null);
    };

    const deleteProtectedArea = () => {
        if (!protectedAreaToDelete) return;

        setDeleting(true);

        router.delete(`/protected-areas/${protectedAreaToDelete.id}`, {
            onSuccess: () => {
                setProtectedAreaToDelete(null);
                setDetailsOpen(false);
                setSelectedArea(null);
            },
            onFinish: () => setDeleting(false),
        });
    };

    const clickableCell = (area, content, className = '') => (
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
            className={`cursor-pointer outline-none transition hover:text-green-800 focus-visible:rounded-md focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:text-green-300 ${className}`}
        >
            {content}
        </div>
    );

    const columns = [
        {
            key: 'name',
            label: sortableLabel('Protected Area', 'name'),
            render: (area) =>
                clickableCell(
                    area,
                    <div className="min-w-[220px] py-0.5">
                        <span className="font-semibold text-gray-900 dark:text-white">
                            {area.name}
                        </span>

                        {area.short_name && (
                            <span className="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                {area.short_name}
                            </span>
                        )}

                        <span className="mt-1 block text-[10px] font-medium uppercase tracking-wide text-gray-400 opacity-0 transition group-hover:opacity-100">
                            Click to view full details
                        </span>
                    </div>
                ),
        },
        {
            key: 'category',
            label: sortableLabel('Category', 'category'),
            render: (area) =>
                clickableCell(
                    area,
                    <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {displayValue(area.category)}
                    </span>
                ),
        },
        {
            key: 'municipality',
            label: sortableLabel('Municipality', 'municipality'),
            render: (area) =>
                clickableCell(
                    area,
                    <div className="max-w-[250px] text-gray-700 dark:text-gray-300">
                        {displayValue(area.municipality)}
                    </div>
                ),
        },
        {
            key: 'area_hectares',
            label: sortableLabel('Total Area (ha)', 'area_hectares'),
            render: (area) =>
                clickableCell(
                    area,
                    <span className="whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                        {area.area_hectares
                            ? `${formatNumber(area.area_hectares)} ha`
                            : '—'}
                    </span>
                ),
        },
        {
            key: 'core_zone_hectares',
            label: 'Core Zone (ha)',
            render: (area) =>
                clickableCell(
                    area,
                    <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {area.core_zone_hectares
                            ? `${formatNumber(area.core_zone_hectares)} ha`
                            : '—'}
                    </span>
                ),
        },
        {
            key: 'buffer_zone_hectares',
            label: 'Buffer Zone (ha)',
            render: (area) =>
                clickableCell(
                    area,
                    <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {area.buffer_zone_hectares
                            ? `${formatNumber(area.buffer_zone_hectares)} ha`
                            : '—'}
                    </span>
                ),
        },
        {
            key: 'pamo',
            label: sortableLabel('PAMO', 'pamo'),
            render: (area) =>
                clickableCell(
                    area,
                    <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">
                        {displayValue(area.pamo)}
                    </span>
                ),
        },
        {
            key: 'pasu',
            label: sortableLabel('PASu', 'pasu'),
            render: (area) =>
                clickableCell(
                    area,
                    <span className="max-w-[220px] text-gray-700 dark:text-gray-300">
                        {displayValue(area.pasu)}
                    </span>
                ),
        },
        {
            key: 'status',
            label: sortableLabel('Status', 'status'),
            render: (area) =>
                clickableCell(
                    area,
                    <StatusBadge variant={variants[area.status]}>
                        {area.status}
                    </StatusBadge>
                ),
        },
    ];

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

                @keyframes checkmarkDraw {
                    0% {
                        stroke-dashoffset: 30;
                    }
                    100% {
                        stroke-dashoffset: 0;
                    }
                }

                .checkmark-check {
                    stroke-dasharray: 30;
                    stroke-dashoffset: 30;
                    animation: checkmarkDraw 0.45s ease-out 0.15s forwards;
                }
            `}</style>

            <PageHeader
                title="Protected Area Management"
                description="Master database of protected areas managed by DENR PENRO Mati."
                actions={
                    auth.canCreateProtectedAreas && (
                        <Link
                            href="/protected-areas/create"
                            className="inline-flex items-center justify-center rounded-xl bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2"
                        >
                            Add protected area
                        </Link>
                    )
                }
            />

            <Card
                className="mt-6 overflow-hidden border border-gray-200 shadow-sm dark:border-gray-800"
                padding="p-0"
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit({ page: 1 });
                    }}
                    className="border-b border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40 sm:flex sm:items-end sm:gap-3"
                >
                    <label
                        className="block flex-1 text-sm font-medium text-gray-700 dark:text-gray-200"
                        htmlFor="protected-area-search"
                    >
                        Search protected areas
                        <input
                            id="protected-area-search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, municipality, category, or status"
                            className="mt-1.5 block w-full rounded-xl border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm transition focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                        />
                    </label>

                    <button
                        type="submit"
                        className="mt-3 rounded-xl bg-green-800 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900 sm:mt-0"
                    >
                        Search
                    </button>
                </form>

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
                        caption="Protected areas"
                    />
                </div>
            </Card>

            <div className="mt-5 flex items-center justify-between text-sm">
                {protectedAreas.prev_page_url ? (
                    <Link
                        href={protectedAreas.prev_page_url}
                        className="rounded-lg px-3 py-2 font-semibold text-green-800 transition hover:bg-green-50 hover:text-green-950 dark:text-green-400 dark:hover:bg-green-950/30"
                    >
                        ← Previous
                    </Link>
                ) : (
                    <span />
                )}

                {protectedAreas.next_page_url ? (
                    <Link
                        href={protectedAreas.next_page_url}
                        className="rounded-lg px-3 py-2 font-semibold text-green-800 transition hover:bg-green-50 hover:text-green-950 dark:text-green-400 dark:hover:bg-green-950/30"
                    >
                        Next →
                    </Link>
                ) : (
                    <span />
                )}
            </div>

            {/* SUCCESS MODAL */}
            {showSuccess && (
                <div
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-black/55 p-4 backdrop-blur-[2px]"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="protected-area-success-title"
                >
                    <div className="animate-pop-in w-full max-w-sm rounded-2xl border border-emerald-100 bg-white p-6 text-center shadow-2xl dark:border-emerald-900 dark:bg-gray-800">
                        <div className="checkmark-circle mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 shadow-sm dark:bg-emerald-950">
                            <svg
                                className="h-8 w-8 text-emerald-600 dark:text-emerald-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth="3"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    className="checkmark-check"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                        </div>

                        <h3
                            id="protected-area-success-title"
                            className="mb-2 text-lg font-bold text-gray-900 dark:text-white"
                        >
                            Success!
                        </h3>

                        <p className="mb-6 text-sm text-gray-600 dark:text-gray-300">
                            {successMessage}
                        </p>

                        <button
                            type="button"
                            onClick={() => setShowSuccess(false)}
                            className="w-full rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700"
                        >
                            Continue
                        </button>
                    </div>
                </div>
            )}

            {/* FULL DETAILS MODAL */}
            {detailsOpen && selectedArea && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-[2px]"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) closeDetails();
                    }}
                >
                    <div className="protected-area-modal relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between border-b border-gray-100 bg-gray-50/80 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40">
                            <div className="flex min-w-0 items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-green-100 text-lg text-green-700 dark:bg-green-950 dark:text-green-400">
                                    🌿
                                </div>

                                <div className="min-w-0">
                                    <h3 className="truncate text-base font-bold text-gray-900 dark:text-white">
                                        Protected Area Full Details
                                    </h3>
                                    <p className="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {selectedArea.name || 'N/A'}
                                        {selectedArea.short_name
                                            ? ` — ${selectedArea.short_name}`
                                            : ''}
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={closeDetails}
                                className="ml-4 rounded-lg p-2 text-xl leading-none text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                aria-label="Close details"
                            >
                                ×
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="protected-area-scrollbar flex-1 space-y-5 overflow-y-auto p-5 sm:p-6">
                            {/* Summary Cards */}
                            <div className="grid grid-cols-2 gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50 sm:grid-cols-4">
                                <DetailItem
                                    label="Category"
                                    value={selectedArea.category}
                                />

                                <DetailItem
                                    label="Year Established"
                                    value={selectedArea.year_established}
                                />

                                <div>
                                    <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Status
                                    </p>

                                    <div className="mt-1">
                                        <StatusBadge
                                            variant={variants[selectedArea.status]}
                                        >
                                            {selectedArea.status}
                                        </StatusBadge>
                                    </div>
                                </div>

                                <DetailItem
                                    label="Total Area"
                                    value={
                                        selectedArea.area_hectares
                                            ? `${formatNumber(
                                                  selectedArea.area_hectares
                                              )} ha`
                                            : null
                                    }
                                />
                            </div>

                            {/* LOCATION */}
                            <section>
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-sm">📍</span>
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        Location & Geographic Coverage
                                    </h4>
                                </div>

                                <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
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
                                            value={selectedArea.province}
                                        />

                                        <DetailItem
                                            label="Municipality / City"
                                            value={selectedArea.municipality}
                                        />
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

                                <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <DetailItem
                                            label="Core Zone"
                                            value={
                                                selectedArea.core_zone_hectares
                                                    ? `${formatNumber(
                                                          selectedArea.core_zone_hectares
                                                      )} ha`
                                                    : null
                                            }
                                        />

                                        <DetailItem
                                            label="Buffer Zone"
                                            value={
                                                selectedArea.buffer_zone_hectares
                                                    ? `${formatNumber(
                                                          selectedArea.buffer_zone_hectares
                                                      )} ha`
                                                    : null
                                            }
                                        />

                                        <div>
                                            <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                Total Area
                                            </p>
                                            <p className="mt-1 text-sm font-bold text-green-700 dark:text-green-400">
                                                {selectedArea.area_hectares
                                                    ? `${formatNumber(
                                                          selectedArea.area_hectares
                                                      )} ha`
                                                    : '—'}
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

                                <div className="grid gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:grid-cols-2">
                                    <DetailItem
                                        label="PAMO"
                                        value={selectedArea.pamo}
                                    />

                                    <DetailItem
                                        label="PASu"
                                        value={selectedArea.pasu}
                                    />

                                    <DetailItem
                                        label="Legal Basis"
                                        value={selectedArea.legal_basis}
                                        className="sm:col-span-2"
                                    />
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
                                    <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                                        <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Description
                                        </p>
                                        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-800 dark:text-gray-200">
                                            {selectedArea.description ||
                                                'No description provided.'}
                                        </p>
                                    </div>

                                    <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
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
                            <div className="flex items-center gap-2">
                                {auth.canUpdateProtectedAreas && (
                                    <Link
                                        href={`/protected-areas/${selectedArea.id}/edit`}
                                        className="inline-flex items-center justify-center rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-xs font-bold text-green-700 transition hover:bg-green-100 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300 dark:hover:bg-green-950"
                                    >
                                        ✏️ Edit This Protected Area
                                    </Link>
                                )}

                                {auth.canDeleteProtectedAreas && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setProtectedAreaToDelete(selectedArea)
                                        }
                                        className="inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-xs font-bold text-red-700 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
                                    >
                                        🗑️ Delete
                                    </button>
                                )}
                            </div>

                            <button
                                type="button"
                                onClick={closeDetails}
                                className="inline-flex items-center justify-center rounded-xl bg-green-800 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-green-900"
                            >
                                Close Details
                            </button>
                        </div>
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={Boolean(protectedAreaToDelete)}
                title="Delete protected area?"
                message={`Remove ${protectedAreaToDelete?.name} from the active protected area registry? This record can be restored from the database if needed.`}
                confirmLabel="Delete protected area"
                onCancel={() => setProtectedAreaToDelete(null)}
                onConfirm={deleteProtectedArea}
                processing={deleting}
            />
        </AuthenticatedLayout>
    );
}
