import { router, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useEffect, useMemo, useState } from 'react';
import Button from '@/Components/Button';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudTable from '@/Components/Crud/CrudTable';
import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';
import TechnicalClassificationEditor from './TechnicalClassificationEditor';
import ObligationDetailsAccordion from './ObligationDetailsAccordion';

export default function OfficeTatAssessmentSettings({ data, recordedAssessment, selectedUnit, canConfigure, periodLabel, periodKey, asOfDate, search = '', saved = null }) {
    const [scopeRow, setScopeRow] = useState(null);
    const [scopeSelection, setScopeSelection] = useState('');
    const [setupTab, setSetupTab] = useState('applicability');
    const [pairChoice, setPairChoice] = useState('');
    const [appEvidence, setAppEvidence] = useState('');
    const [scheduleEvidence, setScheduleEvidence] = useState('');
    const [evidenceError, setEvidenceError] = useState('');
    const [uploadingEvidence, setUploadingEvidence] = useState(false);
    const applicabilityForm = useForm({ protected_area_id: '', workflow_key: '', activity_name: '', document_type: '', status: 'unknown', effective_from: '', effective_to: '', approving_authority: '', approval_reference: '', rationale: '', evidence_reference: '', expected_version: 0 });
    const scheduleForm = useForm({ protected_area_id: '', workflow_key: '', activity_name: '', document_type: '', reporting_year: Number((periodKey || '').slice(0, 4)) || new Date().getFullYear(), period_key: '', schedule_kind: 'recurring', occurrences: [{ occurrence_key: '', start_date: '', deadline: '' }], inventory_complete: true, approving_authority: '', approval_reference: '', rationale: '', evidence_reference: '', expected_version: 0 });
    if (!data) return <section className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"><h2 className="font-extrabold">Compliance-first unit standings</h2><p className="mt-1">The full Conservation and Development roster is available only to users with global Office TAT access.</p></section>;

    const visibleRows = (unit) => (unit?.rows || []).filter(row => `${row.name} ${row.office_binding || ''}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()));
    const configurationReady = data.configuration_schema_ready !== false;
    const pairs = data.conservation?.applicability_catalogue || [];
    const pairOptions = pairs.map((pair, index) => ({ id: String(index), label: `${pair.workflow_label} · ${pair.activity_name} · ${pair.document_type}` }));
    const selectedPair = pairs[Number(pairChoice)] || null;
    const setupState = scopeRow ? (data.conservation?.setup_snapshots?.[scopeRow.participant_key] || { applicability: [], schedules: [] }) : { applicability: [], schedules: [] };
    const pairVersion = (rows, pair) => rows.filter(item => item.workflow_key === pair?.workflow_key && item.activity_name === pair?.activity_name && item.document_type === pair?.document_type).reduce((max, item) => Math.max(max, Number(item.version || 0)), 0);
    const startScope = row => {
        const paId = Number(row.participant_key.slice(3));
        const firstPair = pairs[0];
        const firstIndex = pairs.indexOf(firstPair);
        setPairChoice(firstPair ? String(firstIndex) : '');
        setSetupTab('applicability');
        setAppEvidence('');
        setScheduleEvidence('');
        setEvidenceError('');
        applicabilityForm.setData({ protected_area_id: paId, workflow_key: firstPair?.workflow_key || '', activity_name: firstPair?.activity_name || '', document_type: firstPair?.document_type || '', status: 'unknown', effective_from: '', effective_to: '', approving_authority: '', approval_reference: '', rationale: '', evidence_reference: '', expected_version: 0 });
        applicabilityForm.clearErrors();
        scheduleForm.setData({ protected_area_id: paId, workflow_key: firstPair?.workflow_key || '', activity_name: firstPair?.activity_name || '', document_type: firstPair?.document_type || '', reporting_year: Number((periodKey || '').slice(0, 4)) || new Date().getFullYear(), period_key: firstPair?.period_options?.[0]?.id || '', schedule_kind: firstPair?.schedule_kind || 'recurring', occurrences: [{ occurrence_key: '', start_date: '', deadline: '' }], inventory_complete: true, approving_authority: '', approval_reference: '', rationale: '', evidence_reference: '', expected_version: 0 });
        scheduleForm.clearErrors();
        setScopeRow(row);
    };
    const choosePair = value => {
        const pair = pairs[Number(value)];
        setPairChoice(value);
        if (!pair) return;
        applicabilityForm.setData(current => ({ ...current, workflow_key: pair.workflow_key, activity_name: pair.activity_name, document_type: pair.document_type, expected_version: pairVersion(setupState.applicability, pair), evidence_reference: '' }));
        scheduleForm.setData(current => ({ ...current, workflow_key: pair.workflow_key, activity_name: pair.activity_name, document_type: pair.document_type, schedule_kind: pair.schedule_kind, period_key: pair.period_options?.[0]?.id || '', occurrences: [{ occurrence_key: '', start_date: '', deadline: '' }], expected_version: 0, evidence_reference: '' }));
        setAppEvidence('');
        setScheduleEvidence('');
    };
    const uploadEvidence = (kind, identity, file) => {
        if (!file) return;
        setUploadingEvidence(true);
        setEvidenceError('');
        router.post('/admin/office-tat/approval-evidence', { kind, identity, evidence: file }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: page => {
                const reference = page?.props?.assessmentSaved?.evidence_reference || '';
                if (kind === 'applicability') { setAppEvidence(reference); applicabilityForm.setData('evidence_reference', reference); }
                if (kind === 'schedule') { setScheduleEvidence(reference); scheduleForm.setData('evidence_reference', reference); }
                setEvidenceError(reference ? '' : 'The server did not return a registered evidence reference.');
            },
            onError: errors => setEvidenceError(Object.values(errors || {})[0] || 'Approval evidence could not be registered.'),
            onFinish: () => setUploadingEvidence(false),
        });
    };
    const saveSetup = event => {
        event.preventDefault();
        if (setupTab === 'applicability') {
            applicabilityForm.transform(current => ({ ...current, expected_version: pairVersion(setupState.applicability, selectedPair) }));
            applicabilityForm.post('/admin/office-tat/applicability', { preserveScroll: true, onSuccess: () => { setScopeRow(null); } });
            return;
        }
        const scheduleRows = setupState.schedules.filter(item => item.workflow_key === scheduleForm.data.workflow_key && item.activity_name === scheduleForm.data.activity_name && item.document_type === scheduleForm.data.document_type && Number(item.reporting_year) === Number(scheduleForm.data.reporting_year) && item.period_key === scheduleForm.data.period_key);
        scheduleForm.transform(current => ({ ...current, expected_version: Math.max(0, ...scheduleRows.map(item => Number(item.version || 0))) }));
        scheduleForm.post('/admin/office-tat/obligation-schedule', { preserveScroll: true, onSuccess: () => { setScopeRow(null); } });
    };

    return <section className="space-y-4" aria-labelledby="assessment-settings-heading">
        <header>
            <h2 id="assessment-settings-heading" className="text-sm font-extrabold text-gray-950 dark:text-white">Advanced assessment settings</h2>
            <p className="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">Use these controls only when an owner approved an applicability, schedule, classification, historical reconciliation or tie decision. Source records populate the normal dashboard automatically.</p>
        </header>
        {!configurationReady && <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">The versioned setup schema is unavailable; existing facts remain read-only.</p>}
        {saved && saved.kind !== 'evidence' && <p role="status" className="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-950 dark:bg-emerald-950/30 dark:text-emerald-100">Version {saved.version} saved · {saved.label}.</p>}
        <div className="grid gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <ScopedOptionSelect id="office-tat-settings-pa" label="Protected Area obligation editor" value={scopeSelection} options={(data.conservation?.rows || []).map(row => ({ id: row.participant_key, label: row.name }))} onChange={setScopeSelection} placeholder="Choose a Protected Area" disabled={!configurationReady} />
            <Button type="button" size="compact" disabled={!configurationReady || !scopeSelection} onClick={() => {
                const row = (data.conservation?.rows || []).find(item => item.participant_key === scopeSelection);
                if (row) startScope(row);
            }} className="rounded-lg px-4 py-2.5 text-xs">Edit one selected PA workflow</Button>
        </div>
        <OfficeTatSetupEvidenceLedger ready={configurationReady} participants={data.conservation?.rows || []} snapshots={data.conservation?.setup_snapshots || {}} classifications={data.classification_catalogue || []} registers={data.nonreceipt_registers || []} />
        {canConfigure && configurationReady && <HistoricalNonReceiptPanel participants={[...(data.conservation?.rows || []), ...(data.development?.rows || [])]} registers={data.nonreceipt_registers || []} periodKey={periodKey} asOfDate={asOfDate} />}
        <TechnicalClassificationEditor items={data.classification_catalogue || []} canConfigure={canConfigure && configurationReady} />
        {canConfigure && configurationReady && <TieSettingsPanel unit={selectedUnit} official={data?.[selectedUnit]} recorded={recordedAssessment} periodKey={periodKey} asOfDate={asOfDate} />}
        <CrudFormModal open={Boolean(scopeRow)} title="Conservation obligation evidence" subtitle={scopeRow ? `${scopeRow.name} · append-only owner evidence` : ''} icon={<Icon icon="lucide:list-checks" width="18" height="18" aria-hidden="true" />} onClose={() => setScopeRow(null)} onSubmit={saveSetup} processing={setupTab === 'applicability' ? applicabilityForm.processing || uploadingEvidence : scheduleForm.processing || uploadingEvidence} errors={setupTab === 'applicability' ? applicabilityForm.errors : scheduleForm.errors} saveLabel={setupTab === 'applicability' ? 'Append applicability version' : 'Append schedule inventory'} canSave={Boolean(scopeRow && (setupTab === 'applicability' ? (applicabilityForm.data.status === 'unknown' || Boolean(appEvidence)) : Boolean(scheduleEvidence)))} maxWidth="max-w-4xl">
            {scopeRow && <div className="space-y-4">
                <p className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Record exact owner-approved PA facts with their effective dates and stored source document. Applicability and occurrence schedules are separate. A catalogue cadence does not create a duty, and the prior completeness checkbox no longer affects coverage.</p>
                <div className="flex flex-wrap gap-2" role="tablist" aria-label="Obligation setup facts">
                    {['applicability', 'schedule'].map(tab => <button key={tab} type="button" role="tab" aria-selected={setupTab === tab} onClick={() => setSetupTab(tab)} className={`rounded-lg px-3 py-2 text-xs font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 ${setupTab === tab ? 'bg-emerald-800 text-white' : 'border border-gray-300 bg-white text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200'}`}>{tab === 'applicability' ? 'Applicability' : 'Schedules & occurrences'}</button>)}
                </div>
                <ScopedOptionSelect id="office-tat-pair" label="Workflow · activity · document" value={pairChoice} options={pairOptions} onChange={choosePair} required placeholder="Search active catalogue pair" disabled={!configurationReady} />
                {!selectedPair && <p className="text-xs text-amber-800 dark:text-amber-200">No active PA activity/document pairs are available.</p>}
                {setupTab === 'applicability' && selectedPair && <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Applicability state"><select value={applicabilityForm.data.status} onChange={e => applicabilityForm.setData('status', e.target.value)} className={fieldClass}><option value="unknown">Unknown</option><option value="applicable">Applicable</option><option value="not_applicable">Not applicable</option></select></Field>
                    <Field label="Effective from"><input type="date" required value={applicabilityForm.data.effective_from} onChange={e => applicabilityForm.setData('effective_from', e.target.value)} className={fieldClass} /></Field>
                    <Field label="Effective to (optional)"><input type="date" value={applicabilityForm.data.effective_to} onChange={e => applicabilityForm.setData('effective_to', e.target.value)} className={fieldClass} /></Field>
                    <Field label="Approving authority"><input maxLength={180} value={applicabilityForm.data.approving_authority} onChange={e => applicabilityForm.setData('approving_authority', e.target.value)} className={fieldClass} /></Field>
                    <Field label="Written approval reference"><input maxLength={180} value={applicabilityForm.data.approval_reference} onChange={e => applicabilityForm.setData('approval_reference', e.target.value)} className={fieldClass} /></Field>
                    <Field label="Why this status applies"><textarea required minLength={10} rows={2} value={applicabilityForm.data.rationale} onChange={e => applicabilityForm.setData('rationale', e.target.value)} className={fieldClass} /></Field>
                    {applicabilityForm.data.status !== 'unknown' && <Field label="Approval source file"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={e => uploadEvidence('applicability', { protected_area_id: applicabilityForm.data.protected_area_id, workflow_key: applicabilityForm.data.workflow_key, activity_name: applicabilityForm.data.activity_name, document_type: applicabilityForm.data.document_type }, e.target.files?.[0])} className={fieldClass} /><span className="mt-1 block text-[11px] text-gray-500">{appEvidence ? `Stored evidence ${appEvidence}` : 'Upload the supporting approved document before saving.'}</span></Field>}
                    <div className="sm:col-span-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">Current saved evidence: {setupState.applicability.filter(item => item.workflow_key === selectedPair.workflow_key && item.activity_name === selectedPair.activity_name && item.document_type === selectedPair.document_type).map(item => `${item.status} from ${item.effective_from}${item.effective_to ? ` to ${item.effective_to}` : ''} (v${item.version})`).join(' · ') || 'No version yet'}.</div>
                </div>}
                {setupTab === 'schedule' && selectedPair && <div className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="Reporting year"><input type="number" min="2000" max="2100" value={scheduleForm.data.reporting_year} onChange={e => scheduleForm.setData('reporting_year', Number(e.target.value))} className={fieldClass} /></Field>
                        <Field label="Source period"><select value={scheduleForm.data.period_key} onChange={e => scheduleForm.setData('period_key', e.target.value)} className={fieldClass}>{selectedPair.period_options.map(option => <option key={option.id} value={option.id}>{option.label}</option>)}</select></Field>
                        <div className="self-end text-xs text-gray-600 dark:text-gray-300">{selectedPair.schedule_kind === 'event' ? 'Event inventory: remove all rows to record an approved zero-event period.' : 'Recurring inventory: one or more exact duties are required.'}</div>
                    </div>
                    {scheduleForm.data.occurrences.map((item, index) => <div key={index} className="grid gap-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700 sm:grid-cols-4">
                        <Field label="Stable occurrence ID"><input required value={item.occurrence_key} onChange={e => scheduleForm.setData('occurrences', scheduleForm.data.occurrences.map((v, i) => i === index ? { ...v, occurrence_key: e.target.value } : v))} className={fieldClass} placeholder="e.g. PAMB-2026-03-18-A" /></Field>
                        <Field label="Original start"><input type="date" required value={item.start_date} onChange={e => scheduleForm.setData('occurrences', scheduleForm.data.occurrences.map((v, i) => i === index ? { ...v, start_date: e.target.value } : v))} className={fieldClass} /></Field>
                        <Field label="Approved deadline"><input type="date" required value={item.deadline} onChange={e => scheduleForm.setData('occurrences', scheduleForm.data.occurrences.map((v, i) => i === index ? { ...v, deadline: e.target.value } : v))} className={fieldClass} /></Field>
                        <Button type="button" variant="danger" size="compact" disabled={scheduleForm.data.occurrences.length === 1 && selectedPair.schedule_kind === 'recurring'} onClick={() => scheduleForm.setData('occurrences', scheduleForm.data.occurrences.filter((_, i) => i !== index))} className="self-end rounded-lg px-3 py-2 text-xs">Remove</Button>
                    </div>)}
                    <Button type="button" variant="back" size="compact" onClick={() => scheduleForm.setData('occurrences', [...scheduleForm.data.occurrences, { occurrence_key: '', start_date: '', deadline: '' }])} className="rounded-lg px-3 py-2 text-xs">Add occurrence</Button>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Approving authority"><input required maxLength={180} value={scheduleForm.data.approving_authority} onChange={e => scheduleForm.setData('approving_authority', e.target.value)} className={fieldClass} /></Field>
                        <Field label="Written approval reference"><input required maxLength={180} value={scheduleForm.data.approval_reference} onChange={e => scheduleForm.setData('approval_reference', e.target.value)} className={fieldClass} /></Field>
                        <Field label="Why this schedule is complete"><textarea required minLength={10} rows={2} value={scheduleForm.data.rationale} onChange={e => scheduleForm.setData('rationale', e.target.value)} className={fieldClass} /></Field>
                        <Field label="Approved schedule source file"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={e => uploadEvidence('schedule', { protected_area_id: scheduleForm.data.protected_area_id, workflow_key: scheduleForm.data.workflow_key, activity_name: scheduleForm.data.activity_name, document_type: scheduleForm.data.document_type, reporting_year: scheduleForm.data.reporting_year, period_key: scheduleForm.data.period_key }, e.target.files?.[0])} className={fieldClass} /><span className="mt-1 block text-[11px] text-gray-500">{scheduleEvidence ? `Stored evidence ${scheduleEvidence}` : 'Upload the approved occurrence or schedule source.'}</span></Field>
                    </div>
                    <p className="text-xs text-gray-600 dark:text-gray-300">Saved period inventory: {setupState.schedules.filter(item => item.workflow_key === scheduleForm.data.workflow_key && item.activity_name === scheduleForm.data.activity_name && item.document_type === scheduleForm.data.document_type && Number(item.reporting_year) === Number(scheduleForm.data.reporting_year) && item.period_key === scheduleForm.data.period_key).map(item => `v${item.version} · ${item.occurrences.length} occurrence(s)`).join(' · ') || 'No version yet'}.</p>
                </div>}
                {evidenceError && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">{evidenceError}</p>}
            </div>}
        </CrudFormModal>
    </section>;
}

const fieldClass = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-gray-700 dark:bg-gray-950 dark:text-white';
function Field({ label, children }) { return <label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">{label}{children}</label>; }

function UnitPanel({ unit, rows, canConfigure, periodKey, asOfDate, periodLabel, onConfigureScope }) {
    const [detailRow, setDetailRow] = useState(null);
    const columns = [
        { key: 'rank', label: 'Rank', headerClassName: 'min-w-40', render: row => row.rank ? <span className="inline-flex min-h-8 min-w-8 items-center justify-center rounded-full bg-gradient-to-r from-emerald-600 to-green-700 px-2 text-xs font-extrabold text-white">{row.rank}</span> : <div><span className={`text-xs font-bold ${row.rank_status?.startsWith('Awaiting') ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400'}`}>{row.rank_status || 'Not ranked'}</span>{row.reserved_rank_start && <span className="mt-0.5 block text-[10px] font-semibold text-gray-500 dark:text-gray-400">Reserving places {row.reserved_rank_start}–{row.reserved_rank_end}</span>}</div> },
        { key: 'participant', label: unit?.unit === 'conservation' ? 'Protected Area / PAMO' : 'CENRO', headerClassName: 'min-w-52', render: row => <div><p className="font-bold text-gray-900 dark:text-white">{row.name}</p>{unit?.unit === 'conservation' && <p className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">PAMO binding: {row.office_binding || 'Not assigned'} · PA status: {row.legal_status || 'Unknown'}</p>}</div> },
        { key: 'due', label: 'Due', render: row => metricCount(row.due_count) },
        { key: 'submitted', label: 'Submitted', render: row => metricCount(row.submitted_count) },
        { key: 'volume', label: 'Verified volume', render: row => metricCount(row.volume_count) },
        { key: 'on_time', label: 'On time', render: row => metricCount(row.on_time_count) },
        { key: 'early', label: 'Early', render: row => metricCount(row.early_count) },
        { key: 'early_percent', label: 'Early %', render: row => metricPercent(row.early_percent) },
        { key: 'technical', label: 'Highly technical on time', headerClassName: 'min-w-36', render: row => metricCount(row.technical_on_time_count) },
        { key: 'tc', label: 'TC', render: row => metricPercent(row.tc_percent) },
        { key: 'se', label: 'SE', render: row => metricPercent(row.se_percent) },
        { key: 'otps', label: 'OTPS', render: row => row.otps == null ? row.score_status === 'No due reports' ? 'Not applicable' : 'Coverage incomplete' : <span className="font-extrabold text-emerald-900 dark:text-emerald-200">{Number(row.otps).toFixed(2)}</span> },
        { key: 'evidence', label: 'Details', render: row => <Button type="button" size="compact" variant="primary" onClick={() => setDetailRow(row)} aria-label={`View obligation evidence for ${row.name}`} className="rounded-lg px-3 py-1.5 text-[11px]">View</Button> },
        ...(unit?.unit === 'conservation' && canConfigure ? [{ key: 'scope', label: 'Applicability', render: row => <button type="button" onClick={() => onConfigureScope(row)} className="rounded-lg bg-gradient-to-r from-emerald-700 to-teal-700 px-3 py-2 text-[11px] font-bold text-white shadow-sm transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">{row.scope_version_id ? `Edit v${row.scope_version_id}` : 'Review workflows'}</button> }] : []),
    ];
    const summary = [
        ['Roster / coverage', `${unit?.roster_count ?? 0} / ${unit?.expected_roster_count ?? 0}`, unit?.coverage_complete ? 'Complete' : 'Incomplete'],
        ['Assessed reports (N)', unit?.coverage_complete && unit?.unit_score_status === 'No due reports' ? '0' : metricCount(unit?.due_count), `${metricCount(unit?.submitted_count)} submitted`],
        ['Verified volume', metricCount(unit?.volume_count), 'Unique valid submissions by cutoff'],
        ['On time / early', `${metricCount(unit?.on_time_count)} / ${metricCount(unit?.early_count)}`, 'Counts across verified obligations'],
        ['TC · SE · OTPS', `${metricPercent(unit?.tc_percent)} · ${metricPercent(unit?.se_percent)} · ${unit?.otps == null ? unit?.unit_score_status === 'No due reports' ? 'Not applicable' : 'Coverage incomplete' : Number(unit.otps).toFixed(2)}`, 'Pooled by obligation count'],
    ];

    return <article className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-teal-50 px-4 py-4 dark:border-gray-800 dark:from-emerald-950/30 dark:to-teal-950/20 sm:px-5">
            <div><div className="flex items-center gap-2"><span className="rounded-full bg-gradient-to-r from-emerald-700 to-teal-700 px-3 py-1 text-xs font-extrabold text-white">{unit?.label || 'Unit'}</span><span className={`rounded-full border px-2.5 py-1 text-[10px] font-bold ${unit?.coverage_complete ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200' : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200'}`}>{unit?.unit_score_status || 'Not assessed'}</span></div><p className="mt-2 text-xs leading-5 text-gray-600 dark:text-gray-300">{unit?.unit === 'development' ? 'Active ENGP workflow schedules · period start to catalogued deadline · official receipt: date_received_penro · elapsed calendar days.' : 'Protected Area identity is stable across PAMO bindings. Source submissions use date_accomplished and date_received_penro; unresolved or unencoded starts block the official score.'}</p></div>
            <p className="text-xs font-semibold text-gray-500 dark:text-gray-400">{periodLabel}</p>
        </header>
        <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-5 sm:p-4" aria-label={`${unit?.label || 'Unit'} pooled summary`}>
            {summary.map(([label, value, detail]) => <div key={label} className="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-950"><p className="text-[10px] font-extrabold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p><p className="mt-1 text-lg font-extrabold text-gray-950 dark:text-white">{value}</p><p className="mt-1 text-[10px] leading-4 text-gray-500 dark:text-gray-400">{detail}</p></div>)}
        </div>
        {!unit?.coverage_complete && <div role="status" className="mx-3 mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100 sm:mx-4">
            <p className="font-extrabold">Official score withheld · coverage incomplete</p>
            <ul className="mt-1 list-inside list-disc">{(unit?.coverage_reasons || ['The assessed roster or source evidence is incomplete.']).slice(0, 8).map(reason => <li key={reason}>{reason}</li>)}</ul>
        </div>}
        {(unit?.historical_evidence_count || 0) > 0 && <p className="mx-3 mb-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100 sm:mx-4">{unit.historical_evidence_count} historical submission record(s) are kept separate from live compliance scoring.</p>}
        <div className="border-t border-gray-100 p-3 dark:border-gray-800 sm:p-4">
            <CrudTable compact title={`${unit?.label || 'Unit'} participant standings`} subtitle={`${unit?.roster_count ?? 0} directory participants · ${unit?.expected_roster_count ?? 0} expected · ${periodLabel}`} helperText="Standings sort by TC, SE, early percentage, unique valid submitted volume, then highly technical reports on time. OTPS is displayed and is not a sort key." rows={rows} rowKey={row => row.participant_key} columns={columns} emptyTitle={(unit?.rows?.length || 0) > 0 ? 'No participants match search' : 'No directory participants'} emptyDescription="The unit roster is resolved from the current organization directory; no placeholder participants are created." />
        </div>
        {canConfigure && (unit?.tie_groups || []).filter(group => group.resolvable).map(group => <TieResolutionForm key={group.fingerprint} unit={unit} group={group} rows={unit?.rows || []} periodKey={periodKey} asOfDate={asOfDate} />)}
        <CrudDetailsModal open={Boolean(detailRow)} title="Assessment evidence details" subtitle={detailRow ? `${unit?.label} · ${detailRow.name} · ${periodLabel}` : ''} icon={<Icon icon="lucide:clipboard-check" width="18" height="18" aria-hidden="true" />} onClose={() => setDetailRow(null)} summary={detailRow && <div className="grid gap-2 sm:grid-cols-3"><Metric label="Due denominator N" value={detailRow.due_count == null ? 'Withheld' : detailRow.due_count} /><Metric label="Submitted by cutoff" value={metricCount(detailRow.submitted_count)} /><Metric label="Participant score state" value={detailRow.score_status} /></div>} maxWidth="max-w-5xl" compact>
            {detailRow && <div className="space-y-4">
                <div className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-3 text-xs leading-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
                    <p><strong>N</strong> includes verified obligations whose deadline is on or before the effective cutoff. <strong>T</strong> is the source-specific allowed duration from its approved start to deadline. <strong>D</strong> is elapsed duration from that same start to accepted {detailRow.obligation_details?.[0]?.receipt_endpoint || 'date_received_penro'}.</p>
                    <p className="mt-1">Ranking priority: TC → SE → Early % → unique verified volume → highly technical reports on time → recorded manual decision. OTPS is displayed and never sorts the standings. Search only hides participant rows; it does not recompute or renumber ranks.</p>
                    <p className="mt-1">Source corrections update one report obligation; routing events and archive copies do not add volume. If duplicate live source rows match, the earliest accepted receipt is used once and the match count is shown.</p>
                </div>
                {detailRow.score_issues?.length > 0 && <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"><p className="font-extrabold">Evidence or coverage issues</p><ul className="mt-1 list-inside list-disc">{detailRow.score_issues.map(issue => <li key={issue}>{issue}</li>)}</ul></div>}
                {!unit?.coverage_complete && (unit?.coverage_reasons || []).length > 0 && <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"><p className="font-extrabold">Unit coverage blockers</p><ul className="mt-1 list-inside list-disc">{unit.coverage_reasons.map(reason => <li key={reason}>{reason}</li>)}</ul></div>}
                <ObligationDetailsAccordion key={detailRow.participant_key} participantKey={detailRow.participant_key} items={detailRow.obligation_details || []} />
            </div>}
        </CrudDetailsModal>
    </article>;
}

function OfficeTatSetupEvidenceLedger({ ready, participants, snapshots, classifications, registers }) {
    const configuredClasses = classifications.filter(item => item.version);
    const hasEvidence = participants.some(row => {
        const snapshot = snapshots[row.participant_key] || {};
        return (snapshot.applicability || []).length || (snapshot.schedules || []).length;
    }) || configuredClasses.length || registers.length;
    return <details className="rounded-2xl border border-sky-200 bg-white shadow-sm dark:border-sky-900 dark:bg-gray-900">
        <summary className="cursor-pointer px-4 py-3 text-sm font-extrabold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:text-white">Saved approval and obligation evidence · {hasEvidence ? 'open ledger' : 'no owner evidence entered'}</summary>
        <div className="max-h-[34rem] space-y-3 overflow-auto border-t border-gray-100 p-4 dark:border-gray-800">
            {participants.map(participant => {
                const snapshot = snapshots[participant.participant_key] || { applicability: [], schedules: [] };
                const facts = [...(snapshot.applicability || []).map(item => ({ ...item, kind: 'Applicability' })), ...(snapshot.schedules || []).map(item => ({ ...item, kind: item.schedule_kind === 'event' ? 'Event inventory' : 'Recurring schedule' }))];
                return facts.length > 0 && <section key={participant.participant_key} className="rounded-xl border border-gray-200 p-3 dark:border-gray-700"><h3 className="text-xs font-extrabold text-gray-900 dark:text-white">{participant.name} · {participant.participant_key}</h3><div className="mt-2 space-y-2">{facts.map((item, index) => <article key={`${item.kind}-${item.workflow_key}-${item.period_key || item.effective_from}-${item.version}-${index}`} className="rounded-lg bg-gray-50 p-2 text-[11px] leading-5 text-gray-700 dark:bg-gray-950 dark:text-gray-200"><p className="font-bold">{item.kind} · {item.workflow_key} · {item.activity_name} · {item.document_type}{item.period_key ? ` · ${item.reporting_year} ${item.period_key}` : ` · ${item.status} ${item.effective_from}${item.effective_to ? ` to ${item.effective_to}` : ' onward'}`}</p>{item.occurrences?.length ? item.occurrences.map(occurrence => <p key={occurrence.occurrence_key} className="pl-2">{occurrence.occurrence_key} · original start {occurrence.start_date} · deadline {occurrence.deadline}</p>) : item.schedule_kind === 'event' ? <p>Approved zero-event inventory.</p> : null}<p>{item.approving_authority} · {item.approval_reference} · version {item.version}</p>{item.evidence_reference && <a className="font-bold text-emerald-800 underline dark:text-emerald-200" href={`/admin/office-tat/approval-evidence/${item.evidence_reference}`}>Open stored approval source</a>}</article>)}</div></section>;
            })}
            {configuredClasses.length > 0 && <section className="rounded-xl border border-gray-200 p-3 dark:border-gray-700"><h3 className="text-xs font-extrabold text-gray-900 dark:text-white">Technical classification approvals</h3><div className="mt-2 space-y-2">{configuredClasses.map(item => <article key={`${item.domain}-${item.workflow_key}`} className="rounded-lg bg-gray-50 p-2 text-[11px] leading-5 text-gray-700 dark:bg-gray-950 dark:text-gray-200"><p className="font-bold">{item.domain} · {item.label} · {item.is_highly_technical ? 'Highly technical' : 'Not highly technical'} · v{item.version}</p><p>{item.approving_authority} · {item.approval_reference} · {item.effective_from}{item.effective_to ? ` to ${item.effective_to}` : ' onward'}</p><p>{item.rationale}</p>{item.evidence_reference && <a className="font-bold text-emerald-800 underline dark:text-emerald-200" href={`/admin/office-tat/approval-evidence/${item.evidence_reference}`}>Open classification source</a>}</article>)}</div></section>}
            {registers.length > 0 && <section className="rounded-xl border border-amber-200 p-3 dark:border-amber-900"><h3 className="text-xs font-extrabold text-gray-900 dark:text-white">Historical non-receipt registers</h3><div className="mt-2 space-y-2">{registers.map(item => <article key={`${item.participant_key}-${item.cutoff_date}`} className="rounded-lg bg-amber-50 p-2 text-[11px] leading-5 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"><p className="font-bold">{item.participant_key} · cutoff {item.cutoff_date} · v{item.version} · {item.approving_authority} / {item.approval_reference}</p><ul className="list-inside list-disc">{(item.obligation_manifest || []).map(obligation => <li key={obligation.key}>{obligation.workflow_key} · {obligation.activity_name} · {obligation.document_type} · {obligation.reporting_year} {obligation.period_key}{obligation.occurrence_key ? ` · ${obligation.occurrence_key}` : ''} · {obligation.start_date} to {obligation.deadline}</li>)}</ul>{item.evidence_reference && <a className="font-bold text-emerald-800 underline dark:text-emerald-200" href={`/admin/office-tat/approval-evidence/${item.evidence_reference}`}>Open signed register source</a>}</article>)}</div></section>}
            {!ready && <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">The additive setup schema is not installed; saved approval evidence is unavailable and configuration remains read-only.</p>}
            {ready && !hasEvidence && <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">No applicability, schedule, classification or non-receipt evidence has been entered. The shared unit coverage state is derived and remains incomplete until required approved facts are supported.</p>}
        </div>
    </details>;
}

function HistoricalNonReceiptPanel({ participants, registers, periodKey, asOfDate }) {
    const [participantKey, setParticipantKey] = useState('');
    const [cutoff, setCutoff] = useState(asOfDate || '');
    const [evidenceReference, setEvidenceReference] = useState('');
    const [uploading, setUploading] = useState(false);
    const [evidenceError, setEvidenceError] = useState('');
    const [selectedKeys, setSelectedKeys] = useState([]);
    const [approval, setApproval] = useState({ approving_authority: '', approval_reference: '', rationale: '' });
    const form = useForm({ participant_key: '', period_key: periodKey, as_of_date: asOfDate, cutoff_date: asOfDate, obligation_keys: [], ...approval, evidence_reference: '', expected_version: 0 });
    const options = participants.map(row => ({ id: row.participant_key, label: `${row.name} (${row.participant_key})` }));
    const selected = participants.find(row => row.participant_key === participantKey);
    const candidates = (selected?.obligation_details || []).filter(item => item.key && item.deadline < '2026-10-11' && (!item.received || item.received > cutoff));
    const currentRegisterVersion = Math.max(0, ...registers.filter(row => row.participant_key === participantKey && row.cutoff_date === cutoff).map(row => Number(row.version || 0)));
    useEffect(() => {
        setCutoff(asOfDate || '');
        form.setData(current => ({ ...current, as_of_date: asOfDate, period_key: periodKey, cutoff_date: asOfDate }));
    }, [asOfDate, periodKey]);
    const chooseParticipant = key => { setParticipantKey(key); setSelectedKeys([]); setEvidenceReference(''); form.setData(current => ({ ...current, participant_key: key, obligation_keys: [], evidence_reference: '' })); };
    const toggle = (key, checked) => setSelectedKeys(current => checked ? [...new Set([...current, key])] : current.filter(value => value !== key));
    const upload = file => {
        if (!file || !participantKey || !cutoff) return;
        setUploading(true); setEvidenceError('');
        router.post('/admin/office-tat/approval-evidence', { kind: 'nonreceipt', identity: { participant_key: participantKey, cutoff_date: cutoff }, evidence: file }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: page => {
                const reference = page?.props?.assessmentSaved?.evidence_reference || '';
                setEvidenceReference(reference); form.setData('evidence_reference', reference);
                setEvidenceError(reference ? '' : 'The server did not return a registered evidence reference.');
            },
            onError: errors => setEvidenceError(Object.values(errors || {})[0] || 'Approval evidence could not be registered.'),
            onFinish: () => setUploading(false),
        });
    };
    const submit = event => {
        event.preventDefault();
        form.transform(current => ({ ...current, participant_key: participantKey, period_key: periodKey, as_of_date: asOfDate, cutoff_date: cutoff, obligation_keys: selectedKeys, ...approval, evidence_reference: evidenceReference, expected_version: currentRegisterVersion }));
        form.post('/admin/office-tat/non-receipt-register', { preserveScroll: true, onSuccess: () => { setSelectedKeys([]); setEvidenceReference(''); } });
    };
    return <section className="rounded-2xl border border-amber-200 bg-white shadow-sm dark:border-amber-900 dark:bg-gray-900" aria-labelledby="nonreceipt-evidence-heading">
        <header className="border-b border-amber-100 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/25"><h2 id="nonreceipt-evidence-heading" className="text-sm font-extrabold text-amber-950 dark:text-amber-100">Historical non-receipt evidence</h2><p className="mt-1 text-xs leading-5 text-amber-900 dark:text-amber-200">A register resolves only checked, exact obligation identities before the policy effective date. The server rejects cross-participant keys and any verified or conflicting receipt evidence.</p></header>
        <div className="grid gap-3 p-4 lg:grid-cols-[minmax(14rem,1fr)_2fr]">
            <div className="space-y-3">
                <ScopedOptionSelect label="Participant" value={participantKey} options={options} onChange={chooseParticipant} placeholder="Search PA or CENRO" required />
                <Field label="Register cutoff"><input type="date" value={cutoff} onChange={event => { setCutoff(event.target.value); setEvidenceReference(''); }} className={fieldClass} /></Field>
                <Field label="Approving authority"><input required maxLength={180} value={approval.approving_authority} onChange={event => setApproval(current => ({ ...current, approving_authority: event.target.value }))} className={fieldClass} /></Field>
                <Field label="Written approval reference"><input required maxLength={180} value={approval.approval_reference} onChange={event => setApproval(current => ({ ...current, approval_reference: event.target.value }))} className={fieldClass} /></Field>
                <Field label="Rationale"><textarea required minLength={10} rows={3} value={approval.rationale} onChange={event => setApproval(current => ({ ...current, rationale: event.target.value }))} className={fieldClass} /></Field>
                <Field label="Register source file"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" disabled={!participantKey || !cutoff || uploading} onChange={event => upload(event.target.files?.[0])} className={fieldClass} /><span className="mt-1 block text-[11px] text-gray-500">{evidenceReference ? `Stored evidence ${evidenceReference}` : 'Upload the signed inventory or authorized register.'}</span></Field>
                {evidenceError && <p role="alert" className="text-xs text-red-700 dark:text-red-300">{evidenceError}</p>}
                <Button type="button" variant="primary" size="compact" disabled={form.processing || uploading || !participantKey || selectedKeys.length === 0 || !evidenceReference || !approval.approving_authority.trim() || !approval.approval_reference.trim() || approval.rationale.trim().length < 10} onClick={submit} className="rounded-lg px-4 py-2 text-xs">Append exact non-receipt register</Button>
            </div>
            <div className="max-h-[30rem] space-y-2 overflow-auto rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                {candidates.length === 0 ? <p className="text-xs text-gray-600 dark:text-gray-300">Choose a participant with pre-effective scheduled obligation evidence to view exact candidates.</p> : candidates.map(item => <label key={item.key} className={`flex cursor-pointer gap-3 rounded-lg border p-3 text-xs ${item.historical_receipt_evidence ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/30' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-950'}`}>
                    <input type="checkbox" disabled={Boolean(item.historical_receipt_evidence) || item.status.includes('Conflicting')} checked={selectedKeys.includes(item.key)} onChange={event => toggle(item.key, event.target.checked)} className="mt-0.5 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600" />
                    <span className="min-w-0"><span className="block font-bold text-gray-900 dark:text-gray-100">{item.label} · deadline {item.deadline}</span><span className="mt-1 block break-all font-mono text-[10px] text-gray-600 dark:text-gray-300">{item.key}</span>{item.historical_receipt_evidence && <span className="mt-1 block font-semibold text-red-800 dark:text-red-200">Original historical receipt {item.historical_receipt_evidence}; declaration blocked.</span>}</span>
                </label>)}
            </div>
        </div>
    </section>;
}

function Metric({ label, value }) {
    return <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"><p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p><p className="mt-1 text-sm font-extrabold text-gray-900 dark:text-white">{value}</p></div>;
}

function DataPoint({ label, value }) {
    return <div><dt className="font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-0.5 font-semibold text-gray-900 dark:text-gray-100">{value}</dd></div>;
}

function TieResolutionForm({ unit, group, rows, periodKey, asOfDate, population = 'official' }) {
    const members = useMemo(() => (group.participant_keys || []).map(key => rows.find(row => row.participant_key === key)).filter(Boolean), [group.participant_keys, rows]);
    const form = useForm({ unit: unit.unit, population, period_key: periodKey, as_of_date: asOfDate, metric_fingerprint: group.fingerprint, participant_keys: group.participant_keys, ordered_participant_keys: Array(members.length).fill(''), reason: '' });
    useEffect(() => form.setData(current => ({ ...current, unit: unit.unit, population, period_key: periodKey, as_of_date: asOfDate, metric_fingerprint: group.fingerprint, participant_keys: group.participant_keys })), [unit.unit, population, periodKey, asOfDate, group.fingerprint]);
    const changePosition = (index, value) => form.setData('ordered_participant_keys', form.data.ordered_participant_keys.map((key, slot) => slot === index ? value : key));
    const valid = form.data.ordered_participant_keys.every(Boolean) && new Set(form.data.ordered_participant_keys).size === members.length && form.data.reason.trim().length >= 10;
    const submit = event => { event.preventDefault(); form.post('/admin/office-tat/tie-decision', { preserveScroll: true }); };
    return <form onSubmit={submit} className="border-t border-amber-200 bg-amber-50/70 p-4 dark:border-amber-900 dark:bg-amber-950/20">
        <h3 className="text-sm font-extrabold text-amber-950 dark:text-amber-100">Awaiting tie resolution · places {group.rank_start}–{group.rank_start + members.length - 1}</h3>
        <p className="mt-1 text-xs leading-5 text-amber-900 dark:text-amber-200">All five performance measures are equal. An authorized reviewer records a complete order with a reason. The server rechecks membership and the metric fingerprint before saving.</p>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{members.map((row, index) => <label key={`${group.fingerprint}-${index}`} className="text-xs font-semibold text-gray-800 dark:text-gray-100">Place {group.rank_start + index}
            <select value={form.data.ordered_participant_keys[index] || ''} onChange={event => changePosition(index, event.target.value)} className="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"><option value="">Choose participant</option>{members.map(option => <option key={option.participant_key} value={option.participant_key}>{option.name}</option>)}</select>
        </label>)}</div>
        <label className="mt-3 block text-xs font-semibold text-gray-800 dark:text-gray-100">Decision reason<textarea value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} minLength={10} maxLength={2000} rows={2} className="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white" /></label>
        {(form.errors.order || form.errors.reason) && <p className="mt-2 text-xs text-red-700 dark:text-red-300">{form.errors.order || form.errors.reason}</p>}
        <Button type="submit" size="compact" disabled={!valid || form.processing} className="mt-3 rounded-lg px-4 py-2 text-xs">Record approved tie order</Button>
    </form>;
}

function TieSettingsPanel({ unit, official, recorded, periodKey, asOfDate }) {
    const [population, setPopulation] = useState('official');
    const [fingerprint, setFingerprint] = useState('');
    const active = population === 'recorded' ? recorded : official;
    const groups = (active?.tie_groups || []).filter(group => group.resolvable);
    const names = new Map((active?.rows || []).map(row => [row.participant_key, row.name]));
    const options = groups.map(group => ({
        id: group.fingerprint,
        label: (group.participant_keys || []).map(key => names.get(key) || 'Participant').join(' · '),
    }));
    const selected = groups.find(group => group.fingerprint === fingerprint) || groups[0] || null;
    useEffect(() => {
        if (selected && selected.fingerprint !== fingerprint) setFingerprint(selected.fingerprint);
        if (!selected && fingerprint) setFingerprint('');
    }, [selected, fingerprint]);

    return <details className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <summary className="cursor-pointer px-4 py-3 text-sm font-extrabold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:text-white">Manual tie decisions</summary>
        <div className="space-y-3 border-t border-gray-100 p-4 dark:border-gray-800">
            <p className="text-xs leading-5 text-gray-600 dark:text-gray-300">Record an order only when TC, SE, early percentage and unique volume match. Recorded report and official assessment decisions keep separate result fingerprints.</p>
            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">Assessment basis<select value={population} onChange={event => { setPopulation(event.target.value); setFingerprint(''); }} className={fieldClass}><option value="official">Official complete-period assessment</option><option value="recorded">Recorded reports</option></select></label>
            {groups.length > 0
                ? <><ScopedOptionSelect id="office-tat-tie-group" label="Tied participants" value={selected?.fingerprint || ''} options={options} onChange={setFingerprint} placeholder="Choose the tied participants" />{selected && <TieResolutionForm unit={active} group={selected} rows={active.rows || []} periodKey={periodKey} asOfDate={asOfDate} population={population} />}</>
                : <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-300">No unresolved manual tie is available for this unit and basis.</p>}
        </div>
    </details>;
}

function TechnicalClassificationPanel({ items, canConfigure }) {
    const domains = [
        ['conservation', 'Conservation workflows'],
        ['engp', 'ENGP workflows'],
    ];
    const [values, setValues] = useState({});
    const [approval, setApproval] = useState({});
    const [uploadingKey, setUploadingKey] = useState('');
    const [errors, setErrors] = useState({});
    useEffect(() => {
        setValues(Object.fromEntries(items.map(item => [`${item.domain}:${item.workflow_key}`, item.is_highly_technical == null ? '' : (item.is_highly_technical ? 'yes' : 'no')])));
        setApproval(Object.fromEntries(items.map(item => [`${item.domain}:${item.workflow_key}`, { approving_authority: item.approving_authority || '', approval_reference: item.approval_reference || '', rationale: item.rationale || '', effective_from: item.effective_from || '', effective_to: item.effective_to || '', evidence_reference: item.evidence_reference || '' }])));
    }, [items]);
    const setField = (key, field, value) => setApproval(current => ({ ...current, [key]: { ...(current[key] || {}), [field]: value } }));
    const upload = (item, key, file) => {
        if (!file) return;
        setUploadingKey(key); setErrors(current => ({ ...current, [key]: '' }));
        router.post('/admin/office-tat/approval-evidence', { kind: 'classification', identity: { domain: item.domain, workflow_key: item.workflow_key }, evidence: file }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: page => setField(key, 'evidence_reference', page?.props?.assessmentSaved?.evidence_reference || ''),
            onError: messages => setErrors(current => ({ ...current, [key]: Object.values(messages || {})[0] || 'Approval file could not be registered.' })),
            onFinish: () => setUploadingKey(''),
        });
    };
    const submit = (item, key, value) => {
        const fields = approval[key] || {};
        router.post('/admin/office-tat/technical-classification', {
            domain: item.domain, workflow_key: item.workflow_key, is_highly_technical: value === 'yes',
            ...fields, expected_version: Number(item.stored_version || 0),
        }, { preserveScroll: true });
    };
    return <details className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <summary className="cursor-pointer px-4 py-4 text-sm font-extrabold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:text-white">Highly technical report classification · {items.filter(item => item.is_highly_technical == null).length} unclassified workflows</summary>
        <div className="border-t border-gray-100 p-4 dark:border-gray-800"><p className="mb-3 text-xs leading-5 text-gray-600 dark:text-gray-300">Configuration permission records the entry. The source file, approving authority, written reference, rationale and effective bounds remain attached to each immutable technical classification version.</p>
            {domains.map(([domain, title]) => <section key={domain} className="mb-4 last:mb-0"><h3 className="mb-2 text-xs font-extrabold uppercase tracking-wide text-gray-700 dark:text-gray-200">{title}</h3><div className="grid gap-2">{items.filter(item => item.domain === domain).map(item => {
                const key = `${domain}:${item.workflow_key}`;
                const value = values[key] ?? (item.is_highly_technical == null ? '' : (item.is_highly_technical ? 'yes' : 'no'));
                const fields = approval[key] || {};
                return <div key={key} className="rounded-xl border border-gray-200 p-3 dark:border-gray-700"><div className="flex flex-wrap items-start justify-between gap-3"><span className="min-w-48 flex-1 text-xs font-semibold text-gray-800 dark:text-gray-100">{item.label}{item.version ? <span className="ml-1 text-[10px] font-normal text-gray-500">v{item.version}</span> : <span className="ml-1 text-[10px] font-bold text-amber-700 dark:text-amber-300">Approval missing</span>}<span className="mt-1 block text-[10px] font-normal leading-4 text-gray-500 dark:text-gray-400">Start: {item.start_basis}. Deadline: {item.deadline_basis}. Endpoint: {item.submission_endpoint}. {item.day_convention}{item.allowed_days ? ` · configured duration ${item.allowed_days} days` : ''}.</span></span>
                    <select disabled={!canConfigure} aria-label={`Technical classification for ${item.label}`} value={value} onChange={event => setValues(current => ({ ...current, [key]: event.target.value }))} className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white"><option value="">Choose classification</option><option value="yes">Highly technical</option><option value="no">Not highly technical</option></select></div>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        <input aria-label={`Approving authority for ${item.label}`} placeholder="Approving authority" maxLength={180} disabled={!canConfigure} value={fields.approving_authority || ''} onChange={event => setField(key, 'approving_authority', event.target.value)} className={fieldClass} />
                        <input aria-label={`Written approval reference for ${item.label}`} placeholder="Written reference" maxLength={180} disabled={!canConfigure} value={fields.approval_reference || ''} onChange={event => setField(key, 'approval_reference', event.target.value)} className={fieldClass} />
                        <input aria-label={`Effective from for ${item.label}`} type="date" disabled={!canConfigure} value={fields.effective_from || ''} onChange={event => setField(key, 'effective_from', event.target.value)} className={fieldClass} />
                        <input aria-label={`Effective to for ${item.label}`} type="date" disabled={!canConfigure} value={fields.effective_to || ''} onChange={event => setField(key, 'effective_to', event.target.value)} className={fieldClass} />
                        <textarea aria-label={`Classification rationale for ${item.label}`} placeholder="Rationale" minLength={10} maxLength={4000} rows={2} disabled={!canConfigure} value={fields.rationale || ''} onChange={event => setField(key, 'rationale', event.target.value)} className={fieldClass} />
                        <label className="block text-[11px] font-semibold text-gray-600 dark:text-gray-300">Approval source file<input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" disabled={!canConfigure || uploadingKey === key} onChange={event => upload(item, key, event.target.files?.[0])} className={fieldClass} /><span className="mt-1 block font-normal">{fields.evidence_reference ? `Stored evidence ${fields.evidence_reference}` : 'Upload source file.'}</span></label>
                    </div>
                    {errors[key] && <p role="alert" className="mt-2 text-xs text-red-700 dark:text-red-300">{errors[key]}</p>}
                    <button type="button" disabled={!canConfigure || !value || !fields.evidence_reference || !fields.approving_authority?.trim() || !fields.approval_reference?.trim() || !fields.rationale?.trim() || fields.rationale.trim().length < 10 || !fields.effective_from} onClick={() => submit(item, key, value)} className="mt-3 rounded-lg bg-gradient-to-r from-emerald-700 to-teal-700 px-3 py-2 text-[11px] font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">Append approved classification version</button>
                </div>;
            })}</div></section>)}
        </div>
    </details>;
}

function metricCount(value) { return value == null ? 'Coverage incomplete' : Number(value).toLocaleString(); }
function metricPercent(value) { return value == null ? 'Coverage incomplete' : `${Number(value).toFixed(2)}%`; }
