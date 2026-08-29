import { FloatingInput, FloatingSelect } from '@/Components/Form';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudTable from '@/Components/Crud/CrudTable';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, router, useForm } from '@inertiajs/react';
import TimelinessBadge, { isTimelinessValue } from '@/Components/TimelinessBadge';
import { localDateInputValue } from '@/Utils/dateInput';
import { useEffect, useMemo, useState } from 'react';

const tabs = [
    ['cenro_release', 'CENRO Release'],
    ['penro_receipt', 'PENRO Receipt'],
    ['regional_endorsement', 'Regional Endorsement'],
    ['history', 'History'],
];
const FALLBACK = '\u2014';
const date = value => value || FALLBACK;
const timelineDate = (row, field) => field === 'date_report_released_cenro' && row.cenro_release_applicable === false
    ? `Not Applicable ${FALLBACK} PENRO-managed Protected Area`
    : date(row[field]);
const Badge = ({ value }) => isTimelinessValue(value) ? <TimelinessBadge value={value} /> : <span className="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-800 dark:bg-green-950/60 dark:text-green-200">{value || FALLBACK}</span>;

export default function Index({ queues = {}, filters = {}, filterOptions = {} }) {
    const [tab, setTab] = useState('cenro_release');
    const [search, setSearch] = useState(filters.search || '');
    const [module, setModule] = useState(filters.module || '');
    const [protectedAreaId, setProtectedAreaId] = useState(filters.protected_area_id || '');
    const [selected, setSelected] = useState(null);
    const form = useForm({ date: localDateInputValue(), stage: '' });
    const rows = queues[tab] || [];
    const action = tab === 'cenro_release' ? ['Release Report', 'Record CENRO Release', 'Date Report Released by CENRO Records', 'Confirm Release'] : tab === 'penro_receipt' ? ['Receive Report', 'Record PENRO Receipt', 'Date Received by PENRO Records', 'Confirm Receipt'] : ['Endorse to Region', 'Record Regional Endorsement', 'Date Endorsed to Regional Office', 'Confirm Endorsement'];
    const columns = useMemo(() => [
        { key: 'module', label: 'Module', render: row => <span className="font-bold text-gray-900 dark:text-white">{row.module}</span> },
        { key: 'target_office', label: 'Target Office', render: row => row.target_office || FALLBACK },
        { key: 'protected_area', label: 'Protected Area', render: row => row.protected_area || FALLBACK },
        { key: 'activity_name', label: 'Activity', render: row => row.activity_name || FALLBACK },
        { key: 'document_type', label: 'Document Type', render: row => row.document_type || FALLBACK },
        { key: 'reporting_period', label: 'Reporting Period', render: row => row.reporting_period || FALLBACK },
        { key: 'date_accomplished', label: 'Date Accomplished', render: row => date(row.date_accomplished) },
        { key: 'deadline_submission', label: 'Deadline', render: row => date(row.deadline_submission) },
        ...(tab === 'penro_receipt' ? [{ key: 'date_report_released_cenro', label: 'CENRO Released', render: row => timelineDate(row, 'date_report_released_cenro') }] : []),
        ...(tab === 'regional_endorsement' ? [{ key: 'date_report_released_cenro', label: 'CENRO Released', render: row => timelineDate(row, 'date_report_released_cenro') }, { key: 'date_received_penro', label: 'PENRO Received', render: row => date(row.date_received_penro) }] : []),
        ...(tab === 'history' ? [{ key: 'date_report_released_cenro', label: 'CENRO Released', render: row => timelineDate(row, 'date_report_released_cenro') }, { key: 'date_received_penro', label: 'PENRO Received', render: row => date(row.date_received_penro) }, { key: 'date_endorsed_regional', label: 'Regional Endorsed', render: row => date(row.date_endorsed_regional) }, { key: 'mov_status', label: 'MOV Status', render: row => <Badge value={row.mov_status} /> }] : [{ key: 'submission_status', label: 'Submission Status', render: row => <Badge value={row.submission_status} /> }, { key: 'timeliness', label: 'Timeliness', render: row => <Badge value={row.timeliness} /> }]),
        { key: 'source', label: 'Source', render: row => <Link href={row.source_url} className="text-xs font-bold text-green-700 hover:underline dark:text-green-300">View Source</Link> },
        ...(tab === 'history' ? [] : [{ key: 'action', label: 'Action', render: row => row.can_transition ? <button type="button" onClick={event => { event.stopPropagation(); form.setData({ date: localDateInputValue(), stage: tab }); form.clearErrors(); setSelected(row); }} className="whitespace-nowrap rounded-xl bg-green-700 px-3 py-2 text-xs font-bold text-white hover:bg-green-800">{action[0]}</button> : <span className="text-xs text-gray-500">Read only</span> }]),
    ], [tab, action[0]]);
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

    return <AuthenticatedLayout title="Submission Tracking"><PageHeader title="Submission Tracking" description="Central routing workflow for Protected Area Management and Development report submissions." />
        <div className="mt-6 space-y-5">
            <div className="flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">{tabs.map(([key, label]) => <button key={key} type="button" onClick={() => setTab(key)} className={`rounded-xl px-4 py-2.5 text-xs font-bold transition ${tab === key ? 'bg-green-700 text-white shadow-sm' : 'text-gray-600 hover:bg-green-50 dark:text-gray-300 dark:hover:bg-gray-800'}`}>{label}<span className="ml-2 opacity-75">{(queues[key] || []).length}</span></button>)}</div>
            <div className="grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-3 dark:border-gray-800 dark:bg-gray-900"><FloatingInput id="submission-tracking-search" label="Search" value={search} onChange={event => setSearch(event.target.value)} size="sm" /><FloatingSelect id="submission-tracking-module" label="Module" value={module} onChange={event => { const value = event.target.value; setModule(value); navigateFilters({ module: value || undefined }); }} size="sm"><option value="">All modules</option>{(filterOptions.modules || []).map(value => <option key={value}>{value}</option>)}</FloatingSelect><FloatingSelect id="submission-tracking-area" label="Protected Area" value={protectedAreaId} onChange={event => { const value = event.target.value; setProtectedAreaId(value); navigateFilters({ protected_area_id: value || undefined }); }} size="sm"><option value="">All protected areas</option>{(filterOptions.protectedAreas || []).map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect></div>
            <CrudTable title={tabs.find(([key]) => key === tab)?.[1] || 'Submission Tracking'} subtitle={tab === 'history' ? 'Read-only routing audit trail' : 'Server-authoritative routing queue'} helperText={tab === 'history' ? 'Routing dates are read only in History.' : 'Use the action button to record a dated workflow transition.'} columns={columns} rows={rows} rowKey={row => `${row.source}-${row.source_id}`} emptyTitle="No reports in this queue" emptyDescription="No report submissions currently match this workflow stage and filters." tableClassName="min-w-[1500px]" />
        </div>
        <CrudFormModal open={Boolean(selected)} mode="edit" title={action[1]} subtitle="Record the official routing date. Backdating is allowed when chronology is valid." onClose={() => setSelected(null)} onSubmit={submit} processing={form.processing} errors={form.errors} saveLabel={action[3]} maxWidth="max-w-xl"><CrudSection title="Submission Timeline"><FloatingInput id="submission-tracking-date" label={action[2]} type="date" value={form.data.date} onChange={event => form.setData('date', event.target.value)} error={form.errors.date} /></CrudSection></CrudFormModal>
    </AuthenticatedLayout>;
}
