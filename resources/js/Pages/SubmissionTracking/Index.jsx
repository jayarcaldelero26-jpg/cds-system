import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import CrudTable from '@/Components/Crud/CrudTable';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PambRoutingTimeline from '@/Components/SubmissionTracking/PambRoutingTimeline';
import PambMovProgress from '@/Components/SubmissionTracking/PambMovProgress';
import DocumentPreviewDialog from '@/Components/SubmissionTracking/DocumentPreviewDialog';
import PremiumTimePicker from '@/Components/PremiumTimePicker';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import TimelinessBadge, { isTimelinessValue } from '@/Components/TimelinessBadge';
import { localDateInputValue } from '@/Utils/dateInput';
import { formatReportDate, formatReportDateTime } from '@/Utils/dateFormatters';
import { localDateTimeInputValue } from '@/Utils/timePicker';
import { useEffect, useMemo, useState } from 'react';

const tabs = [
    ['cenro_release', 'CENRO Release'],
    ['penro_receipt', 'PENRO Receipt'],
    ['regional_endorsement', 'Regional Endorsement'],
    ['history', 'History'],
];
const FALLBACK = '\u2014';
const plainDate = value => formatReportDate(value, FALLBACK);
const historyDate = value => formatReportDate(value, FALLBACK);
const timelineDate = (row, field, formatter = plainDate) => field === 'date_report_released_cenro' && row.cenro_release_applicable === false
    ? `Not Applicable ${FALLBACK} PENRO-managed Protected Area`
    : formatter(row[field]);
const Badge = ({ value }) => isTimelinessValue(value) ? <TimelinessBadge value={value} /> : <span className="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-800 dark:bg-green-950/60 dark:text-green-200">{value || FALLBACK}</span>;
const MovBadge = ({ value }) => <span className="inline-flex max-w-36 items-center justify-center rounded-full bg-green-100 px-2.5 py-1 text-center text-xs font-medium leading-4 text-green-800 dark:bg-green-950/60 dark:text-green-200">{value === 'Complete' ? 'MOV Submitted' : value || FALLBACK}</span>;
const historyWidths = {
    100: 'w-[100px] min-w-[100px]',
    120: 'w-[120px] min-w-[120px]',
    135: 'w-[135px] min-w-[135px]',
    150: 'w-[150px] min-w-[150px]',
    160: 'w-[160px] min-w-[160px]',
    180: 'w-[180px] min-w-[180px]',
    190: 'w-[190px] min-w-[190px]',
    220: 'w-[220px] min-w-[220px]',
};
const historyHeader = width => `${historyWidths[width]} px-4 py-3 align-middle text-xs font-semibold leading-4`;
const historyCell = width => `${historyWidths[width]} px-4 py-3 align-top text-sm leading-5`;

export default function Index({ queues = {}, filters = {}, filterOptions = {}, trackingContext = {} }) {
    const { props: pageProps } = usePage();
    const canCorrectSubmissionRouting = Boolean(pageProps.auth?.canCorrectSubmissionRouting);
    const cenroTabs = [['for_submission', 'For Submission'], ['for_review', 'For Review'], ['needs_correction', 'Needs Correction'], ['for_release', 'For Release'], ['release_history', 'Release History']];
    const pamoTabs = [['for_submission', 'My MOV Compliance'], ['needs_correction', 'Needs Correction'], ['release_history', 'History']];
    const visibleTabs = trackingContext.is_cenro_user ? cenroTabs : trackingContext.is_pamo_user ? pamoTabs : tabs;
    const [tab, setTab] = useState(trackingContext.is_cenro_user || trackingContext.is_pamo_user ? 'for_submission' : 'cenro_release');
    const [search, setSearch] = useState(filters.search || '');
    const [module, setModule] = useState(filters.module || '');
    const [protectedAreaId, setProtectedAreaId] = useState(filters.protected_area_id || '');
    const [selected, setSelected] = useState(null);
    const [details, setDetails] = useState(null);
    const form = useForm({ date: localDateInputValue(), stage: '' });
    const internalForm = useForm({ remarks: '', stage: '' });
    const correctionForm = useForm({ dates: {}, release_events: {}, internal_events: {}, reason: '', password: '' });
    const reviewForm = useForm({ decision: '', remarks: '' });
    const [correction, setCorrection] = useState(null);
    const [routingStage, setRoutingStage] = useState(null);
    const [previewRow, setPreviewRow] = useState(null);
    const [reviewing, setReviewing] = useState(null);
    const rows = queues[tab] || [];
    const displayDate = tab === 'history' ? historyDate : plainDate;
    const action = tab === 'cenro_release' ? ['Record CENRO Release', 'Record CENRO release date', 'Date CENRO released the report / MOV', 'Save Release Date'] : tab === 'penro_receipt' ? ['Record PENRO Receipt', 'Record PENRO receipt date', 'Date PENRO received the report / MOV', 'Save Receipt Date'] : ['Record Regional Endorsement', 'Record Regional endorsement date', 'Date report / MOV was endorsed to the Regional Office', 'Save Endorsement Date'];
    const columns = useMemo(() => [
        { key: 'module', label: 'Module', headerClassName: tab === 'history' ? historyHeader(160) : '', cellClassName: tab === 'history' ? `${historyCell(160)} whitespace-nowrap` : '', render: row => <span className={tab === 'history' ? 'font-semibold text-slate-900 dark:text-white' : 'font-bold text-gray-900 dark:text-white'}>{row.module}{row.routing_corrections_count > 0 && <span className="ml-2 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">Corrected</span>}</span> },
        { key: 'target_office', label: 'Target Office', headerClassName: tab === 'history' ? historyHeader(150) : '', cellClassName: tab === 'history' ? `${historyCell(150)} whitespace-nowrap` : '', render: row => row.target_office || FALLBACK },
        { key: 'protected_area', label: 'Protected Area', headerClassName: tab === 'history' ? historyHeader(220) : '', cellClassName: tab === 'history' ? `${historyCell(220)} max-w-[220px]` : '', render: row => <span className={tab === 'history' ? 'block max-h-[3.75rem] overflow-hidden' : ''}>{row.protected_area || FALLBACK}</span> },
        { key: 'activity_name', label: 'Activity', headerClassName: tab === 'history' ? historyHeader(190) : '', cellClassName: tab === 'history' ? `${historyCell(190)} max-w-[190px]` : '', render: row => <span className={tab === 'history' ? 'block max-h-10 overflow-hidden' : ''}>{row.activity_name || FALLBACK}</span> },
        { key: 'document_type', label: 'Document Type', headerClassName: tab === 'history' ? historyHeader(150) : '', cellClassName: tab === 'history' ? `${historyCell(150)} max-w-[150px]` : '', render: row => <span className={tab === 'history' ? 'block max-h-10 overflow-hidden' : ''}>{row.document_type || FALLBACK}</span> },
        { key: 'reporting_period', label: 'Reporting Period', headerClassName: tab === 'history' ? historyHeader(120) : '', cellClassName: tab === 'history' ? `${historyCell(120)} whitespace-nowrap` : '', render: row => row.reporting_period || FALLBACK },
        { key: 'date_accomplished', label: 'Date Accomplished', headerClassName: tab === 'history' ? historyHeader(135) : '', cellClassName: tab === 'history' ? `${historyCell(135)} whitespace-nowrap` : '', render: row => displayDate(row.date_accomplished) },
        { key: 'deadline_submission', label: 'Deadline', headerClassName: tab === 'history' ? historyHeader(135) : '', cellClassName: tab === 'history' ? `${historyCell(135)} whitespace-nowrap` : '', render: row => displayDate(row.deadline_submission) },
        ...(tab === 'penro_receipt' ? [{ key: 'date_report_released_cenro', label: 'CENRO Released', render: row => timelineDate(row, 'date_report_released_cenro', displayDate) }] : []),
        ...(tab === 'regional_endorsement' ? [{ key: 'date_report_released_cenro', label: 'CENRO Released', render: row => timelineDate(row, 'date_report_released_cenro', displayDate) }, { key: 'date_received_penro', label: 'PENRO Received', render: row => displayDate(row.date_received_penro) }] : []),
        ...(trackingContext.is_cenro_user ? [{ key: 'mov_progress', label: 'MOV Progress', render: row => row.mov_processing?.applicable ? `${row.mov_processing.percent}% · ${row.mov_processing.status_label}` : FALLBACK }] : [{ key: 'mov_progress', label: 'PAMB MOV Progress', render: row => row.mov_processing?.applicable ? `${row.mov_processing.percent}%` : FALLBACK }, { key: 'processing_stage', label: 'Current Processing Stage', render: row => row.mov_processing?.applicable ? row.mov_processing.status_label : FALLBACK }, { key: 'pending_stage', label: 'Pending at Stage', render: row => row.mov_processing?.applicable && row.mov_processing.working_days_at_current_stage !== null ? `${row.mov_processing.working_days_at_current_stage} working day${row.mov_processing.working_days_at_current_stage === 1 ? '' : 's'}` : FALLBACK }]),
        ...(tab === 'history' ? [
            { key: 'date_report_released_cenro', label: 'CENRO Released', headerClassName: historyHeader(180), cellClassName: `${historyCell(180)} whitespace-nowrap`, render: row => timelineDate(row, 'date_report_released_cenro', displayDate) },
            { key: 'date_received_penro', label: 'PENRO Received', headerClassName: historyHeader(180), cellClassName: `${historyCell(180)} whitespace-nowrap`, render: row => displayDate(row.date_received_penro) },
            { key: 'date_endorsed_regional', label: 'Regional Endorsed', headerClassName: historyHeader(180), cellClassName: `${historyCell(180)} whitespace-nowrap`, render: row => displayDate(row.date_endorsed_regional) },
            { key: 'mov_status', label: 'MOV Status', headerClassName: historyHeader(150), cellClassName: historyCell(150), render: row => <MovBadge value={row.mov_status} /> },
        ] : trackingContext.is_cenro_user ? [{ key: 'turnaround', label: 'Turnaround', render: row => row.mov_processing?.turnaround?.label || FALLBACK }] : [{ key: 'submission_status', label: 'Current Routing Stage', render: row => <Badge value={row.submission_status} /> }, { key: 'current_document_location', label: 'Current Document Location', render: row => row.current_document_location || FALLBACK }, { key: 'timeliness', label: 'Timeliness', render: row => <Badge value={row.timeliness} /> }]),
        { key: 'source', label: 'Source', headerClassName: tab === 'history' ? historyHeader(100) : '', cellClassName: tab === 'history' ? `${historyCell(100)} whitespace-nowrap` : '', render: row => <Link href={row.source_url} className="text-xs font-semibold text-green-700 hover:underline dark:text-green-300">View Source</Link> },
    ], [tab, trackingContext.is_cenro_user]);
    useEffect(() => setSearch(filters.search || ''), [filters.search]);
    useEffect(() => setModule(filters.module || ''), [filters.module]);
    useEffect(() => setProtectedAreaId(filters.protected_area_id || ''), [filters.protected_area_id]);
    const navigateFilters = changes => router.get(route('submission-tracking.index'), { ...filters, search: search || undefined, module: module || undefined, protected_area_id: protectedAreaId || undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    useEffect(() => {
        if (search === (filters.search || '')) return undefined;
        const timer = window.setTimeout(() => navigateFilters({ search: search || undefined }), 300);
        return () => window.clearTimeout(timer);
    }, [search]);
    useEffect(() => {
        const allRows = Object.values(queues).flat();
        if (selected) {
            const updated = allRows.find(row => row.source === selected.source && row.source_id === selected.source_id);
            if (updated) setSelected(updated);
        }
        if (details) {
            const updated = allRows.find(row => row.source === details.source && row.source_id === details.source_id);
            if (updated) setDetails(updated);
        }
    }, [queues]);
    const submit = event => { event.preventDefault(); form.post(route('submission-tracking.transition', [selected.source, selected.source_id, form.data.stage]), { preserveScroll: true, onSuccess: () => setSelected(null) }); };
    const submitReview = event => { event.preventDefault(); reviewForm.post(route('submission-tracking.mov.review', [reviewing.source, reviewing.source_id]), { preserveScroll: true, onSuccess: () => { setReviewing(null); reviewForm.reset(); } }); };
    const submitInternal = event => { event.preventDefault(); internalForm.post(route('submission-tracking.internal-routing', [details.source, details.source_id, routingStage.key]), { preserveScroll: true, onSuccess: () => { setRoutingStage(null); internalForm.reset(); } }); };
    const submitCorrection = event => { event.preventDefault(); correctionForm.patch(route('submission-tracking.correct-routing', [correction.source, correction.source_id]), { preserveScroll: true, onSuccess: () => { setCorrection(null); correctionForm.reset(); } }); };
    const openCorrection = row => {
        const dates = {};
        ['date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional'].forEach(field => {
            if (Object.prototype.hasOwnProperty.call(row, field)) dates[field] = row[field] || '';
        });
        const releaseEvents = Object.fromEntries((row.release_events || []).map(event => [event.id, event.date_report_released_cenro || '']));
        const internalEvents = Object.fromEntries((row.routing_timeline || [])
            .filter(event => event.is_internal && event.occurred_at)
            .map(event => [event.key, localDateTimeInputValue(event.occurred_at)]));
        correctionForm.setData({ dates, release_events: releaseEvents, internal_events: internalEvents, reason: '', password: '' });
        correctionForm.clearErrors();
        setDetails(null);
        setCorrection(row);
    };

    return <AuthenticatedLayout title="Submission Tracking"><PageHeader title="Submission Tracking" description="Monitor report / MOV routing milestones recorded by staff. These actions record real-world dates; eDATS does not transmit official documents." />
        <div className="mt-6 space-y-5">
            <div className="flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">{visibleTabs.map(([key, label]) => <button key={key} type="button" onClick={() => setTab(key)} className={`rounded-xl px-4 py-2.5 text-xs font-bold transition ${tab === key ? 'bg-green-700 text-white shadow-sm' : 'text-gray-600 hover:bg-green-50 dark:text-gray-300 dark:hover:bg-gray-800'}`}>{label}<span className="ml-2 opacity-75">{(queues[key] || []).length}</span></button>)}</div>
            <div className="grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-3 dark:border-gray-800 dark:bg-gray-900"><FloatingInput id="submission-tracking-search" label="Search" value={search} onChange={event => setSearch(event.target.value)} size="sm" /><FloatingSelect id="submission-tracking-module" label="Module" value={module} onChange={event => { const value = event.target.value; setModule(value); navigateFilters({ module: value || undefined }); }} size="sm"><option value="">All modules</option>{(filterOptions.modules || []).map(value => <option key={value}>{value}</option>)}</FloatingSelect><FloatingSelect id="submission-tracking-area" label="Protected Area" value={protectedAreaId} onChange={event => { const value = event.target.value; setProtectedAreaId(value); navigateFilters({ protected_area_id: value || undefined }); }} size="sm"><option value="">All protected areas</option>{(filterOptions.protectedAreas || []).map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect></div>
            <CrudTable title={visibleTabs.find(([key]) => key === tab)?.[1] || 'Submission Tracking'} subtitle={tab === 'history' || tab === 'release_history' ? 'Completed routing records' : 'Monitoring queue'} helperText={tab === 'history' || tab === 'release_history' ? 'Read-only record of completed monitoring history.' : 'Click a row for Full Details. Actions are available after opening Full Details.'} columns={columns} rows={rows} rowKey={row => `${row.source}-${row.source_id}`} onRowClick={setDetails} emptyTitle="No reports in this queue" emptyDescription="No report submissions currently match this workflow stage and filters." tableClassName={tab === 'history' || tab === 'release_history' ? 'min-w-[2260px]' : 'min-w-[1500px]'} compact />
        </div>
            <CrudDetailsModal open={Boolean(details)} title="Submission Full Details" subtitle={details ? `${details.module || 'Report'} · ${details.protected_area || 'Protected area unavailable'}` : ''} onClose={() => setDetails(null)} canEdit={canCorrectSubmissionRouting} onEdit={() => openCorrection(details)} editLabel="Correct Routing Record" summary={details && <CrudSummaryGrid items={[{ label: 'Module', value: details.module }, { label: 'Protected Area', value: details.protected_area || FALLBACK }, { label: 'Reporting Period', value: details.reporting_period || FALLBACK }, { label: details.mov_processing?.applicable ? 'Workflow Status' : 'Routing Status', render: () => <Badge value={details.mov_processing?.applicable ? details.mov_processing.workflow_status : details.submission_status} /> }, { label: 'Timeliness', render: () => <Badge value={details.timeliness} /> }, ...(details.pamb_routing_applicable ? [{ label: 'Current Document Location', value: details.current_document_location || FALLBACK }] : [])]} />}>
            {details?.mov_processing?.applicable && <PambMovProgress row={details} context={trackingContext} onSubmit={(row, options = {}) => router.post(route('submission-tracking.mov.submit-review', [row.source, row.source_id]), {}, { preserveScroll: true, ...options })} onReview={(row, decision) => { reviewForm.setData({ decision, remarks: '' }); reviewForm.clearErrors(); setReviewing(row); }} onRelease={row => { form.setData({ date: localDateInputValue(), stage: 'cenro_release' }); form.clearErrors(); setSelected(row); }} />}
             {details?.pamb_routing_applicable && trackingContext.can_use_downstream_operations ? <PambRoutingTimeline row={details} onRecord={stage => { internalForm.setData({ remarks: '', stage: stage.key }); internalForm.clearErrors(); setRoutingStage(stage); }} onCanonicalAction={stage => { form.setData({ date: localDateInputValue(), stage: stage.key === 'penro_records_received' ? 'penro_receipt' : 'regional_endorsement' }); form.clearErrors(); setSelected(details); }} /> : <CrudSection title={trackingContext.is_cenro_user || trackingContext.is_pamo_user ? 'History' : 'Canonical Routing Timeline'}><div className="grid gap-3 text-sm sm:grid-cols-3"><div><p className="text-xs text-gray-500">CENRO Release</p><p className="font-semibold">{timelineDate(details || {}, 'date_report_released_cenro')}</p></div>{!trackingContext.is_cenro_user && !trackingContext.is_pamo_user && <><div><p className="text-xs text-gray-500">PENRO Receipt</p><p className="font-semibold">{plainDate(details?.date_received_penro)}</p></div><div><p className="text-xs text-gray-500">Regional Endorsement</p><p className="font-semibold">{plainDate(details?.date_endorsed_regional)}</p></div></>}</div></CrudSection>}
        </CrudDetailsModal>
        <CrudFormModal open={Boolean(selected)} mode="edit" title={action[1]} subtitle="Record the real-world routing event only. eDATS does not electronically transmit the official document." onClose={() => setSelected(null)} onSubmit={submit} processing={form.processing} errors={form.errors} saveLabel={action[3]} maxWidth="max-w-xl"><CrudSection title="Monitoring Event">{selected?.mov_url && <button type="button" onClick={() => setPreviewRow(selected)} className="mb-3 rounded-lg border border-green-700 px-3 py-2 text-xs font-bold text-green-800 hover:bg-green-50 dark:text-green-200">Preview MOV / Report</button>}<p className="mb-3 text-xs text-gray-600 dark:text-gray-300">This date records when the office released, received, or endorsed the report / MOV. Backdating is allowed when chronology is valid.</p><FloatingInput id="submission-tracking-date" label={action[2]} type="date" value={form.data.date} onChange={event => form.setData('date', event.target.value)} error={form.errors.date} /></CrudSection></CrudFormModal>
         <CrudFormModal open={Boolean(routingStage)} mode="edit" title={routingStage?.action_label || `Record ${routingStage?.label || 'Internal Routing Event'}`} subtitle="Record the real-world event only; eDATS does not transmit the document." onClose={() => !internalForm.processing && setRoutingStage(null)} onSubmit={submitInternal} processing={internalForm.processing} errors={internalForm.errors} saveLabel={routingStage?.action_label || 'Save Routing Event'} maxWidth="max-w-xl"><CrudSection title="Document / MOV">{details?.mov_url && <button type="button" onClick={() => setPreviewRow(details)} className="rounded-lg border border-green-700 px-3 py-2 text-xs font-bold text-green-800 dark:text-green-200">Preview Document</button>}<p className="mt-2 text-xs text-gray-600 dark:text-gray-300">{details?.mov_attachment?.name || 'No MOV/report attachment is available for this submission.'}</p><div className="mt-2 grid gap-1 text-xs text-gray-500 dark:text-gray-400 sm:grid-cols-2"><p>Current location: <span className="font-semibold text-gray-800 dark:text-gray-200">{details?.current_document_location || FALLBACK}</span></p><p>Next destination: <span className="font-semibold text-gray-800 dark:text-gray-200">{routingStage?.destination || FALLBACK}</span></p></div>{routingStage?.previous_occurred_at && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Prior event: {formatReportDateTime(routingStage.previous_occurred_at, FALLBACK)}</p>}</CrudSection><CrudSection title="Internal Routing Event"><p className="mb-3 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200">Event time: recorded automatically when saved.</p><FloatingTextarea id="pamb-routing-remarks" label="Remarks / Reference (optional)" rows={3} value={internalForm.data.remarks} onChange={event => internalForm.setData('remarks', event.target.value)} error={internalForm.errors.remarks} /></CrudSection></CrudFormModal>
        <CrudFormModal open={Boolean(reviewing)} mode="edit" title={reviewForm.data.decision === 'needs_correction' ? 'Return MOV/report for Correction' : 'Mark MOV/report Ready for Release'} subtitle="Record the Chief review decision. This is operational monitoring, not an electronic approval chain." onClose={() => !reviewForm.processing && setReviewing(null)} onSubmit={submitReview} processing={reviewForm.processing} errors={reviewForm.errors} saveLabel={reviewForm.data.decision === 'needs_correction' ? 'Return for Correction' : 'Ready for Release'} maxWidth="max-w-xl"><CrudSection title="Chief Review"><FloatingTextarea id="pamb-review-remarks" label={reviewForm.data.decision === 'needs_correction' ? 'Correction remarks' : 'Review remarks (optional)'} required={reviewForm.data.decision === 'needs_correction'} rows={4} value={reviewForm.data.remarks} onChange={event => reviewForm.setData('remarks', event.target.value)} error={reviewForm.errors.remarks} /></CrudSection></CrudFormModal>
        <CrudFormModal open={Boolean(correction)} mode="edit" title="Correct Routing Record" subtitle="Administrative correction. Every changed date is retained in the audit trail." onClose={() => !correctionForm.processing && setCorrection(null)} onSubmit={submitCorrection} processing={correctionForm.processing} errors={correctionForm.errors} saveLabel="Confirm Correction" maxWidth="max-w-xl">
            <CrudSection title="Record / Module"><p className="text-sm font-semibold text-gray-800 dark:text-gray-100">{correction?.module || FALLBACK}</p><p className="mt-1 text-xs text-gray-500">Record ID: {correction ? `${correction.source}-${correction.source_id}` : FALLBACK}</p></CrudSection>
            <CrudSection title="Current Routing Dates"><div className="space-y-3">{Object.prototype.hasOwnProperty.call(correctionForm.data.dates, 'date_report_released_cenro') && <FloatingInput id="correction-cenro-release" label="CENRO Released" type="date" value={correctionForm.data.dates.date_report_released_cenro || ''} onChange={event => correctionForm.setData('dates', { ...correctionForm.data.dates, date_report_released_cenro: event.target.value })} error={correctionForm.errors['dates.date_report_released_cenro']} />}{Object.prototype.hasOwnProperty.call(correctionForm.data.dates, 'date_received_penro') && <FloatingInput id="correction-penro-receipt" label="PENRO Received" type="date" value={correctionForm.data.dates.date_received_penro || ''} onChange={event => correctionForm.setData('dates', { ...correctionForm.data.dates, date_received_penro: event.target.value })} error={correctionForm.errors['dates.date_received_penro']} />}{Object.prototype.hasOwnProperty.call(correctionForm.data.dates, 'date_endorsed_regional') && <FloatingInput id="correction-regional-endorsement" label="Regional Endorsed" type="date" value={correctionForm.data.dates.date_endorsed_regional || ''} onChange={event => correctionForm.setData('dates', { ...correctionForm.data.dates, date_endorsed_regional: event.target.value })} error={correctionForm.errors['dates.date_endorsed_regional']} />}{Object.entries(correctionForm.data.release_events || {}).map(([id, value]) => <FloatingInput key={id} id={`correction-release-event-${id}`} label={`CENRO Release Event ${id}`} type="date" value={value || ''} onChange={event => correctionForm.setData('release_events', { ...correctionForm.data.release_events, [id]: event.target.value })} />)}</div></CrudSection>
            {Object.keys(correctionForm.data.internal_events || {}).length > 0 && <CrudSection title="Current Internal Routing Events"><p className="mb-3 text-xs text-gray-600 dark:text-gray-300">Adjust the time of an existing internal routing event. The event date remains part of the saved value and the original record stays in the correction audit trail.</p><div className="space-y-3">{Object.entries(correctionForm.data.internal_events).map(([stage, value]) => <PremiumTimePicker key={stage} id={`correction-internal-${stage}`} label={stage.replaceAll('_', ' ')} value={value || ''} onChange={nextValue => correctionForm.setData('internal_events', { ...correctionForm.data.internal_events, [stage]: nextValue })} error={correctionForm.errors[`internal_events.${stage}`]} />)}</div></CrudSection>}
            <CrudSection title="Authorization"><div className="space-y-4"><FloatingInput id="correction-reason" label="Correction reason" required value={correctionForm.data.reason} onChange={event => correctionForm.setData('reason', event.target.value)} error={correctionForm.errors.reason} /><FloatingInput id="correction-password" label="Current password" required type="password" value={correctionForm.data.password} onChange={event => correctionForm.setData('password', event.target.value)} error={correctionForm.errors.password} /></div></CrudSection>
        </CrudFormModal>
        <DocumentPreviewDialog open={Boolean(previewRow)} row={previewRow} onClose={() => setPreviewRow(null)} />
    </AuthenticatedLayout>;
}
