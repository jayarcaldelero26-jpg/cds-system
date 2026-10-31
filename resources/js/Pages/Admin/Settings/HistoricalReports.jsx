import { Head, Link, router, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createUuid } from '@/Utils/createUuid.mjs';
import SettingsShell from '@/Components/Admin/SettingsShell';
import SettingsPageHeader from '@/Components/Admin/SettingsPageHeader';
import Button from '@/Components/Button';
import Card from '@/Components/Card';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudTable from '@/Components/Crud/CrudTable';
import DatePicker from '@/Components/DatePicker';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';
import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';
import HistoricalRecordBadge from '@/Components/HistoricalRecordBadge';
import HistoricalReportDetail from './HistoricalReportDetail';
import HistoricalReportDetailSkeleton from './HistoricalReportDetailSkeleton';
import { createHistoricalDetailRequestGuard, createHistoricalDetailTrace, fetchHistoricalDetail, historicalRecordIdentity } from './historicalDetailRequest.mjs';

const empty = { workflow_id: '', protected_area_id: '', target_office: '', activity_name: '', document_type: '', reporting_period: '', date_conducted: '', date_accomplished: '', date_conducted_ranges: [], semester: '', reporting_year: String(new Date().getFullYear()), period_key: '', office: '', section_name: '', quarter: '', report_period_type: '', reporting_month: '', monitoring_period_start: '', monitoring_period_end: '', station_name: '', location: '', total_collected: '', deadline_submission: '', management_plan_type_id: '', original_date_report_released_cenro: '', original_date_received_penro: '', original_date_endorsed_regional: '', remarks: '', mov: null, report_file: null, attachments: [], idempotency_key: '' };
const sourceLabels = { conservation: 'Conservation', engp: 'ENGP', bms: 'BMS', bams: 'BAMS', imea: 'IMEA', 'imea-maintenance': 'IMEA Maintenance', aws: 'AWS', 'ipaf-management': 'IPAF Management', revenue: 'Revenue Collection', 'management-plans': 'Management Plans' };
const fileFor = kind => kind === 'aws' ? 'report_file' : kind === 'management-plans' ? 'attachments' : 'mov';
const dateField = (id, label, value, onChange, error, required = false, actualDate = true, helperText = '') => <DatePicker id={id} label={label} value={value || ''} onChange={onChange} error={error} required={required} actualDate={actualDate} helperText={helperText} />;

export default function HistoricalReports({ catalog = {}, records = [], filters = {}, canEncode = false, success = null }) {
    const [open, setOpen] = useState(false);
    const [selectedRecord, setSelectedRecord] = useState(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [detailRecord, setDetailRecord] = useState(null);
    const [detailState, setDetailState] = useState('idle');
    const [detailError, setDetailError] = useState('');
    const [detailRetry, setDetailRetry] = useState(0);
    const [previewOpen, setPreviewOpen] = useState(false);
    const detailRequestGuardRef = useRef(null);
    const detailControllerRef = useRef(null);
    const detailTriggerRef = useRef(null);
    const detailTraceRef = useRef(null);
    if (!detailRequestGuardRef.current) detailRequestGuardRef.current = createHistoricalDetailRequestGuard();
    const selectedIdentity = historicalRecordIdentity(selectedRecord);
    const form = useForm(empty);
    const [preview, setPreview] = useState(null);
    const [periods, setPeriods] = useState([]);
    const [periodsLoading, setPeriodsLoading] = useState(false);
    const workflow = useMemo(() => catalog.workflows?.find(item => String(item.id) === String(form.data.workflow_id)) || null, [catalog.workflows, form.data.workflow_id]);
    const selectedArea = catalog.protectedAreas?.find(item => String(item.id) === String(form.data.protected_area_id));
    const fileField = fileFor(workflow?.kind);

    useEffect(() => {
        if (!selectedRecord || !selectedIdentity) {
            setDetailRecord(null);
            setDetailState('idle');
            setDetailError('');
            return undefined;
        }
        const trace = detailTraceRef.current?.identity === selectedIdentity ? detailTraceRef.current : null;
        trace?.mark('detail_effect_start');
        const controller = new AbortController();
        detailControllerRef.current = controller;
        const requestToken = detailRequestGuardRef.current.begin();
        setDetailRecord(null);
        setDetailState('loading');
        setDetailError('');
        const requestUrl = trace?.withDiagnostic(selectedRecord.url) || selectedRecord.url;
        fetchHistoricalDetail(requestUrl, { signal: controller.signal, expectedIdentity: selectedIdentity, trace }).then(record => {
            if (controller.signal.aborted) {
                trace?.finish('aborted');
                return;
            }
            if (!detailRequestGuardRef.current.isCurrent(requestToken)) {
                trace?.finish('stale_identity');
                return;
            }
            trace?.mark('request_identity_accepted');
            setDetailRecord(record);
            setDetailState('ready');
        }).catch(error => {
            if (error.name === 'AbortError' || controller.signal.aborted) {
                trace?.finish('aborted');
                return;
            }
            if (!detailRequestGuardRef.current.isCurrent(requestToken)) {
                trace?.finish('stale_identity');
                return;
            }
            setDetailError(error.message || 'The historical details could not be loaded.');
            setDetailState('error');
        });
        return () => {
            controller.abort();
            if (detailControllerRef.current === controller) detailControllerRef.current = null;
            detailRequestGuardRef.current.invalidate();
        };
    }, [selectedIdentity, selectedRecord?.url, detailRetry]);

    useLayoutEffect(() => {
        if (!detailsOpen) return undefined;
        const trace = detailTraceRef.current;
        if (!trace || trace.identity !== selectedIdentity) return undefined;
        trace.mark('modal_shell_commit');
        let firstFrame = null;
        let secondFrame = null;
        firstFrame = window.requestAnimationFrame(() => {
            secondFrame = window.requestAnimationFrame(() => trace.mark('modal_two_frames'));
        });
        return () => {
            if (firstFrame !== null) window.cancelAnimationFrame(firstFrame);
            if (secondFrame !== null) window.cancelAnimationFrame(secondFrame);
        };
    }, [detailsOpen, selectedIdentity]);

    useEffect(() => {
        if (!detailsOpen) return undefined;
        const trace = detailTraceRef.current;
        if (!trace || trace.identity !== selectedIdentity) return undefined;
        const panel = [...document.querySelectorAll('[data-cds-modal-layer] [role="dialog"]')]
            .find(element => element.getAttribute('aria-label') === selectedRecord?.label);
        if (!panel) return undefined;
        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
        const transitionTimes = window.getComputedStyle(panel).transitionDuration.split(',').map(value => {
            const duration = Number.parseFloat(value) || 0;
            return value.trim().endsWith('ms') ? duration : duration * 1000;
        });
        if (reducedMotion || transitionTimes.every(value => value <= 0)) trace.mark('modal_transition_end');
        const markMotion = event => {
            if (event.target !== panel || event.propertyName !== 'transform') return;
            if (event.type === 'transitionrun' || event.type === 'transitionstart') {
                trace.mark('modal_transition_start');
                return;
            }
            trace.mark('modal_transition_end');
            if (trace.hasMark('details_two_frames') || trace.hasMark('error_two_frames')) {
                trace.finish(`${trace.result()}_after_two_frames_and_motion`);
                if (detailTraceRef.current === trace) detailTraceRef.current = null;
            }
        };
        panel.addEventListener('transitionrun', markMotion);
        panel.addEventListener('transitionstart', markMotion);
        panel.addEventListener('transitionend', markMotion);
        panel.addEventListener('transitioncancel', markMotion);
        return () => {
            panel.removeEventListener('transitionrun', markMotion);
            panel.removeEventListener('transitionstart', markMotion);
            panel.removeEventListener('transitionend', markMotion);
            panel.removeEventListener('transitioncancel', markMotion);
        };
    }, [detailsOpen, selectedIdentity, selectedRecord?.label]);

    useLayoutEffect(() => {
        if (!detailsOpen || !['ready', 'error'].includes(detailState)) return undefined;
        const trace = detailTraceRef.current;
        if (!trace || trace.identity !== selectedIdentity || trace.finished()) return undefined;
        const ready = detailState === 'ready' && Boolean(detailRecord);
        trace.mark(ready ? 'details_commit' : 'error_commit');
        trace.setResult(ready ? 'ready' : 'error');
        let firstFrame = null;
        let secondFrame = null;
        firstFrame = window.requestAnimationFrame(() => {
            secondFrame = window.requestAnimationFrame(() => {
                trace.mark(ready ? 'details_two_frames' : 'error_two_frames');
                if (trace.hasMark('modal_transition_end')) trace.finish(`${trace.result()}_after_two_frames_and_motion`);
                if (trace.finished() && detailTraceRef.current === trace) detailTraceRef.current = null;
            });
        });
        return () => {
            if (firstFrame !== null) window.cancelAnimationFrame(firstFrame);
            if (secondFrame !== null) window.cancelAnimationFrame(secondFrame);
        };
    }, [detailsOpen, selectedIdentity, detailState, detailRecord]);

    useEffect(() => {
        if (workflow?.kind !== 'engp' || !form.data.reporting_year) { setPeriods([]); return undefined; }
        let cancelled = false;
        const timer = window.setTimeout(async () => {
            setPeriodsLoading(true);
            try {
                const response = await fetch('/settings/historical-reports/periods', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ workflow_id: workflow.id, reporting_year: form.data.reporting_year }),
                });
                const payload = response.ok ? await response.json() : { periods: [] };
                if (!cancelled) setPeriods(payload.periods || []);
            } catch { if (!cancelled) setPeriods([]); }
            finally { if (!cancelled) setPeriodsLoading(false); }
        }, 180);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [workflow?.id, workflow?.kind, form.data.reporting_year]);

    useEffect(() => {
        if (!open || !workflow) { setPreview(null); return undefined; }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch('/settings/historical-reports/preview', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ ...form.data, workflow_key: workflow.workflow_key }),
                    signal: controller.signal,
                });
                setPreview(response.ok ? await response.json() : null);
            } catch (error) { if (error.name !== 'AbortError') setPreview(null); }
        }, 450);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [open, workflow?.id, form.data.protected_area_id, form.data.target_office, form.data.activity_name, form.data.document_type, form.data.reporting_period, form.data.date_conducted, form.data.date_conducted_ranges, form.data.date_accomplished, form.data.semester, form.data.reporting_year, form.data.period_key, form.data.office, form.data.quarter, form.data.report_period_type, form.data.monitoring_period_start, form.data.monitoring_period_end, form.data.reporting_month, form.data.deadline_submission, form.data.management_plan_type_id]);

    const startEncode = () => {
        form.reset();
        form.clearErrors();
        form.setData({ ...empty, reporting_year: String(new Date().getFullYear()), idempotency_key: createUuid() });
        setPreview(null);
        setOpen(true);
    };
    const set = (field, value) => form.setData(field, value);
    const close = () => { if (!form.processing) { setOpen(false); setPreview(null); } };
    const openDetails = (record, event) => {
        const nextIdentity = historicalRecordIdentity(record);
        if (!nextIdentity) return;
        const activatedAt = Number.isFinite(event?.nativeEvent?.timeStamp) ? event.nativeEvent.timeStamp
            : Number.isFinite(event?.timeStamp) ? event.timeStamp : globalThis.performance?.now?.() ?? null;
        detailTraceRef.current?.finish('superseded_by_activation');
        detailTraceRef.current = createHistoricalDetailTrace({
            source: record.source,
            id: record.id,
            activatedAt,
        });
        event?.currentTarget?.focus?.();
        detailTriggerRef.current = event?.currentTarget || null;
        const sameRecord = nextIdentity === selectedIdentity;
        if (!sameRecord) {
            detailRequestGuardRef.current.invalidate();
            detailControllerRef.current?.abort();
            detailControllerRef.current = null;
            setSelectedRecord(record);
            setDetailRecord(null);
            setDetailError('');
            setDetailState('loading');
        } else if (!detailsOpen && detailState === 'loading') {
            detailRequestGuardRef.current.invalidate();
            setDetailRetry(value => value + 1);
        }
        setDetailsOpen(true);
        setPreviewOpen(false);
    };
    const retryDetails = () => {
        if (selectedRecord) {
            detailTraceRef.current?.finish('superseded_by_retry');
            detailTraceRef.current = createHistoricalDetailTrace({
                source: selectedRecord.source,
                id: selectedRecord.id,
                activatedAt: globalThis.performance?.now?.() ?? null,
            });
        }
        setDetailRetry(value => value + 1);
    };
    const closeDetails = () => {
        detailTraceRef.current?.finish('closed_before_result');
        detailTraceRef.current = null;
        detailRequestGuardRef.current.invalidate();
        detailControllerRef.current?.abort();
        detailControllerRef.current = null;
        setDetailsOpen(false);
        setPreviewOpen(false);
    };
    const finishDetailsClose = () => {
        setSelectedRecord(null);
        setDetailRecord(null);
        setDetailError('');
        setDetailState('idle');
    };
    const submit = event => {
        event.preventDefault();
        if (!workflow || form.processing) return;
        form.post('/settings/historical-reports', { forceFormData: true, preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } });
    };
    const setFilter = (name, value) => router.get('/settings/historical-reports', { ...filters, [name]: value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    const clearFilters = () => router.get('/settings/historical-reports', {}, { preserveState: true, preserveScroll: true, replace: true });
    const filterChips = [
        filters.search ? { key: 'search', label: 'Search', value: filters.search, onRemove: () => setFilter('search', '') } : null,
        filters.source ? { key: 'source', label: 'Module', value: sourceLabels[filters.source] || filters.source, onRemove: () => setFilter('source', '') } : null,
    ].filter(Boolean);
    const workflowOptions = (catalog.workflows || []).map(item => ({ id: String(item.id), label: item.label + (item.description ? ' — ' + item.description : '') }));
    const areaOptions = (catalog.protectedAreas || []).map(area => ({ id: String(area.id), label: area.name + (area.short_name ? ' (' + area.short_name + ')' : '') }));
    const activityOptions = (workflow?.activities || []).map(activity => ({ id: activity, label: activity }));
    const listColumns = [
        { key: 'report', label: 'Report / Activity', render: record => <div className="min-w-48"><span className="font-bold text-gray-900 dark:text-white">{record.label}</span><div className="mt-1"><HistoricalRecordBadge source={record.source} id={record.id} passive /></div></div> },
        { key: 'module', label: 'Module', render: record => sourceLabels[record.source] || record.source },
        { key: 'area', label: 'Protected Area / Office', render: record => record.protected_area || record.office || 'Not recorded' },
        { key: 'period', label: 'Reporting Period', render: record => record.period || 'Not provided' },
        { key: 'encoded', label: 'Encoded', render: record => formatDate(record.encoded_at) },
    ];

    return <SettingsShell active="Historical Reports">
        <Head title="Historical Reports" />
        <SettingsPageHeader title="Historical Reports" description="Encode verified legacy reports with their source-module rules and clearly marked original dates." actions={canEncode && <Button type="button" size="compact" onClick={startEncode} className="gap-2 rounded-lg px-4 py-2.5 text-xs"><Icon icon="lucide:plus" width="15" height="15" aria-hidden="true" />Encode historical report</Button>} />

        <div className="mb-4 grid gap-3 sm:grid-cols-3">
            <InfoCard icon="lucide:archive" title="Historical evidence" text="Manually recorded dates retain their original-date labels and encoder details." />
            <InfoCard icon="lucide:git-branch" title="Live routing stays separate" text="These records are excluded from current queues and do not create custody events." />
            <InfoCard icon="lucide:shield-check" title="Source rules apply" text="Workflow, period, protected-area scope, dates, and attachments follow the selected module." />
        </div>
        {success && <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200"><span><strong>Historical record saved.</strong> {success.label}{success.idempotent ? ' Existing saved entry was returned safely.' : ''}</span><Link href={success.url} className="font-bold underline underline-offset-2">View record</Link></div>}

        <CrudTable
            title="Recently encoded reports"
            subtitle="Historical entries can be reviewed here and in their source module."
            helperText="Historical records remain separate from live Submission Tracking."
            rows={records}
            columns={listColumns}
            rowKey={record => record.source + ':' + record.id}
            onRowClick={openDetails}
            rowFocusVisibleOnly
            emptyTitle="No historical reports found"
            emptyDescription="Try another filter or encode a report when you have verified source information."
            caption="Recently encoded historical reports"
            tableContainerClassName="min-w-0"
            filters={<FilterToolbar label="Filter records" chips={filterChips} onClear={clearFilters}>
                <div className="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1.6fr)_minmax(12rem,1fr)]">
                    <FloatingInput id="historical-search" label="Search reports" value={filters.search || ''} onChange={event => setFilter('search', event.target.value)} placeholder="Search report, PA, or office" />
                    <FloatingSelect id="historical-source" label="Module" value={filters.source || ''} onChange={event => setFilter('source', event.target.value)}>
                        <option value="">All modules</option>{Object.entries(sourceLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </FloatingSelect>
                </div>
            </FilterToolbar>}
        />

        <CrudFormModal
            open={open}
            initialFocusSelector="#historical-workflow"
            title="Encode historical report"
            subtitle="Use verified original dates. Encoding never creates live routing activity."
            icon={<Icon icon="lucide:archive" width="18" height="18" aria-hidden="true" />}
            onClose={close}
            onSubmit={submit}
            processing={form.processing}
            progress={form.progress}
            errors={form.errors}
            saveLabel="Save historical report"
            canSave={Boolean(workflow)}
            maxWidth="max-w-7xl"
            preview={<PreviewPanel preview={preview} workflow={workflow} selectedArea={selectedArea} />}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <ScopedOptionSelect
                        id="historical-workflow"
                        label="Report / Activity"
                        value={form.data.workflow_id}
                        options={workflowOptions}
                        placeholder="Choose a report or activity"
                        required
                        error={form.errors.workflow_id}
                        onChange={value => {
                            const next = catalog.workflows?.find(item => String(item.id) === value);
                            form.setData({ ...empty, reporting_year: String(new Date().getFullYear()), idempotency_key: form.data.idempotency_key, workflow_id: value, activity_name: next?.activity || '', date_conducted_ranges: next && (next.date_ranges || next.kind === 'pa') ? [{ from: '', to: '' }] : [] });
                            form.clearErrors();
                            setPreview(null);
                        }}
                    />
                </div>

                {workflow && <>
                    <SectionHeading>Report details</SectionHeading>
                    {workflow.protected_area_required && <ScopedOptionSelect
                        id="historical-protected-area"
                        label="Protected Area"
                        value={form.data.protected_area_id}
                        options={areaOptions}
                        placeholder="Choose a protected area"
                        required
                        error={form.errors.protected_area_id}
                        onChange={value => {
                            set('protected_area_id', value);
                            const area = catalog.protectedAreas?.find(item => String(item.id) === value);
                            if (area?.supervising_office) set('target_office', area.supervising_office);
                        }}
                    />}

                    {workflow.kind === 'engp' ? <>
                        <FloatingSelect id="historical-cenro" label="CENRO" value={form.data.office} onChange={event => set('office', event.target.value)} required error={form.errors.office}>
                            <option value="">Choose CENRO</option>{(workflow.offices || []).map(office => <option key={office} value={office}>{office}</option>)}
                        </FloatingSelect>
                        <FloatingInput id="historical-engp-year" label="Reporting Year" type="number" min="2000" max="2100" required value={form.data.reporting_year} onChange={event => { set('reporting_year', event.target.value); set('period_key', ''); }} error={form.errors.reporting_year} />
                        <FloatingSelect id="historical-engp-period" label="Reporting Period" value={form.data.period_key} onChange={event => set('period_key', event.target.value)} required error={form.errors.period_key} disabled={periodsLoading}>
                            <option value="">{periodsLoading ? 'Loading periods…' : 'Choose period'}</option>{periods.map(period => <option key={period.key} value={period.key}>{period.label}</option>)}
                        </FloatingSelect>
                        <FloatingInput id="historical-engp-section" label="Section / Unit" value={form.data.section_name} onChange={event => set('section_name', event.target.value)} />
                    </> : <>
                        {workflow.kind !== 'conservation' && <FloatingSelect id="historical-receiving-office" label="Receiving Office" value={form.data.target_office} onChange={event => set('target_office', event.target.value)} required error={form.errors.target_office}>
                            <option value="">Choose receiving office</option>{(catalog.offices || []).map(office => <option key={office.value} value={office.value}>{office.label}</option>)}
                        </FloatingSelect>}
                        {['pa', 'maintenance', 'revenue', 'management-plans'].includes(workflow.kind) && <ScopedOptionSelect
                            id="historical-activity"
                            label="Activity"
                            value={form.data.activity_name}
                            options={activityOptions}
                            placeholder="Choose activity"
                            required
                            error={form.errors.activity_name}
                            onChange={value => set('activity_name', value)}
                        />}
                        {workflow.kind === 'aws' && <>
                            <FloatingInput id="historical-station" label="AWS Station" value={form.data.station_name} onChange={event => set('station_name', event.target.value)} />
                            <FloatingInput id="historical-location" label="Location" value={form.data.location} onChange={event => set('location', event.target.value)} />
                        </>}
                        {workflow.kind === 'management-plans' && <FloatingSelect id="historical-plan-type" label="Management Plan Type" value={form.data.management_plan_type_id} onChange={event => set('management_plan_type_id', event.target.value)} required error={form.errors.management_plan_type_id}>
                            <option value="">Choose plan type</option>{(catalog.managementPlanTypes || []).map(type => <option key={type.id} value={type.id}>{type.name}</option>)}
                        </FloatingSelect>}
                    </>}

                    {workflow.kind === 'conservation' && <>
                        <FloatingSelect id="historical-conservation-office" label="Receiving Office" value={form.data.target_office} onChange={event => set('target_office', event.target.value)} required error={form.errors.target_office}>
                            <option value="">Choose receiving office</option>{(catalog.offices || []).map(office => <option key={office.value} value={office.value}>{office.label}</option>)}
                        </FloatingSelect>
                        <ScopedOptionSelect id="historical-conservation-activity" label="Activity" value={form.data.activity_name} options={activityOptions} placeholder="Choose activity" required error={form.errors.activity_name} onChange={value => set('activity_name', value)} />
                        <FloatingSelect id="historical-conservation-document-type" label="Document Type" value={form.data.document_type} onChange={event => set('document_type', event.target.value)} error={form.errors.document_type}>
                            <option value="">Choose document type</option>{(workflow.activity_documents?.[form.data.activity_name] || workflow.documents || []).map(item => <option key={item} value={item}>{item}</option>)}
                        </FloatingSelect>
                        {workflow.periods?.length > 0
                            ? <FloatingSelect id="historical-conservation-period" label={workflow.period_label || 'Reporting Period'} value={form.data.reporting_period} onChange={event => set('reporting_period', event.target.value)} required={workflow.period_required} error={form.errors.reporting_period}>
                                <option value="">Choose period</option>{workflow.periods.map(item => <option key={item} value={item}>{item}</option>)}
                            </FloatingSelect>
                            : <FloatingInput id="historical-conservation-period" label={workflow.period_label || 'Reporting Period'} value={form.data.reporting_period} onChange={event => set('reporting_period', event.target.value)} error={form.errors.reporting_period} />}
                    </>}

                    {['pa', 'maintenance', 'ipaf-management', 'revenue', 'management-plans'].includes(workflow.kind) && <FloatingSelect id="historical-document-type" label="Document Type" value={form.data.document_type} onChange={event => set('document_type', event.target.value)} required={workflow.kind !== 'pa'} error={form.errors.document_type}>
                        <option value="">Choose document type</option>{['Final Report', 'Progress Report'].map(item => <option key={item} value={item}>{item}</option>)}
                    </FloatingSelect>}
                    {workflow.kind === 'pa' && <FloatingSelect id="historical-pa-semester" label="Semester" value={form.data.semester} onChange={event => set('semester', event.target.value)} required error={form.errors.semester}><option value="">Choose semester</option><option>1st Semester</option><option>2nd Semester</option></FloatingSelect>}
                    {workflow.kind === 'maintenance' && <FloatingSelect id="historical-maintenance-quarter" label="Quarter" value={form.data.quarter} onChange={event => set('quarter', event.target.value)} required error={form.errors.quarter}><option value="">Choose quarter</option>{[1, 2, 3, 4].map(number => <option key={number}>Quarter {number}</option>)}</FloatingSelect>}

                    {workflow.kind === 'aws' && <>
                        <FloatingInput id="historical-aws-year" label="Reporting Year" type="number" min="2000" max="2100" required value={form.data.reporting_year} onChange={event => set('reporting_year', event.target.value)} error={form.errors.reporting_year} />
                        <FloatingSelect id="historical-aws-quarter" label="Quarter" value={form.data.quarter} onChange={event => set('quarter', event.target.value)} required error={form.errors.quarter}><option value="">Choose quarter</option>{[1, 2, 3, 4].map(number => <option key={number} value={number}>Quarter {number}</option>)}</FloatingSelect>
                        <FloatingSelect id="historical-aws-period-type" label="Report Period Type" value={form.data.report_period_type} onChange={event => set('report_period_type', event.target.value)} required error={form.errors.report_period_type}><option value="">Choose period type</option>{['Monthly', 'Quarterly', 'Semestral'].map(value => <option key={value}>{value}</option>)}</FloatingSelect>
                        <FloatingInput id="historical-aws-reporting-period" label="Reporting Period" value={form.data.reporting_period} onChange={event => set('reporting_period', event.target.value)} required error={form.errors.reporting_period} placeholder="Use the module's reporting period" />
                        <FloatingSelect id="historical-aws-document-type" label="Document Type" value={form.data.document_type} onChange={event => set('document_type', event.target.value)} required error={form.errors.document_type}>
                            <option value="">Choose document type</option>{['Final Report', 'Progress Report', 'Final Report (1-15)', 'Final Report (16-30)', 'Final Report (16-28)', 'First-half month report', 'Second-half month report'].map(value => <option key={value}>{value}</option>)}
                        </FloatingSelect>
                        {dateField('historical-monitoring-start', 'Monitoring Period Start', form.data.monitoring_period_start, value => set('monitoring_period_start', value), form.errors.monitoring_period_start, true)}
                        {dateField('historical-monitoring-end', 'Monitoring Period End', form.data.monitoring_period_end, value => set('monitoring_period_end', value), form.errors.monitoring_period_end, true)}
                    </>}

                    {workflow.kind === 'revenue' && <>
                        <FloatingSelect id="historical-revenue-month" label="Reporting Month" value={form.data.reporting_month} onChange={event => set('reporting_month', event.target.value)} required error={form.errors.reporting_month}><option value="">Choose month</option>{Array.from({ length: 12 }, (_, index) => index + 1).map(month => <option key={month} value={month}>{new Date(2000, month - 1, 1).toLocaleString('en', { month: 'long' })}</option>)}</FloatingSelect>
                        <FloatingInput id="historical-revenue-year" label="Reporting Year" type="number" min="2000" max="2100" required value={form.data.reporting_year} onChange={event => set('reporting_year', event.target.value)} error={form.errors.reporting_year} />
                        <FloatingInput id="historical-total-collected" label="Total Collected" type="number" min="0" step="0.01" required value={form.data.total_collected} onChange={event => set('total_collected', event.target.value)} error={form.errors.total_collected} />
                        {dateField('historical-revenue-deadline', 'Module Deadline', form.data.deadline_submission, value => set('deadline_submission', value), form.errors.deadline_submission, true, false, 'Enter the deadline used by the original monthly revenue report.')}
                    </>}

                    {['conservation', 'pa'].includes(workflow.kind) && (workflow.date_ranges || workflow.kind === 'pa')
                        ? <div className="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                            {dateField('historical-conducted-from', 'Date Conducted — From', form.data.date_conducted_ranges?.[0]?.from, value => set('date_conducted_ranges', [{ ...(form.data.date_conducted_ranges?.[0] || {}), from: value }]), form.errors['date_conducted_ranges.0.from'], true)}
                            {dateField('historical-conducted-to', 'Date Conducted — To (optional)', form.data.date_conducted_ranges?.[0]?.to, value => set('date_conducted_ranges', [{ ...(form.data.date_conducted_ranges?.[0] || {}), to: value }]), form.errors['date_conducted_ranges.0.to'])}
                        </div>
                        : null}

                    {['conservation', 'maintenance', 'aws', 'ipaf-management', 'management-plans'].includes(workflow.kind) && dateField('historical-date-conducted', 'Date Conducted', form.data.date_conducted, value => set('date_conducted', value), form.errors.date_conducted, workflow.kind === 'conservation' ? Boolean(workflow.date_conducted_required) : workflow.kind !== 'aws')}
                    {!['engp', 'revenue'].includes(workflow.kind) && dateField('historical-date-accomplished', 'Date Accomplished', form.data.date_accomplished, value => set('date_accomplished', value), form.errors.date_accomplished, !['conservation', 'aws'].includes(workflow.kind))}
                    {workflow.kind === 'management-plans' && <FloatingSelect id="historical-plan-semester" label="Semester" value={form.data.semester} onChange={event => set('semester', event.target.value)} required error={form.errors.semester}><option value="">Choose semester</option><option>1st Semester</option><option>2nd Semester</option></FloatingSelect>}

                    {workflow.kind === 'engp' && <>
                        {dateField('historical-cenro-release', 'Original Date Report Released by CENRO', form.data.original_date_report_released_cenro, value => set('original_date_report_released_cenro', value), form.errors.original_date_report_released_cenro)}
                        {dateField('historical-penro-receipt', 'Original Date Received by PENRO', form.data.original_date_received_penro, value => set('original_date_received_penro', value), form.errors.original_date_received_penro)}
                        {dateField('historical-regional-endorsement', 'Original Date Endorsed to Regional Office', form.data.original_date_endorsed_regional, value => set('original_date_endorsed_regional', value), form.errors.original_date_endorsed_regional)}
                    </>}

                    {workflow.kind !== 'engp' && <>
                        <SectionHeading>Original routing dates (optional)</SectionHeading>
                        <p className="sm:col-span-2 -mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">These dates are retained as manual evidence. They are not logged as current workflow events.</p>
                        {dateField('historical-cenro-release', 'Original Date Report Released by CENRO', form.data.original_date_report_released_cenro, value => set('original_date_report_released_cenro', value), form.errors.original_date_report_released_cenro)}
                        {dateField('historical-penro-receipt', 'Original Date Received by PENRO', form.data.original_date_received_penro, value => set('original_date_received_penro', value), form.errors.original_date_received_penro)}
                        {dateField('historical-regional-endorsement', 'Original Date Endorsed to Regional Office', form.data.original_date_endorsed_regional, value => set('original_date_endorsed_regional', value), form.errors.original_date_endorsed_regional)}
                    </>}

                    <div className="sm:col-span-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <AttachmentDropzone
                            id="historical-upload"
                            label={workflow.kind === 'aws' ? 'Original Report File' : 'Proof of Original Report / MOV'}
                            files={workflow.kind === 'management-plans' ? form.data.attachments : form.data[fileField]}
                            multiple={workflow.kind === 'management-plans'}
                            onFilesChange={files => set(fileField, files)}
                            onChange={file => set(fileField, file)}
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.zip"
                            allowedExtensions={workflow.kind === 'management-plans' ? ['zip'] : []}
                            maxSizeBytes={workflow.kind === 'management-plans' ? 20 * 1024 * 1024 : 100 * 1024 * 1024}
                            maxFiles={workflow.kind === 'management-plans' ? 10 : 1}
                            required
                            helperText="Upload the original supporting document. Files use protected application storage."
                            error={form.errors[fileField]}
                        />
                    </div>
                    <div className="sm:col-span-2"><FloatingTextarea id="historical-remarks" label="Remarks" rows="3" value={form.data.remarks} onChange={event => set('remarks', event.target.value)} error={form.errors.remarks} placeholder="Optional source note or verification reference" /></div>
                </>}
            </div>
        </CrudFormModal>

        <CrudFormModal
            open={detailsOpen}
            title={selectedRecord?.label || 'Historical report details'}
            subtitle={selectedRecord ? `${sourceLabels[selectedRecord.source] || selectedRecord.source} · ${selectedRecord.period || 'Period not recorded'}` : ''}
            icon={<Icon icon="lucide:archive" width="18" height="18" aria-hidden="true" />}
            onClose={closeDetails}
            onExited={finishDetailsClose}
            returnFocusTarget={detailTriggerRef.current}
            onSubmit={event => event.preventDefault()}
            canSave={false}
            backLabel="Close details"
            maxWidth="max-w-6xl"
            closeOnOverlayClick
            suspendKeyboardHandling={previewOpen}
            showHeaderClose={false}
            motion="soft"
        >
            {detailState === 'loading' && <HistoricalReportDetailSkeleton />}
            {detailState === 'error' && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"><p>{detailError}</p><div className="mt-3 flex gap-2"><Button type="button" size="compact" onClick={retryDetails} className="rounded-lg px-3 py-2 text-xs">Retry details</Button><Button type="button" size="compact" variant="cancel" onClick={closeDetails} className="rounded-lg px-3 py-2 text-xs">Close</Button></div></div>}
            {detailState === 'ready' && detailRecord && <HistoricalReportDetail record={detailRecord} embedded onPreviewOpenChange={setPreviewOpen} />}
        </CrudFormModal>
    </SettingsShell>;
}

function SectionHeading({ children }) {
    return <h3 className="sm:col-span-2 -mb-2 text-[10px] font-extrabold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">{children}</h3>;
}

function InfoCard({ icon, title, text }) {
    return <Card padding="p-3" className="flex gap-3">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"><Icon icon={icon} width="16" height="16" aria-hidden="true" /></span>
        <div><h3 className="text-xs font-extrabold text-gray-900 dark:text-white">{title}</h3><p className="mt-1 text-[11px] leading-4 text-gray-500 dark:text-gray-400">{text}</p></div>
    </Card>;
}

function PreviewPanel({ preview, workflow, selectedArea }) {
    return <div className="space-y-3">
        <Card padding="p-4" variant="subtle">
            <div className="flex items-center gap-2"><Icon icon="lucide:calendar-clock" width="17" height="17" className="text-green-800 dark:text-green-300" aria-hidden="true" /><h3 className="text-xs font-extrabold text-gray-950 dark:text-white">Deadline preview</h3></div>
            <p className="mt-2 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">{preview?.deadline ? formatDate(preview.deadline) : 'Not calculated'}</p>
            <p className="mt-2 text-xs leading-5 text-gray-700 dark:text-gray-300">{preview?.deadline_basis || 'Choose a workflow and enter the dates or period used by that source module.'}</p>
            {preview?.period && <p className="mt-2 text-xs font-bold text-gray-800 dark:text-gray-200">Period: {preview.period}</p>}
        </Card>
        <Card padding="p-4" variant="subtle">
            <h3 className="text-xs font-extrabold text-gray-900 dark:text-white">Before saving</h3>
            <ul className="mt-2 space-y-2 text-xs leading-5 text-gray-600 dark:text-gray-300">
                <li className="flex gap-2"><Icon icon="lucide:check" width="14" height="14" className="mt-0.5 shrink-0 text-green-700" aria-hidden="true" />Confirm the protected area, reporting period, and report category from the source.</li>
                <li className="flex gap-2"><Icon icon="lucide:check" width="14" height="14" className="mt-0.5 shrink-0 text-green-700" aria-hidden="true" />Use the date shown in the original office receipt or endorsement.</li>
                <li className="flex gap-2"><Icon icon="lucide:check" width="14" height="14" className="mt-0.5 shrink-0 text-green-700" aria-hidden="true" />Existing entries are never overwritten by a duplicate submission.</li>
            </ul>
        </Card>
        {workflow?.kind === 'engp' && <Card padding="p-4"><p className="text-xs font-bold text-gray-900 dark:text-white">ENGP CENRO attribution</p><p className="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">This entry remains with its selected CENRO. PENRO receipt and release dates are original milestones only.</p></Card>}
        {selectedArea && <Card padding="p-3"><p className="text-xs font-bold text-gray-900 dark:text-white">Selected protected area</p><p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{selectedArea.name}</p><p className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Responsible PAMO: {selectedArea.pamo || 'Not specified'}</p></Card>}
    </div>;
}

function formatDate(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value + (String(value).length === 10 ? 'T00:00:00' : ''));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
