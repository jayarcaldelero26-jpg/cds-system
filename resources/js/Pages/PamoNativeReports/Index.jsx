import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput } from '@/Components/Form';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import LaravelPaginationLinks from '@/Components/Crud/LaravelPaginationLinks';
import PamoPambDraftWorkspace from '@/Components/PamoPambDraftWorkspace';
import { formatReportDate, formatReportValue } from '@/Utils/dateFormatters';

export default function Index({ workflow, reports, pamoDrafts = [], assignedProtectedArea, pamoContext = null, filters = {}, pamoWorkspace = false }) {
    const page = usePage();
    const draftWorkspace = useRef(null);
    const [archivedReportId, setArchivedReportId] = useState(null);
    const [search, setSearch] = useState(filters.search || '');
    const [pendingResubmission, setPendingResubmission] = useState(null);
    const [resubmitting, setResubmitting] = useState(false);
    const [resubmissionError, setResubmissionError] = useState('');
    useEffect(() => {
        const timer = window.setTimeout(() => {
            const current = new URLSearchParams((page.url || '').split('?')[1] || '');
            if ((current.get('search') || '') === search.trim()) return;
            router.get(route('pamo-native-reports.index', workflow.key), search.trim() ? { search: search.trim() } : {}, {
                preserveState: true, preserveScroll: true, replace: true, only: ['reports', 'pamoDrafts', 'filters'],
            });
        }, 250);
        return () => window.clearTimeout(timer);
    }, [search, page.url, workflow.key]);

    const rows = reports?.data || [];
    const archivedReport = rows.find(row => row.id === archivedReportId && row.has_verified_final_archive);
    const confirmResubmission = () => {
        const row = pendingResubmission;
        if (!row || resubmitting) return;
        setResubmissionError('');
        setResubmitting(true);
        router.post(route('submission-tracking.transition', [workflow.source, row.id, row.resubmit_action]), { stage: row.resubmit_action }, {
            preserveScroll: true,
            onSuccess: () => setPendingResubmission(null),
            onError: () => setResubmissionError('The corrected copy could not be resubmitted. Your report remains available; review it and try again.'),
            onHttpException: () => setResubmissionError('The server could not resubmit this corrected copy. Your report remains available; review it and try again.'),
            onNetworkError: () => setResubmissionError('The request did not reach the server. Your report remains available; check the connection and try again.'),
            onFinish: () => setResubmitting(false),
        });
    };
    const period = row => row.reporting_period || row.document_type || (row.reporting_month ? `${row.reporting_month}/${row.reporting_year}` : row.semester || row.quarter || row.report_period_type || 'Not provided');
    const reportDetailsColumns = [
        { key: 'protected_area_name', label: 'Protected Area', render: row => formatReportValue(row.protected_area_name, 'Not recorded') },
        { key: 'activity_name', label: 'Activity / Report', render: row => formatReportValue(row.activity_name || row.station_name || workflow.label) },
        { key: 'document_type', label: 'Document type', render: row => formatReportValue(row.document_type || row.report_period_type) },
        { key: 'reporting_period', label: 'Native period', render: row => period(row) },
        { key: 'date_accomplished', label: 'Date accomplished', render: row => formatReportDate(row.date_accomplished) },
        { key: 'target_office', label: 'Receiving office', render: row => formatReportValue(row.target_office, 'Not recorded') },
        { key: 'workflow_stage', label: 'Routing stage', render: row => formatReportValue(row.workflow_stage, 'Not recorded') },
    ];
    const columns = [
        ...reportDetailsColumns,
        { key: 'mov_url', label: 'Report file', render: row => row.has_verified_final_archive ? 'Archived' : row.mov_url ? <a className="font-semibold text-emerald-800 underline dark:text-emerald-300" href={row.mov_url} onClick={event => event.stopPropagation()}>Protected preview</a> : <span className="text-xs text-gray-500 dark:text-gray-400" role="status">File unavailable</span> },
        { key: 'can_edit_correction', label: 'Correction', render: row => row.can_edit_correction ? <div className="flex flex-wrap gap-2"><Button type="button" size="compact" variant="secondary" onClick={event => { event.stopPropagation(); draftWorkspace.current?.openCorrection(row); }}>Edit correction</Button><Button type="button" size="compact" variant="primary" onClick={event => { event.stopPropagation(); setResubmissionError(''); setPendingResubmission(row); }}>Resubmit corrected copy</Button></div> : 'No correction action available' },
    ];
    const draftColumns = [
        { key: 'started_at', label: 'Preparation started', render: row => formatReportDate(row.started_at) },
        { key: 'status', label: 'Status', render: row => row.status === 'forwarded' ? 'Forwarded' : 'Saved draft' },
        { key: 'mov_file_name', label: 'Attachment', render: row => row.mov_file_name || 'No attachment' },
        { key: 'source_url', label: 'Source', render: row => row.source_url ? <a className="text-emerald-800 underline dark:text-emerald-300" href={row.source_url} onClick={event => event.stopPropagation()}>Open tracking</a> : 'Not forwarded' },
    ];

    return <AuthenticatedLayout title={`${workflow.label} Report`}>
        <PageHeader title={`${workflow.label} Report`} description={`Prepare and track ${workflow.label} reports for ${assignedProtectedArea?.name || 'your assigned Protected Area'}.`} />
        <div className="mt-5 flex flex-wrap items-end gap-3">
            <FilterToolbar chips={[]} onClear={() => setSearch('')}>
                <FloatingInput id="pamo-native-search" label="Search reports" value={search} onChange={event => setSearch(event.target.value)} />
            </FilterToolbar>
            {pamoWorkspace && workflow?.is_active !== false && <Button type="button" size="compact" variant="primary" className="h-10 shrink-0 rounded-xl px-5 text-xs" onClick={() => draftWorkspace.current?.startNew()}>New draft</Button>}
        </div>
        <div className="mt-4">
            <CrudTable compactEmpty title={`${workflow.label} reports`} subtitle={`${reports?.total ?? rows.length} report${(reports?.total ?? rows.length) === 1 ? '' : 's'}`} helperText={rows.length ? 'Open a row to view tracking or archived report details.' : undefined} caption={`${workflow.label} report submissions`} columns={columns} rows={rows} rowKey="id" onRowClick={row => {
                if (row.has_verified_final_archive) setArchivedReportId(row.id);
                else router.visit(row.source_url);
            }} emptyTitle="No report submissions" emptyDescription={`No ${workflow.label} reports match this filter.`} pagination={reports?.links?.length > 3 ? <LaravelPaginationLinks links={reports.links} /> : null} />
        </div>
        <div className="mt-4">
            <CrudTable compact title="Saved drafts" subtitle="Saved drafts stay private until you forward a complete report." columns={draftColumns} rows={pamoDrafts} rowKey="id" onRowClick={draft => draftWorkspace.current?.openDraft(draft)} emptyTitle="No saved drafts" emptyDescription="Choose New draft when preparation begins." />
        </div>
        <CrudDetailsModal report reportData={archivedReport} open={Boolean(archivedReport)} title={`${workflow.label} Report Details`} subtitle={archivedReport?.protected_area_name} onClose={() => setArchivedReportId(null)}>
            {archivedReport && <dl className="grid gap-4 sm:grid-cols-2">
                {reportDetailsColumns.map(column => <div key={column.key}>
                    <dt className="text-xs font-semibold text-gray-500 dark:text-gray-400">{column.label}</dt>
                    <dd className="mt-1 text-sm">{column.render(archivedReport)}</dd>
                </div>)}
                <div className="sm:col-span-2">
                    <dt className="text-xs font-semibold text-gray-500 dark:text-gray-400">Remarks</dt>
                    <dd className="mt-1 whitespace-pre-wrap text-sm">{formatReportValue(archivedReport.remarks)}</dd>
                </div>
            </dl>}
        </CrudDetailsModal>
        {pamoWorkspace && <PamoPambDraftWorkspace ref={draftWorkspace} workflow={workflow} drafts={pamoDrafts} assignedProtectedArea={assignedProtectedArea} pamoContext={pamoContext} />}
        <ConfirmDialog open={Boolean(pendingResubmission)} title="Confirm resubmission?" message={<><span>Resubmit the corrected copy for {pendingResubmission?.activity_name || pendingResubmission?.document_type || workflow.label} through its existing review route?</span>{resubmissionError && <span role="alert" className="mt-3 block rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-left text-xs leading-5 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{resubmissionError}</span>}</>} confirmLabel="Resubmit corrected copy" cancelLabel="Go Back" processing={resubmitting} onConfirm={confirmResubmission} onCancel={() => { if (!resubmitting) { setPendingResubmission(null); setResubmissionError(''); } }} />
    </AuthenticatedLayout>;
}
