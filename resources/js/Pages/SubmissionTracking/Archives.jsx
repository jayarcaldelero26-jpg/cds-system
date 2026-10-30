import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import LaravelPaginationLinks from '@/Components/Crud/LaravelPaginationLinks';
import PageHeader from '@/Components/PageHeader';
import { formatManila } from '@/Utils/dateFormatters';
import FinalArchiveResults from '@/Components/SubmissionTracking/FinalArchiveResults';
import FinalArchiveDisplayToggle from '@/Components/SubmissionTracking/FinalArchiveDisplayToggle';
import FinalArchiveReader from '@/Components/SubmissionTracking/FinalArchiveReader';
import FinalArchiveStatusTabs from '@/Components/SubmissionTracking/FinalArchiveStatusTabs';
import FinalArchivesFilterFields from '@/Components/SubmissionTracking/FinalArchivesFilterFields';
import { archiveFiles, archiveReportTitle, archiveRowContext, archiveRowDate, archiveRowIdentity, archivePendingStatus } from '@/Utils/finalArchivePresentation.mjs';
import { archiveThumbnailScopeKey } from '@/Utils/archiveThumbnailLoader.mjs';
import {
    finalArchiveRequestFailureMessage,
    finalArchiveVisitErrorOptions,
    postFinalArchive,
    postFinalArchiveRetry,
    registerFinalArchivePageGuard,
} from '@/Utils/finalArchiveActions.mjs';
import { applyFinalArchiveFilters } from '@/Utils/finalArchiveFilters.mjs';
import { defaultManilaReceiptInput, earliestManilaReceiptInput } from '@/Utils/finalArchiveReceiptTime.mjs';
import {
    FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS,
    hasFinalArchiveStatusPollAttemptsRemaining,
    shouldPollFinalArchiveStatus,
} from '@/Utils/finalArchiveStatusPolling.mjs';
import PamoReportPendingAnimation from '@/Components/PamoReportPendingAnimation';

const emptyPaginator = { data: [], links: [], total: 0 };
const paginationQueryParams = () => {
    const query = new URLSearchParams(window.location.search);
    return Object.fromEntries(['page', 'awaiting_page', 'receipt_page'].flatMap(key => query.has(key) ? [[key, query.get(key)]] : []));
};
const newRequestIdentity = () => globalThis.crypto?.randomUUID?.()
    || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const value = Math.random() * 16 | 0;
        return (character === 'x' ? value : (value & 0x3 | 0x8)).toString(16);
    });

export default function Archives({ archives = emptyPaginator, awaitingArchive = emptyPaginator, awaitingReceipt = emptyPaginator, filters = {}, selected = null, filterOptions = {} }) {
    const { flash = {}, auth = {} } = usePage().props;
    const thumbnailScope = archiveThumbnailScopeKey(auth);
    const [tab, setTab] = useState('archives');
    const [displayMode, setDisplayMode] = useState('grid');
    const [readerInitialTab, setReaderInitialTab] = useState('document');
    const [search, setSearch] = useState(filters.search || '');
    const [module, setModule] = useState(filters.module || '');
    const [protectedArea, setProtectedArea] = useState(filters.protected_area || '');
    const [office, setOffice] = useState(filters.office || '');
    const [year, setYear] = useState(filters.reporting_year || '');
    const [modal, setModal] = useState(null);
    const [previewFile, setPreviewFile] = useState(null);
    const [archiveRequestError, setArchiveRequestError] = useState('');
    const [listLoading, setListLoading] = useState(false);
    const [statusRefreshVersion, setStatusRefreshVersion] = useState(0);
    const [statusPollTimedOut, setStatusPollTimedOut] = useState(false);
    const handledStatusRefreshVersion = useRef(0);
    const archiveForm = useForm({ physically_received_at: '', intent_key: '', final_files: [], signature_confirmation: false });
    const retryForm = useForm({});
    const handleArchiveRequestFailure = failure => setArchiveRequestError(finalArchiveRequestFailureMessage(failure));
    const handleArchiveMutationFailure = failure => {
        handleArchiveRequestFailure(failure);
        setStatusRefreshVersion(version => version + 1);
    };
    const selectedManifestIsProcessing = shouldPollFinalArchiveStatus(selected?.latest_manifest);

    useEffect(() => registerFinalArchivePageGuard(route('final-archives.index')), []);

    useEffect(() => {
        setSearch(filters.search || '');
        setModule(filters.module || '');
        setProtectedArea(filters.protected_area || '');
        setOffice(filters.office || '');
        setYear(filters.reporting_year || '');
    }, [filters.search, filters.module, filters.protected_area, filters.office, filters.reporting_year]);

    useEffect(() => {
        setModal(null);
        setPreviewFile(null);
        archiveForm.reset();
        archiveForm.clearErrors();
    }, [selected?.source, selected?.source_id, selected?.workflow_key]);

    useEffect(() => {
        setStatusPollTimedOut(false);
    }, [selected?.source, selected?.source_id, selected?.workflow_key]);

    useEffect(() => {
        const unknownOutcome = statusRefreshVersion > handledStatusRefreshVersion.current;
        if (!selectedManifestIsProcessing && !unknownOutcome) return undefined;
        if (unknownOutcome) handledStatusRefreshVersion.current = statusRefreshVersion;

        let attempts = 0;
        let timer = null;
        let cancelToken = null;
        let stopped = false;
        let keepChecking = selectedManifestIsProcessing || unknownOutcome;
        const url = `${route('final-archives.index')}${window.location.search}`;

        const scheduleNext = active => {
            keepChecking = active;
            if (!active) {
                setStatusPollTimedOut(false);
                return;
            }
            if (!hasFinalArchiveStatusPollAttemptsRemaining(attempts)) {
                setStatusPollTimedOut(true);
                return;
            }
            timer = window.setTimeout(refresh, FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS);
        };

        const refresh = () => {
            if (stopped || !hasFinalArchiveStatusPollAttemptsRemaining(attempts)) {
                if (keepChecking) setStatusPollTimedOut(true);
                return;
            }
            attempts += 1;
            router.get(url, {}, {
                only: ['selected'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onCancelToken: token => { cancelToken = token; },
                onSuccess: page => scheduleNext(shouldPollFinalArchiveStatus(page?.props?.selected?.latest_manifest)),
                onHttpException: () => {
                    scheduleNext(keepChecking);
                    return false;
                },
                onNetworkError: () => {
                    scheduleNext(keepChecking);
                    return false;
                },
                onFinish: () => { cancelToken = null; },
            });
        };

        timer = window.setTimeout(refresh, unknownOutcome ? 0 : FINAL_ARCHIVE_STATUS_POLL_INTERVAL_MS);
        return () => {
            stopped = true;
            if (timer !== null) window.clearTimeout(timer);
            cancelToken?.cancel?.();
        };
    }, [selected?.source, selected?.source_id, selected?.workflow_key, selectedManifestIsProcessing, statusRefreshVersion]);

    useEffect(() => {
        const url = previewFile?.url;
        return () => { if (typeof url === 'string' && url.startsWith('blob:')) URL.revokeObjectURL(url); };
    }, [previewFile?.url]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            applyFinalArchiveFilters(
                router,
                route,
                { search, module, protectedArea, office, year, selected },
                new URLSearchParams(window.location.search),
                {
                    ...finalArchiveVisitErrorOptions(handleArchiveRequestFailure),
                    onStart: () => setListLoading(true),
                    onSuccess: () => setArchiveRequestError(''),
                    onFinish: () => setListLoading(false),
                },
            );
        }, 260);
        return () => window.clearTimeout(timer);
    }, [search, module, protectedArea, office, year, selected?.source, selected?.source_id]);

    const visibleRows = tab === 'awaiting' ? (awaitingArchive.data || []) : tab === 'receipt' ? (awaitingReceipt.data || []) : (archives.data || []);
    const displayedPreview = useMemo(() => {
        if (!previewFile) return null;
        if (previewFile.protected) return previewFile;
        return { name: previewFile.name, type: previewFile.type, url: previewFile.url };
    }, [previewFile]);

    const openSelected = (row, initialTab = 'document') => {
        setArchiveRequestError('');
        setReaderInitialTab(initialTab);
        return router.get(route('final-archives.index'), {
            ...(search.trim() ? { search: search.trim() } : {}), ...(module ? { module } : {}),
            ...(protectedArea ? { protected_area: protectedArea } : {}), ...(office ? { office } : {}), ...(year ? { reporting_year: year } : {}),
            ...paginationQueryParams(),
            source: row.source, record: row.source_id,
        }, { preserveScroll: true, preserveState: true, only: ['selected'], ...finalArchiveVisitErrorOptions(handleArchiveRequestFailure) });
    };
    const closeSelected = () => {
        setArchiveRequestError('');
        setModal(null);
        setPreviewFile(null);
        archiveForm.reset();
        return router.get(route('final-archives.index'), {
            ...(search.trim() ? { search: search.trim() } : {}), ...(module ? { module } : {}),
            ...(protectedArea ? { protected_area: protectedArea } : {}), ...(office ? { office } : {}), ...(year ? { reporting_year: year } : {}),
            ...paginationQueryParams(),
        }, { preserveScroll: true, preserveState: true, only: ['selected'], ...finalArchiveVisitErrorOptions(handleArchiveRequestFailure) });
    };
    const openReceiveAndArchive = () => {
        setArchiveRequestError('');
        archiveForm.clearErrors();
        archiveForm.setData({
            physically_received_at: defaultManilaReceiptInput(selected?.release_occurred_at, selected?.receipt?.physically_received_at),
            intent_key: newRequestIdentity(), final_files: [], signature_confirmation: false,
        });
        setPreviewFile(null);
        setModal('receive-archive');
    };
    const closeModal = () => {
        if (archiveForm.processing) return;
        setModal(null);
        archiveForm.reset();
        archiveForm.clearErrors();
        setPreviewFile(null);
    };
    const submitArchive = event => {
        setArchiveRequestError('');
        return postFinalArchive(event, archiveForm, selected, route, page => {
            if (page?.props?.flash?.archive_status === 'archived') closeModal();
        }, handleArchiveMutationFailure);
    };
    const retryArchive = () => {
        setArchiveRequestError('');
        return postFinalArchiveRetry(retryForm, selected, route, () => {}, handleArchiveMutationFailure);
    };
    const clearFilters = () => { setSearch(''); setModule(''); setProtectedArea(''); setOffice(''); setYear(''); };
    const activePaginator = tab === 'awaiting' ? awaitingArchive : tab === 'receipt' ? awaitingReceipt : archives;
    const paginationLinks = Array.isArray(activePaginator.links) ? activePaginator.links : [];
    const selectedIdentity = archiveRowIdentity(selected);
    const chips = [
        search.trim() && { key: 'search', label: 'Search', value: search.trim(), onRemove: () => setSearch('') },
        module && { key: 'module', label: 'Report module', value: module, onRemove: () => setModule('') },
        protectedArea && { key: 'protected-area', label: 'Protected Area', value: protectedArea, onRemove: () => setProtectedArea('') },
        office && { key: 'office', label: 'Originating office', value: office, onRemove: () => setOffice('') },
        year && { key: 'year', label: 'Reporting year', value: year, onRemove: () => setYear('') },
    ].filter(Boolean);
    const filtered = Boolean(search.trim() || module || protectedArea || office || year);

    const columns = [
        { key: 'file', label: 'Report / original file', render: row => <div><p className="max-w-[360px] truncate font-semibold text-slate-900 dark:text-white" title={archiveReportTitle(row)}>{archiveReportTitle(row)}</p>{archiveFiles(row)[0]?.name && <p className="mt-0.5 max-w-[360px] truncate text-xs text-slate-500 dark:text-slate-400" title={archiveFiles(row)[0].name}>File: {archiveFiles(row)[0].name}</p>}{archiveFiles(row).length > 1 && <p className="mt-1 text-[11px] text-slate-500">{archiveFiles(row).length} signed files</p>}</div> },
        { key: 'protected_area', label: 'Protected Area / Office', render: row => <div><p className="font-medium">{row.protected_area || row.office || 'Not recorded'}</p>{row.supervising_office && <p className="mt-0.5 text-xs text-slate-500">Receiving CENRO: {row.supervising_office}</p>}</div> },
        { key: 'period', label: 'Period', render: row => [row.period, row.reporting_year].filter(Boolean).join(' · ') || 'Not provided' },
        { key: 'date', label: tab === 'receipt' ? 'Released' : tab === 'awaiting' ? 'Received' : 'Archived', render: row => archiveRowDate(row, tab) ? formatManila(archiveRowDate(row, tab)) : 'Not recorded' },
        ...(tab === 'archives' ? [{ key: 'archive_type', label: 'Archive type', render: row => row.earlier_dispatch_copy ? 'Earlier Dispatch copy' : 'Final signed Regional return' }] : []),
        ...(tab === 'archives' ? [] : [{ key: 'status', label: 'Status', render: row => { const status = archivePendingStatus(row, tab); return <span className={`inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold ${status.state === 'processing' ? 'border-sky-200 bg-sky-50 text-sky-800' : status.state === 'failed' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>{status.label}</span>; } }]),
    ];

    return <AuthenticatedLayout title="Archives">
        <PageHeader title="Archives" description="Find final signed returns, earlier Dispatch copies, and reports waiting for archive steps." className="mb-5" />
        {flash.error && <div role="alert" className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">{flash.error}</div>}
        {flash.info && <div role="status" className="mb-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-200">{flash.info}</div>}
        {archiveRequestError && !selected && <div role="alert" className="mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">{archiveRequestError}</div>}
        <div className="flex min-w-0 flex-wrap items-center gap-2">
            <FinalArchiveStatusTabs
                value={tab}
                archivesTotal={archives.total || 0}
                awaitingArchiveTotal={awaitingArchive.total || 0}
                awaitingReceiptTotal={awaitingReceipt.total || 0}
                onChange={setTab}
            />
        </div>
        <div className="mt-3 flex min-w-0 flex-col gap-2.5 sm:flex-row sm:items-center">
            <label className="relative block w-full shrink-0 sm:max-w-[352px]">
                <span className="sr-only">Search archives</span>
                <Icon icon="lucide:search" width="16" height="16" aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search reports, areas, offices, or files" aria-label="Search archives" className="h-10 w-full rounded-lg border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
            </label>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 sm:justify-end">
                <FilterToolbar className="min-w-0 flex-1" triggerClassName="h-10" chips={chips} onClear={clearFilters}>
                    <FinalArchivesFilterFields
                        module={module}
                        protectedArea={protectedArea}
                        office={office}
                        year={year}
                        filterOptions={filterOptions}
                        onModuleChange={event => setModule(event.target.value)}
                        onProtectedAreaChange={event => setProtectedArea(event.target.value)}
                        onOfficeChange={event => setOffice(event.target.value)}
                        onYearChange={event => setYear(event.target.value)}
                    />
                </FilterToolbar>
                <FinalArchiveDisplayToggle value={displayMode} onChange={setDisplayMode} />
            </div>
        </div>
        <div className="mt-4" aria-busy={listLoading}>
            {visibleRows.length > 0 && listLoading && <p role="status" className="mb-2 text-xs text-slate-500 dark:text-slate-400">Updating archive results…</p>}
            {visibleRows.length === 0 && listLoading ? <section className="flex min-h-48 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-8 text-center dark:border-slate-700 dark:bg-slate-900" role="status">
                <Icon icon="lucide:loader-circle" width="30" height="30" className="animate-spin text-emerald-700 dark:text-emerald-400" aria-hidden="true" />
                <h2 className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Loading archive records…</h2>
            </section> : visibleRows.length === 0 ? <section className="flex min-h-48 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-8 text-center dark:border-slate-700 dark:bg-slate-900" role="status">
                <Icon icon={filtered ? 'lucide:search-x' : 'lucide:files'} width="34" height="34" className="text-slate-300 dark:text-slate-600" aria-hidden="true" />
                <h2 className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{filtered ? 'No matching documents' : tab === 'awaiting' ? 'No receipts awaiting archive' : tab === 'receipt' ? 'No released reports awaiting final receipt' : 'No archives'}</h2>
                <p className="mt-1 max-w-md text-xs leading-5 text-slate-500 dark:text-slate-400">{filtered ? 'Change or clear the current search and filters.' : tab === 'awaiting' ? 'Final receipts from Regional Office appear here for the eligible PENRO focal.' : tab === 'receipt' ? 'Eligible released reports appear here for the receiving PENRO focal.' : 'Final signed returns appear after complete verification. Earlier Dispatch copies are labeled separately and do not prove a Regional receipt.'}</p>
            </section> : <FinalArchiveResults rows={visibleRows} displayMode={displayMode} tab={tab} selectedIdentity={selectedIdentity} thumbnailScopeKey={thumbnailScope} columns={columns} onOpen={openSelected} />}
            {visibleRows.length > 0 && paginationLinks.length > 3 && <div className="mt-4"><LaravelPaginationLinks links={paginationLinks} visitOptions={finalArchiveVisitErrorOptions(handleArchiveRequestFailure)} /></div>}
        </div>

        <FinalArchiveReader
            open={Boolean(selected) && !modal}
            selected={selected}
            initialTab={readerInitialTab}
            onClose={closeSelected}
            onReceive={openReceiveAndArchive}
            onAddArchive={openReceiveAndArchive}
            onRetry={retryArchive}
            retrying={retryForm.processing}
            statusPollTimedOut={statusPollTimedOut}
            requestError={archiveRequestError}
        />
        <CrudFormModal open={modal === 'receive-archive'} icon={<ReceiptArchiveIcon />} title={selected?.receipt ? 'Attach Final MOVs to Recorded Receipt' : 'Receive Final MOVs from Regional Office'} subtitle={selected ? `${selected.module} · ${selected.receiving_office || ''}` : ''} onClose={closeModal} onSubmit={submitArchive} processing={archiveForm.processing} errors={archiveForm.errors} busyOverlay={archiveForm.processing ? <PamoReportPendingAnimation intent="archive" /> : null} busyOverlayPresentation="success-sized" saveLabel={selected?.receipt ? 'Attach Signed Set and Archive' : 'Receive and Archive'} maxWidth="max-w-5xl" preview={<FilePreviewPanel file={displayedPreview} title="Selected final copy preview" emptyText="Select a signed copy to preview" heightClass="h-[420px]" />}>
            <CrudSection title="Returned final file set" subtitle="Select every file in the complete returned signed set for this report. The existing source has one official attachment slot; unsigned source and unrelated correction/reference files are not copied.">
                <div className="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100"><p>Enter when the fully signed documents physically arrived at the PENRO. The server records this receipt, then uploads and verifies the complete set in the private archive.</p><p className="mt-1 text-xs">Regional release: <strong>{selected?.release_occurred_at ? formatManila(selected.release_occurred_at) : 'not available'}</strong> · All times use Asia/Manila.</p></div>
                <label htmlFor="final-regional-receipt-at" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Actual receipt date and time (Asia/Manila)</label>
                <input id="final-regional-receipt-at" name="physically_received_at" type="datetime-local" step="60" min={selected?.receipt ? undefined : earliestManilaReceiptInput(selected?.release_occurred_at)} value={archiveForm.data.physically_received_at} readOnly={Boolean(selected?.receipt)} onChange={event => archiveForm.setData('physically_received_at', event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm read-only:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:read-only:bg-slate-800" required />
                <p className="mt-1 text-xs text-slate-500">{selected?.receipt ? `Original receipt recorded ${formatManila(selected.receipt.physically_received_at)} by ${selected.receipt.received_by_name || 'the PENRO focal'}. This audit date is preserved.` : `Choose a time at or after ${selected?.release_occurred_at ? formatManila(selected.release_occurred_at) : 'the Regional release'}. The selectable minimum is rounded up to the next minute if needed.`}</p>
                {archiveForm.errors.physically_received_at && <p role="alert" className="mt-1 text-xs text-red-600">{archiveForm.errors.physically_received_at}</p>}
                <AttachmentDropzone id="final-return-upload" label={selected?.final_file_contract?.label || 'Final signed MOV'} files={archiveForm.data.final_files} onChange={files => { const next = Array.isArray(files) ? files : (files ? [files] : []); archiveForm.setData({ ...archiveForm.data, final_files: next, signature_confirmation: false }); const file = next[0]; setPreviewFile(file ? { name: file.name, type: file.type || 'application/octet-stream', url: URL.createObjectURL(file) } : null); }} onSelectFile={file => { if (file) setPreviewFile({ name: file.name, type: file.type || 'application/octet-stream', url: URL.createObjectURL(file) }); }} accept={(selected?.final_file_contract?.extensions || []).map(extension => `.${extension}`).join(',')} maxSizeBytes={(selected?.final_file_contract?.max_kilobytes || 0) * 1024} multiple required disabled={archiveForm.processing} acceptedTypesHint={(selected?.final_file_contract?.extensions || []).map(value => value.toUpperCase()).join(', ')} maxSizeHint={`Maximum ${Math.ceil((selected?.final_file_contract?.max_kilobytes || 0) / 1024)} MB per file`} error={archiveForm.errors['final_files.0'] || archiveForm.errors.final_files} />
                <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"><input type="checkbox" disabled={archiveForm.processing} checked={Boolean(archiveForm.data.signature_confirmation)} onChange={event => archiveForm.setData('signature_confirmation', event.target.checked)} className="mt-0.5 rounded border-amber-400 text-emerald-700 focus:ring-emerald-600 disabled:opacity-50" /><span>I reviewed this complete returned file set and confirm its signatures are complete. CDS-SMART records this confirmation against the selected files; it does not cryptographically validate signatures or add a Regional Office verdict.</span></label>
                {archiveForm.errors.signature_confirmation && <p role="alert" className="mt-1 text-xs text-red-600">{archiveForm.errors.signature_confirmation}</p>}
                {flash.archive_error_code === 'google_drive_invalid_grant' && <p role="alert" className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">Google Drive rejected its saved authorization. Contact the CDS-SMART administrator to confirm the same approved Google account and client, then restore authorization before retrying this retained manifest.</p>}
                {flash.archive_error_code === 'google_drive_session_reauth' && <p role="alert" className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">Google Workspace requires the existing Drive account to be reauthenticated through the approved flow before retrying this retained manifest.</p>}
                {flash.archive_error_code === 'google_drive_client_configuration' && <p role="alert" className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">Google rejected the configured OAuth client. Contact the CDS-SMART administrator to verify the existing approved client configuration before retrying.</p>}
                {flash.archive_status && flash.archive_status !== 'archived' && <p role="status" className="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">{flash.archive_status === 'processing' ? 'Receipt recorded; archive verification is still processing.' : 'Receipt recorded; archive needs retry. Your receipt date and exact signed set are retained.'} Close this form to use Retry Archive from the record details.</p>}
            </CrudSection>
        </CrudFormModal>
    </AuthenticatedLayout>;
}

function ReceiptArchiveIcon() {
    return <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M4 7.5h16v12H4zM3 4.5h18v3H3zM8 11.5h8"/><path d="m9 15 2 2 4-4"/></svg>;
}
