import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import DataTable from '../../Components/DataTable';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

const variants = {
    'Submitted': 'active',
    'Pending': 'pending',
    'Delayed': 'inactive'
};

const messages = {
    'technical-report-created': 'Technical report created successfully.',
    'technical-report-updated': 'Technical report updated successfully.',
    'technical-report-deleted': 'Technical report deleted successfully.'
};

export default function Index({ technicalReports, filters, protectedAreas, reportTypes, statuses }) {
    const { auth, status } = usePage().props;
    const [search, setSearch] = useState(filters.search || '');
    const [deleting, setDeleting] = useState(null);
    const [showSuccess, setShowSuccess] = useState(false);

    useEffect(() => setSearch(filters.search || ''), [filters.search]);

    // I-trigger ang animated success modal
    useEffect(() => {
        if (status && messages[status]) {
            setShowSuccess(true);
        }
    }, [status]);

    const visit = (params) => router.get('/technical-reports', { ...filters, search, ...params }, { preserveState: true, replace: true });
    const remove = () => router.delete(`/technical-reports/${deleting.id}`, { onFinish: () => setDeleting(null) });

    const columns = [
        {
            key: 'protected_area_name',
            label: 'Protected Area/PAMO',
            render: (report) => <span className="font-medium text-gray-900 dark:text-white">{report.protected_area_name}</span>
        },
        { key: 'report_type', label: 'Report Type' },
        {
            key: 'period',
            label: 'Reporting Period',
            render: (report) => `${report.reporting_year} ${report.quarter ? `(${report.quarter})` : ''}`
        },
        {
            key: 'submission_date',
            label: 'Submission Date',
            render: (report) => report.submission_date ? report.submission_date : <span className="text-gray-400 dark:text-gray-600 italic">Not Submitted</span>
        },
        {
            key: 'status',
            label: 'Status',
            render: (report) => <StatusBadge variant={variants[report.status]}>{report.status}</StatusBadge>
        },
        {
            key: 'attachment',
            label: 'Attachment',
            render: (report) => report.attachment ? (
                <div className="flex items-center gap-2">
                    <a href={`/view-file/${report.attachment}`} target="_blank" rel="noopener noreferrer" className="text-green-700 hover:text-green-900 dark:text-green-400 text-sm font-medium">
                        View
                    </a>
                    <span className="text-gray-300 dark:text-gray-600">|</span>
                    <a href={`/storage/${report.attachment}`} download className="text-blue-700 hover:text-blue-900 dark:text-blue-400 text-sm font-medium">
                        Download
                    </a>
                </div>
            ) : <span className="text-gray-400 text-xs italic">No Attachment</span>
        },
        {
            key: 'actions',
            label: <span className="sr-only">Actions</span>,
            cellClassName: 'text-right',
            render: (report) => (
                <div className="flex justify-end gap-3">
                    <Link className="font-medium text-green-800 hover:text-green-950 dark:text-green-400" href={`/technical-reports/${report.id}/edit`}>Edit</Link>
                    <button type="button" className="font-medium text-red-700 hover:text-red-900 dark:text-red-300" onClick={() => setDeleting(report)}>Delete</button>
                </div>
            )
        }
    ];

    const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

    return (
        <AuthenticatedLayout title="Technical Reports">
            <PageHeader
                title="Technical & AWS Reports Tracker"
                description="Monitor, track, and review timely submissions of Wetland Status and other technical reports from PAMOs."
                actions={
                    <Link href="/technical-reports/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                        Add technical report
                    </Link>
                }
            />

            <Card className="mt-6" padding="p-0">
                <form onSubmit={(event) => { event.preventDefault(); visit({ page: 1 }); }} className="grid gap-3 border-b border-gray-200 p-4 dark:border-gray-700 md:grid-cols-4">
                    <label className="md:col-span-2">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Search</span>
                        <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search reports, remarks, or PAMOs..." className={selectClass} />
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Protected Area</span>
                        <select className={selectClass} value={filters.protected_area_id || ''} onChange={(event) => visit({ protected_area_id: event.target.value, page: 1 })}>
                            <option value="">All protected areas</option>
                            {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Report Type</span>
                        <select className={selectClass} value={filters.report_type || ''} onChange={(event) => visit({ report_type: event.target.value, page: 1 })}>
                            <option value="">All report types</option>
                            {reportTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </label>
                    <label>
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-200">Status</span>
                        <select className={selectClass} value={filters.status || ''} onChange={(event) => visit({ status: event.target.value, page: 1 })}>
                            <option value="">All statuses</option>
                            {statuses.map((item) => <option key={item} value={item}>{item}</option>)}
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button type="submit" className="w-full rounded-ui bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-900">Search</button>
                    </div>
                </form>

                <DataTable columns={columns} rows={technicalReports.data} emptyTitle="No technical reports found" emptyDescription="Create a report tracker or adjust the search filters." />
            </Card>

            <div className="mt-5 flex justify-between text-sm">
                {technicalReports.prev_page_url ? <Link href={technicalReports.prev_page_url} className="font-semibold text-green-800 dark:text-green-400">← Previous</Link> : <span />}
                {technicalReports.next_page_url ? <Link href={technicalReports.next_page_url} className="font-semibold text-green-800 dark:text-green-400">Next →</Link> : <span />}
            </div>

            <ConfirmDialog open={Boolean(deleting)} title="Delete technical report record?" message={`Are you sure you want to delete this report? This will permanently remove the record and its attachment.`} confirmLabel="Delete record" onCancel={() => setDeleting(null)} onConfirm={remove} />

            {/* SUCCESS DIALOG MODAL POPUP WITH ANIMATION */}
            {showSuccess && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
                    <style>{`
                        @keyframes stroke {
                            100% { stroke-dashoffset: 0; }
                        }
                        @keyframes scale {
                            0%, 100% { transform: none; }
                            50% { transform: scale3d(1.15, 1.15, 1); }
                        }
                        @keyframes popIn {
                            0% { transform: scale(0.9); opacity: 0; }
                            100% { transform: scale(1); opacity: 1; }
                        }
                        .animate-pop-in {
                            animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
                        }
                        .checkmark-circle {
                            animation: scale 0.3s ease-in-out 0.3s both;
                        }
                        .checkmark-check {
                            stroke-dasharray: 50;
                            stroke-dashoffset: 50;
                            animation: stroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.15s forwards;
                        }
                    `}</style>

                    <div className="bg-white dark:bg-gray-800 rounded-xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center animate-pop-in">
                        <div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-950 mb-4 shadow-sm">
                            <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor">
                                <path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 font-sans">Success!</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">
                            {messages[status]}
                        </p>
                        <button
                            type="button"
                            onClick={() => setShowSuccess(false)}
                            className="w-full inline-flex justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition active:scale-95"
                        >
                            Okay
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
