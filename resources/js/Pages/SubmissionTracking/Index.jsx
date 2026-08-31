import { FloatingInput, FloatingSelect } from '@/Components/Form';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudTable from '@/Components/Crud/CrudTable';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import TimelinessBadge, { isTimelinessValue } from '@/Components/TimelinessBadge';
import { localDateInputValue } from '@/Utils/dateInput';
import { formatReportDate } from '@/Utils/dateFormatters';
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

export default function Index({ queues = {}, filters = {}, filterOptions = {} }) {
    const { auth = {} } = usePage().props;
    const [tab, setTab] = useState('cenro_release');
    const [search, setSearch] = useState(filters.search || '');
    const [module, setModule] = useState(filters.module || '');
    const [protectedAreaId, setProtectedAreaId] = useState(filters.protected_area_id || '');
    const [selected, setSelected] = useState(null);
    const form = useForm({ date: localDateInputValue(), stage: '' });
    const correctionForm = useForm({ dates: {}, release_events: {}, reason: '', password: '' });
    const [correction, setCorrection] = useState(null);
    const rows = queues[tab] || [];
    const displayDate = tab === 'history' ? historyDate : plainDate;
    const action = tab === 'cenro_release' ? ['Release Report', 'Record CENRO Release', 'Date Report Released by CENRO Records', 'Confirm Release'] : tab === 'penro_receipt' ? ['Receive Report', 'Record PENRO Receipt', 'Date Received by PENRO Records', 'Confirm Receipt'] : ['Endorse to Region', 'Record Regional Endorsement', 'Date Endorsed to Regional Office', 'Confirm Endorsement'];
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
        ...(tab === 'history' ? [
            { key: 'date_report_released_cenro', label: 'CENRO Released', headerClassName: historyHeader(180), cellClassName: `${historyCell(180)} whitespace-nowrap`, render: row => timelineDate(row, 'date_report_released_cenro', displayDate) },
            { key: 'date_received_penro', label: 'PENRO Received', headerClassName: historyHeader(180), cellClassName: `${historyCell(180)} whitespace-nowrap`, render: row => displayDate(row.date_received_penro) },
            { key: 'date_endorsed_regional', label: 'Regional Endorsed', headerClassName: historyHeader(180), cellClassName: `${historyCell(180)} whitespace-nowrap`, render: row => displayDate(row.date_endorsed_regional) },
            { key: 'mov_status', label: 'MOV Status', headerClassName: historyHeader(150), cellClassName: historyCell(150), render: row => <MovBadge value={row.mov_status} /> },
        ] : [{ key: 'submission_status', label: 'Submission Status', render: row => <Badge value={row.submission_status} /> }, { key: 'timeliness', label: 'Timeliness', render: row => <Badge value={row.timeliness} /> }]),
        { key: 'source', label: 'Source', headerClassName: tab === 'history' ? historyHeader(100) : '', cellClassName: tab === 'history' ? `${historyCell(100)} whitespace-nowrap` : '', render: row => <Link href={row.source_url} className="text-xs font-semibold text-green-700 hover:underline dark:text-green-300">View Source</Link> },
        { key: 'action', label: 'Action', render: row => <div className="flex flex-col items-start gap-2">{tab !== 'history' ? (row.can_transition ? <button type="button" onClick={event => { event.stopPropagation(); form.setData({ date: localDateInputValue(), stage: tab }); form.clearErrors(); setSelected(row); }} className="whitespace-nowrap rounded-xl bg-green-700 px-3 py-2 text-xs font-bold text-white hover:bg-green-800">{action[0]}</button> : <span className="text-xs text-gray-500">Read only</span>) : null}{auth.canCorrectSubmissionRouting && <button type="button" onClick={event => { event.stopPropagation(); correctionForm.clearErrors(); correctionForm.setData({ dates: { ...(row.date_report_released_cenro !== null && row.cenro_release_applicable !== false ? { date_report_released_cenro: row.date_report_released_cenro || '' } : {}), date_received_penro: row.date_received_penro || '', ...(row.date_endorsed_regional !== null ? { date_endorsed_regional: row.date_endorsed_regional || '' } : {}) }, release_events: Object.fromEntries((row.release_events || []).map((item, index) => [item.id || item.period_component || index, item.date_report_released_cenro || ''])), reason: '', password: '' }); setCorrection(row); }} className="whitespace-nowrap rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800 hover:bg-amber-100">Correct Routing Record</button>}</div> },
    ], [tab, action[0], auth.canCorrectSubmissionRouting]);
    useEffect(() => setSearch(filters.search || ''), [filters.search]);
    useEffect(() => setModule(filters.module || ''), [filters.module]);
    useEffect(() => setProtectedAreaId(filters.protected_area_id || ''), [filters.protected_area_id]);
    const navigateFilters = changes => router.get(route('submission-tracking.index'), { ...filters, search: search || undefined, module: module || undefined, protected_area_id: protectedAreaId || undefined, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    useEffect(() => {
        if (search === (filters.search || '')) return undefined;
        const timer = window.setTimeout(() => navigateFilters({ search: search || undefined }), 300);
        return () => window.clearTimeout(timer);
    }, [search]);
    const submit = event => { event.preventDefault(); form.post(route('submission-tracking.transition', [selected.source, selected.source_id, tab]), { preserveScroll: true, onSuccess: () => setSelected(null) }); };
    const submitCorrection = event => { event.preventDefault(); correctionForm.patch(route('submission-tracking.correct-routing', [correction.source, correction.source_id]), { preserveScroll: true, onSuccess: () => { setCorrection(null); correctionForm.reset(); } }); };

    return <AuthenticatedLayout title="Submission Tracking"><PageHeader title="Submission Tracking" description="Central routing workflow for monitored eDATS report submissions." />
        <div className="mt-6 space-y-5">
            <div className="flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">{tabs.map(([key, label]) => <button key={key} type="button" onClick={() => setTab(key)} className={`rounded-xl px-4 py-2.5 text-xs font-bold transition ${tab === key ? 'bg-green-700 text-white shadow-sm' : 'text-gray-600 hover:bg-green-50 dark:text-gray-300 dark:hover:bg-gray-800'}`}>{label}<span className="ml-2 opacity-75">{(queues[key] || []).length}</span></button>)}</div>
            <div className="grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-3 dark:border-gray-800 dark:bg-gray-900"><FloatingInput id="submission-tracking-search" label="Search" value={search} onChange={event => setSearch(event.target.value)} size="sm" /><FloatingSelect id="submission-tracking-module" label="Module" value={module} onChange={event => { const value = event.target.value; setModule(value); navigateFilters({ module: value || undefined }); }} size="sm"><option value="">All modules</option>{(filterOptions.modules || []).map(value => <option key={value}>{value}</option>)}</FloatingSelect><FloatingSelect id="submission-tracking-area" label="Protected Area" value={protectedAreaId} onChange={event => { const value = event.target.value; setProtectedAreaId(value); navigateFilters({ protected_area_id: value || undefined }); }} size="sm"><option value="">All protected areas</option>{(filterOptions.protectedAreas || []).map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect></div>
            <CrudTable title={tabs.find(([key]) => key === tab)?.[1] || 'Submission Tracking'} subtitle={tab === 'history' ? 'Completed routing records' : 'Server-authoritative routing queue'} helperText={tab === 'history' ? 'Read-only record of submissions that have completed their required routing process.' : 'Use the action button to record a dated workflow transition.'} columns={columns} rows={rows} rowKey={row => `${row.source}-${row.source_id}`} emptyTitle="No reports in this queue" emptyDescription="No report submissions currently match this workflow stage and filters." tableClassName={tab === 'history' ? 'min-w-[2260px]' : 'min-w-[1500px]'} compact />
        </div>
        <CrudFormModal open={Boolean(selected)} mode="edit" title={action[1]} subtitle="Record the official routing date. Backdating is allowed when chronology is valid." onClose={() => setSelected(null)} onSubmit={submit} processing={form.processing} errors={form.errors} saveLabel={action[3]} maxWidth="max-w-xl"><CrudSection title="Submission Timeline"><FloatingInput id="submission-tracking-date" label={action[2]} type="date" value={form.data.date} onChange={event => form.setData('date', event.target.value)} error={form.errors.date} /></CrudSection></CrudFormModal>
        <CrudFormModal open={Boolean(correction)} mode="edit" title="Correct Routing Record" subtitle="Administrative correction. Every changed date is retained in the audit trail." onClose={() => !correctionForm.processing && setCorrection(null)} onSubmit={submitCorrection} processing={correctionForm.processing} errors={correctionForm.errors} saveLabel="Confirm Correction" maxWidth="max-w-xl">
            <CrudSection title="Record / Module"><p className="text-sm font-semibold text-gray-800 dark:text-gray-100">{correction?.module || FALLBACK}</p><p className="mt-1 text-xs text-gray-500">Record ID: {correction ? `${correction.source}-${correction.source_id}` : FALLBACK}</p></CrudSection>
            <CrudSection title="Current Routing Dates"><div className="space-y-3">{Object.prototype.hasOwnProperty.call(correctionForm.data.dates, 'date_report_released_cenro') && <FloatingInput id="correction-cenro-release" label="CENRO Released" type="date" value={correctionForm.data.dates.date_report_released_cenro || ''} onChange={event => correctionForm.setData('dates', { ...correctionForm.data.dates, date_report_released_cenro: event.target.value })} error={correctionForm.errors['dates.date_report_released_cenro']} />}{Object.prototype.hasOwnProperty.call(correctionForm.data.dates, 'date_received_penro') && <FloatingInput id="correction-penro-receipt" label="PENRO Received" type="date" value={correctionForm.data.dates.date_received_penro || ''} onChange={event => correctionForm.setData('dates', { ...correctionForm.data.dates, date_received_penro: event.target.value })} error={correctionForm.errors['dates.date_received_penro']} />}{Object.prototype.hasOwnProperty.call(correctionForm.data.dates, 'date_endorsed_regional') && <FloatingInput id="correction-regional-endorsement" label="Regional Endorsed" type="date" value={correctionForm.data.dates.date_endorsed_regional || ''} onChange={event => correctionForm.setData('dates', { ...correctionForm.data.dates, date_endorsed_regional: event.target.value })} error={correctionForm.errors['dates.date_endorsed_regional']} />}{Object.entries(correctionForm.data.release_events || {}).map(([id, value]) => <FloatingInput key={id} id={`correction-release-event-${id}`} label={`CENRO Release Event ${id}`} type="date" value={value || ''} onChange={event => correctionForm.setData('release_events', { ...correctionForm.data.release_events, [id]: event.target.value })} />)}</div></CrudSection>
            <CrudSection title="Authorization"><div className="space-y-4"><FloatingInput id="correction-reason" label="Correction Reason *" value={correctionForm.data.reason} onChange={event => correctionForm.setData('reason', event.target.value)} error={correctionForm.errors.reason} /><FloatingInput id="correction-password" label="Current Password *" type="password" value={correctionForm.data.password} onChange={event => correctionForm.setData('password', event.target.value)} error={correctionForm.errors.password} /></div></CrudSection>
        </CrudFormModal>
    </AuthenticatedLayout>;
}
