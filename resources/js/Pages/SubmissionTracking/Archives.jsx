import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput, FloatingSelect } from '@/Components/Form';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import LaravelPaginationLinks from '@/Components/Crud/LaravelPaginationLinks';
import { formatManila } from '@/Utils/dateFormatters';

const emptyPaginator = { data: [], links: [], total: 0 };
const newRequestIdentity = () => globalThis.crypto?.randomUUID?.()
    || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const value = Math.random() * 16 | 0;
        return (character === 'x' ? value : (value & 0x3 | 0x8)).toString(16);
    });

export default function Archives({ archives = emptyPaginator, awaitingArchive = emptyPaginator, awaitingReceipt = emptyPaginator, filters = {}, selected = null, filterOptions = {} }) {
    const { flash = {} } = usePage().props;
    const [tab, setTab] = useState('archives');
    const [search, setSearch] = useState(filters.search || '');
    const [module, setModule] = useState(filters.module || '');
    const [protectedArea, setProtectedArea] = useState(filters.protected_area || '');
    const [office, setOffice] = useState(filters.office || '');
    const [year, setYear] = useState(filters.reporting_year || '');
    const [modal, setModal] = useState(null);
    const [previewFile, setPreviewFile] = useState(null);
    const receiveForm = useForm({ physically_received_at: '' });
    const archiveForm = useForm({ intent_key: '', final_files: [], signature_confirmation: false });
    const retryForm = useForm({});

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
        receiveForm.reset();
        receiveForm.clearErrors();
        archiveForm.reset();
        archiveForm.clearErrors();
    }, [selected?.source, selected?.source_id, selected?.workflow_key]);

    useEffect(() => {
        const url = previewFile?.url;
        return () => { if (typeof url === 'string' && url.startsWith('blob:')) URL.revokeObjectURL(url); };
    }, [previewFile?.url]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            const params = {
                ...(search.trim() ? { search: search.trim() } : {}),
                ...(module ? { module } : {}),
                ...(protectedArea ? { protected_area: protectedArea } : {}),
                ...(office ? { office } : {}),
                ...(year ? { reporting_year: year } : {}),
                ...(selected ? { source: selected.source, record: selected.source_id } : {}),
            };
            const current = new URLSearchParams(window.location.search);
            const same = ['search', 'module', 'protected_area', 'office', 'reporting_year'].every(key => (current.get(key) || '') === (params[key] || ''));
            if (same) return;
            router.get(route('final-archives.index'), params, { preserveState: true, preserveScroll: true, replace: true });
        }, 260);
        return () => window.clearTimeout(timer);
    }, [search, module, protectedArea, office, year, selected?.source, selected?.source_id]);

    const visibleRows = tab === 'awaiting' ? (awaitingArchive.data || []) : tab === 'receipt' ? (awaitingReceipt.data || []) : (archives.data || []);
    const displayedPreview = useMemo(() => {
        if (!previewFile) return null;
        if (previewFile.protected) return previewFile;
        return { name: previewFile.name, type: previewFile.type, url: previewFile.url };
    }, [previewFile]);

    const openSelected = row => router.get(route('final-archives.index'), {
        ...(search.trim() ? { search: search.trim() } : {}), ...(module ? { module } : {}),
        ...(protectedArea ? { protected_area: protectedArea } : {}), ...(office ? { office } : {}), ...(year ? { reporting_year: year } : {}),
        source: row.source, record: row.source_id,
    }, { preserveScroll: true, preserveState: true, only: ['selected'] });
    const closeSelected = () => {
        setModal(null);
        setPreviewFile(null);
        receiveForm.reset();
        archiveForm.reset();
        router.get(route('final-archives.index'), {
            ...(search.trim() ? { search: search.trim() } : {}), ...(module ? { module } : {}),
            ...(protectedArea ? { protected_area: protectedArea } : {}), ...(office ? { office } : {}), ...(year ? { reporting_year: year } : {}),
        }, { preserveScroll: true, preserveState: true, only: ['selected'] });
    };
    const openReceive = () => {
        receiveForm.reset('physically_received_at');
        receiveForm.clearErrors();
        setModal('receive');
    };
    const openArchive = () => {
        archiveForm.reset();
        archiveForm.clearErrors();
        archiveForm.setData({ intent_key: newRequestIdentity(), final_files: [], signature_confirmation: false });
        setPreviewFile(null);
        setModal('archive');
    };
    const closeModal = () => {
        if (receiveForm.processing || archiveForm.processing) return;
        setModal(null);
        receiveForm.reset();
        archiveForm.reset();
        archiveForm.clearErrors();
        setPreviewFile(null);
    };
    const submitReceipt = event => {
        event.preventDefault();
        receiveForm.post(route('final-archives.receipts.store', [selected.source, selected.source_id]), {
            onSuccess: () => { closeModal(); router.reload({ only: ['selected', 'archives', 'awaitingArchive'] }); },
        });
    };
    const submitArchive = event => {
        event.preventDefault();
        archiveForm.post(route('final-archives.store', [selected.source, selected.source_id]), {
            forceFormData: true,
            onSuccess: () => { closeModal(); router.reload({ only: ['selected', 'archives', 'awaitingArchive'] }); },
        });
    };
    const retryArchive = () => {
        if (!selected?.latest_manifest?.id || retryForm.processing) return;
        retryForm.post(route('final-archives.retry', selected.latest_manifest.id), {
            onSuccess: () => router.reload({ only: ['selected', 'archives', 'awaitingArchive'] }),
        });
    };
    const clearFilters = () => { setSearch(''); setModule(''); setProtectedArea(''); setOffice(''); setYear(''); };

    const columns = [
        { key: 'module', label: 'Report / Workflow', render: row => <div><p className="font-semibold text-slate-900 dark:text-white">{row.module}</p><p className="mt-0.5 text-xs text-slate-500">{row.workflow_key || row.source}{row.title ? ` · ${row.title}` : ''}</p></div> },
        { key: 'protected_area', label: 'Protected Area / Office', render: row => <div><p className="font-medium">{row.protected_area || '—'}</p><p className="mt-0.5 text-xs text-slate-500">{row.office || '—'}</p></div> },
        { key: 'period', label: 'Period / Year', render: row => [row.period, row.reporting_year].filter(Boolean).join(' · ') || '—' },
        { key: 'receipt', label: tab === 'receipt' ? 'Released to Regional' : 'Received from Regional', render: row => tab === 'receipt' ? formatManila(row.release_occurred_at) : row.receipt?.physically_received_at ? formatManila(row.receipt.physically_received_at) : '—' },
        { key: 'archived_at', label: tab === 'archives' ? 'Archived' : tab === 'awaiting' ? 'Archive status' : 'Next action', render: row => tab === 'archives' ? formatManila(row.manifest?.finalized_at) : <span className="text-xs font-semibold text-amber-700 dark:text-amber-300">{tab === 'receipt' ? 'Awaiting Final Receipt' : row.manifest?.status || 'Awaiting Archive'}</span> },
        { key: 'actions', label: 'Actions', render: row => <button type="button" onClick={() => openSelected(row)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-slate-50 dark:border-slate-600 dark:text-emerald-300 dark:hover:bg-slate-800" data-cds-action="true" data-cds-action-variant="primary">{row.manifest?.status === 'ARCHIVED' ? 'View Archive' : 'Open details'}</button> },
    ];

    return <AuthenticatedLayout title="Final Archives">
        <PageHeader title="Final Archives" description="Regional-return copies remain scoped to each report source, office, and account. Final receipt and archive records do not change custody routing." />
        {flash.error && <div role="alert" className="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">{flash.error}</div>}
        {flash.info && <div role="status" className="mt-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-200">{flash.info}</div>}
        <div className="mt-5">
            <FilterToolbar chips={[]} onClear={clearFilters}>
                <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <FloatingInput id="final-archive-search" label="Search report, PA, office, or file" value={search} onChange={event => setSearch(event.target.value)} />
                    <FloatingSelect id="final-archive-module" label="Report module" value={module} onChange={event => setModule(event.target.value)}><option value="">All modules</option>{(filterOptions.modules || []).map(value => <option key={value} value={value}>{value}</option>)}</FloatingSelect>
                    <FloatingSelect id="final-archive-pa" label="Protected Area" value={protectedArea} onChange={event => setProtectedArea(event.target.value)}><option value="">All Protected Areas</option>{(filterOptions.protectedAreas || []).map(value => <option key={value} value={value}>{value}</option>)}</FloatingSelect>
                    <FloatingSelect id="final-archive-office" label="Originating office" value={office} onChange={event => setOffice(event.target.value)}><option value="">All offices</option>{(filterOptions.offices || []).map(value => <option key={value} value={value}>{value}</option>)}</FloatingSelect>
                    <FloatingSelect id="final-archive-year" label="Reporting year" value={year} onChange={event => setYear(event.target.value)}><option value="">All years</option>{(filterOptions.years || []).map(value => <option key={value} value={value}>{value}</option>)}</FloatingSelect>
                </div>
            </FilterToolbar>
        </div>
        <div role="tablist" aria-label="Final archive status" className="mt-4 flex gap-2 border-b border-slate-200 dark:border-slate-700">
            <button type="button" role="tab" aria-selected={tab === 'archives'} onClick={() => setTab('archives')} className={`border-b-2 px-3 py-2 text-sm font-semibold ${tab === 'archives' ? 'border-emerald-700 text-emerald-800 dark:text-emerald-300' : 'border-transparent text-slate-500'}`}>Verified Archives ({archives.total || 0})</button>
            {awaitingArchive.total > 0 && <button type="button" role="tab" aria-selected={tab === 'awaiting'} onClick={() => setTab('awaiting')} className={`border-b-2 px-3 py-2 text-sm font-semibold ${tab === 'awaiting' ? 'border-emerald-700 text-emerald-800 dark:text-emerald-300' : 'border-transparent text-slate-500'}`}>Awaiting Archive ({awaitingArchive.total || 0})</button>}
            {awaitingReceipt.total > 0 && <button type="button" role="tab" aria-selected={tab === 'receipt'} onClick={() => setTab('receipt')} className={`border-b-2 px-3 py-2 text-sm font-semibold ${tab === 'receipt' ? 'border-emerald-700 text-emerald-800 dark:text-emerald-300' : 'border-transparent text-slate-500'}`}>Awaiting Final Receipt ({awaitingReceipt.total || 0})</button>}
        </div>
        <div className="mt-4">
            <CrudTable compactEmpty preserveFrameWhenEmpty title={tab === 'awaiting' ? 'Awaiting Archive' : tab === 'receipt' ? 'Awaiting Final Receipt' : 'Verified final returned copies'} subtitle={`${tab === 'awaiting' ? awaitingArchive.total || 0 : tab === 'receipt' ? awaitingReceipt.total || 0 : archives.total || 0} ${tab === 'awaiting' ? 'receipt(s) awaiting final archive' : tab === 'receipt' ? 'released report(s) awaiting the PENRO focal receipt' : 'verified final archive(s)'}`} caption="Regional-return final document archives" columns={columns} rows={visibleRows} rowKey="id" onRowClick={openSelected} emptyTitle={tab === 'awaiting' ? 'No receipts awaiting archive' : tab === 'receipt' ? 'No released reports awaiting final receipt' : 'No verified final archives'} emptyDescription={tab === 'awaiting' ? 'Final receipts from Regional Office appear here for the eligible PENRO focal.' : tab === 'receipt' ? 'Eligible released reports appear here for the exact receiving PENRO focal.' : 'Verified final manifests appear here after every required file has passed archive verification.'} pagination={<LaravelPaginationLinks links={(tab === 'awaiting' ? awaitingArchive : tab === 'receipt' ? awaitingReceipt : archives).links || []} />} />
        </div>

        <CrudFormModal open={Boolean(selected) && !modal} mode="edit" icon="archive" title="Final Regional-return documents" subtitle={selected ? `${selected.module} · ${selected.protected_area || selected.office || 'Authorized report'}` : ''} onClose={closeSelected} canSave={false} backLabel="Close" maxWidth="max-w-5xl">
            {selected && <div className="space-y-5">
                <CrudSection title="Report and receipt">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2"><div><dt className="text-xs text-slate-500">Report / workflow</dt><dd className="font-semibold">{selected.module}{selected.workflow_key ? ` · ${selected.workflow_key}` : ''}</dd></div><div><dt className="text-xs text-slate-500">Protected Area / originating office</dt><dd className="font-semibold">{[selected.protected_area, selected.originating_office].filter(Boolean).join(' · ') || '—'}</dd></div><div><dt className="text-xs text-slate-500">Regional release</dt><dd className="font-semibold">{selected.release_occurred_at ? formatManila(selected.release_occurred_at) : 'Release evidence not found'}</dd></div><div><dt className="text-xs text-slate-500">Final receipt</dt><dd className="font-semibold">{selected.receipt?.physically_received_at ? `${formatManila(selected.receipt.physically_received_at)} · ${selected.receipt.receiving_office}` : 'Not received'}</dd></div></dl>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {selected.can_receive_final && <button type="button" onClick={openReceive} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-emerald-800" data-cds-action="true" data-cds-action-variant="primary">Receive Final MOVs from Regional Office</button>}
                        {selected.can_archive_final && !selected.latest_manifest && <button type="button" onClick={openArchive} className="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-emerald-800" data-cds-action="true" data-cds-action-variant="primary">Add to Archive</button>}
                        {selected.can_archive_final && ['PENDING', 'FAILED', 'PROCESSING'].includes(selected.latest_manifest?.status) && <button type="button" onClick={retryArchive} disabled={retryForm.processing} className="rounded-xl bg-amber-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-amber-700 disabled:opacity-60" data-cds-action="true" data-cds-action-variant="primary">{retryForm.processing ? 'Retrying…' : 'Retry Archive'}</button>}
                    </div>
                    {selected.latest_manifest?.status === 'PROCESSING' && <p role="status" className="mt-3 text-xs text-amber-700 dark:text-amber-300">Archive processing is still active. Refresh this report to check its status.</p>}
                </CrudSection>
                {selected.latest_manifest && <CrudSection title="Final archive manifest" subtitle={`Revision ${selected.latest_manifest.revision} · ${selected.latest_manifest.status}${selected.latest_manifest.finalized_at ? ` · ${formatManila(selected.latest_manifest.finalized_at)}` : ''}`}>
                    <div className="space-y-4">{(selected.latest_manifest.files || []).map(file => <div key={file.id} className="space-y-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700"><div className="flex flex-wrap items-center justify-between gap-2"><div><p className="text-sm font-semibold">{file.name}</p><p className="text-xs text-slate-500">{file.mime_type} · {Math.ceil((file.size || 0) / 1024)} KB · {file.status}</p></div>{file.download_url && <a href={file.download_url} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-emerald-800 dark:border-slate-600 dark:text-emerald-300" data-cds-action="true" data-cds-action-variant="primary">Download</a>}</div>{file.preview_url && <FilePreviewPanel file={{ ...file, type: file.mime_type, protected: true }} title="Protected final copy" heightClass="h-[420px]" />}</div>)}</div>
                </CrudSection>}
            </div>}
        </CrudFormModal>

        <CrudFormModal open={modal === 'receive'} icon="check" title="Receive Final MOVs from Regional Office" subtitle={selected ? `${selected.module} · ${selected.receiving_office || ''}` : ''} onClose={closeModal} onSubmit={submitReceipt} processing={receiveForm.processing} errors={receiveForm.errors} saveLabel="Record Final Receipt" maxWidth="max-w-2xl">
            <CrudSection title="Physical receipt">
                <p className="mb-3 text-sm text-slate-600 dark:text-slate-300">Enter the actual date and time the final documents arrived. This records a separate audit receipt and makes no Drive call.</p>
                <label htmlFor="final-regional-receipt-at" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Received date and time</label>
                <input id="final-regional-receipt-at" type="datetime-local" value={receiveForm.data.physically_received_at} onChange={event => receiveForm.setData('physically_received_at', event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-900" required />
                {receiveForm.errors.physically_received_at && <p role="alert" className="mt-1 text-xs text-red-600">{receiveForm.errors.physically_received_at}</p>}
            </CrudSection>
        </CrudFormModal>

        <CrudFormModal open={modal === 'archive'} icon="archive" title="Add final returned set to Archive" subtitle={selected?.final_file_contract ? `${selected.final_file_contract.label} · all files for this official slot` : ''} onClose={closeModal} onSubmit={submitArchive} processing={archiveForm.processing} errors={archiveForm.errors} saveLabel="Add to Archive" maxWidth="max-w-5xl" preview={<FilePreviewPanel file={displayedPreview} title="Selected final copy preview" emptyText="Select a signed copy to preview" heightClass="h-[520px]" />}>
            <CrudSection title="Returned final file set" subtitle="Select every file in the complete returned signed set for this report. The existing source has one official attachment slot; unsigned source and unrelated correction/reference files are not copied.">
                <AttachmentDropzone id="final-return-upload" label={selected?.final_file_contract?.label || 'Final signed MOV'} files={archiveForm.data.final_files} onChange={files => { const next = Array.isArray(files) ? files : (files ? [files] : []); archiveForm.setData({ ...archiveForm.data, final_files: next, signature_confirmation: false }); const file = next[0]; setPreviewFile(file ? { name: file.name, type: file.type || 'application/octet-stream', url: URL.createObjectURL(file) } : null); }} onSelectFile={file => { if (file) setPreviewFile({ name: file.name, type: file.type || 'application/octet-stream', url: URL.createObjectURL(file) }); }} accept={(selected?.final_file_contract?.extensions || []).map(extension => `.${extension}`).join(',')} maxSizeBytes={(selected?.final_file_contract?.max_kilobytes || 0) * 1024} multiple required disabled={archiveForm.processing} acceptedTypesHint={(selected?.final_file_contract?.extensions || []).map(value => value.toUpperCase()).join(', ')} maxSizeHint={`Maximum ${Math.ceil((selected?.final_file_contract?.max_kilobytes || 0) / 1024)} MB per file`} error={archiveForm.errors['final_files.0'] || archiveForm.errors.final_files} />
                <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"><input type="checkbox" disabled={archiveForm.processing} checked={Boolean(archiveForm.data.signature_confirmation)} onChange={event => archiveForm.setData('signature_confirmation', event.target.checked)} className="mt-0.5 rounded border-amber-400 text-emerald-700 focus:ring-emerald-600 disabled:opacity-50" /><span>I reviewed this complete returned file set and confirm its signatures are complete. CDS-SMART records this confirmation against the selected files; it does not cryptographically validate signatures or add a Regional Office verdict.</span></label>
                {archiveForm.errors.signature_confirmation && <p role="alert" className="mt-1 text-xs text-red-600">{archiveForm.errors.signature_confirmation}</p>}
            </CrudSection>
        </CrudFormModal>
    </AuthenticatedLayout>;
}
