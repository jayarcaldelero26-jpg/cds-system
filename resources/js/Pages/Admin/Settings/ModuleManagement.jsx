import ModalPortal from '@/Components/ModalPortal';
import { Head, router, useForm } from '@inertiajs/react';
import { Children, cloneElement, isValidElement, useEffect, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
import SettingsShell from '@/Components/Admin/SettingsShell';
import SettingsPageHeader from '@/Components/Admin/SettingsPageHeader';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import FilterToolbar from '@/Components/Form/FilterToolbar';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';
import ConfirmDialog from '@/Components/ConfirmDialog';

const empty = { name: '', unit: 'conservation', program_area: 'protected_area_management_and_development', module_type: 'regular_target', reporting_frequency: 'monthly', activity_name: '', document_types: '', period_labels: '', offices: [], reminder_offices: [], configure_workflow: true, deadline_mode: 'standard_working_days', default_deadline_days: '15', allow_deadline_override: false, description: '', is_active: true };
const deadlineLabels = { standard_working_days: 'Standard Working Days', calendar_days: 'Calendar Days', custom: 'Custom Deadline', none: 'No Deadline' };

export default function ModuleManagement({ definitions = [], filters = {}, programAreas = [], frequencies = [], developmentOffices = [], needsCanonicalConservationSetup = false, needsCanonicalEngpSetup = false }) {
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [pendingMutation, setPendingMutation] = useState(null);
    const [mutationProcessing, setMutationProcessing] = useState(false);
    const [mutationError, setMutationError] = useState('');
    const mutationInFlight = useRef(false);
    const form = useForm(empty);
    const setFilter = (name, value) => router.get(route('module-definitions.index'), buildModuleFilterParams(filters, name, value), { preserveState: true, preserveScroll: true, replace: true });
    const startCreate = () => { form.setData({ ...empty, offices: [...developmentOffices] }); form.clearErrors(); setEditing(null); setOpen(true); };
    const startEdit = definition => {
        const editable = { ...definition };
        delete editable.plan_duration_years;
        form.setData({ ...empty, ...editable, configure_workflow: definition.configured_generic_flow === true, default_deadline_days: definition.default_deadline_days || '', description: definition.description || '' });
        form.clearErrors(); setEditing(definition); setOpen(true);
    };
    const submit = event => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { setOpen(false); setEditing(null); form.reset(); } };
        editing ? form.put(route('module-definitions.update', editing.id), options) : form.post(route('module-definitions.store'), options);
    };
    const confirmMutation = () => {
        if (!pendingMutation || mutationInFlight.current) return;
        mutationInFlight.current = true;
        setMutationProcessing(true);
        setMutationError('');
        const options = {
            preserveScroll: true,
            onSuccess: () => setPendingMutation(null),
            onError: errors => setMutationError(Object.values(errors || {}).find(value => typeof value === 'string' && value.trim()) || 'The module change was not saved. Review its current state and try again.'),
            onHttpException: () => setMutationError('The server could not complete this module change. Review the current state and try again.'),
            onNetworkError: () => setMutationError('The request did not reach the server. Check the connection and try again.'),
            onFinish: () => { setMutationProcessing(false); mutationInFlight.current = false; },
        };
        if (pendingMutation.type === 'toggle') router.patch(route('module-definitions.status', pendingMutation.definition.id), {}, options);
        if (pendingMutation.type === 'ensure-conservation') router.post(route('module-definitions.ensure-canonical-conservation'), {}, options);
        if (pendingMutation.type === 'ensure-engp') router.post(route('module-definitions.ensure-canonical-engp'), {}, options);
    };
    const appliedFilters = [
        ...(filters.search ? [{ key: 'search', label: 'Search', value: filters.search, onRemove: () => setFilter('search', '') }] : []),
        ...(filters.program_area ? [{ key: 'program_area', label: 'Program Area', value: programAreas.find(item => item.value === filters.program_area)?.label || filters.program_area, onRemove: () => setFilter('program_area', '') }] : []),
        ...(filters.module_type ? [{ key: 'module_type', label: 'Module Type', value: filters.module_type === 'plan' ? 'Plan' : 'Regular Target', onRemove: () => setFilter('module_type', '') }] : []),
        ...(filters.status ? [{ key: 'status', label: 'Status', value: filters.status === 'active' ? 'Active' : 'Inactive', onRemove: () => setFilter('status', '') }] : []),
    ];

    return <SettingsShell active="Module Management">
        <Head title="Module Management" />
        <SettingsPageHeader title="Module Management" description="Manage CDS-SMART modules and availability." />
        <section className="cds-card-surface mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-5">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-2">
                    <FloatingInput id="module-search" label="Search modules" size="sm" value={filters.search || ''} onChange={event => setFilter('search', event.target.value)} placeholder="Search modules" className="min-w-0" />
                    <FilterToolbar className="self-end" chips={appliedFilters} onClear={() => router.get(route('module-definitions.index'), { ...filters, search: undefined, program_area: undefined, module_type: undefined, status: undefined }, { preserveState: true, preserveScroll: true, replace: true })}>
                        <div className="grid gap-3 sm:grid-cols-3"><FilterSelect value={filters.program_area || ''} onChange={value => setFilter('program_area', value)} label="All Program Areas" options={programAreas} /><FilterSelect value={filters.module_type || ''} onChange={value => setFilter('module_type', value)} label="All Module Types" options={[{ value: 'regular_target', label: 'Regular Target' }, { value: 'plan', label: 'Plan' }]} /><FilterSelect value={filters.status || ''} onChange={value => setFilter('status', value)} label="All Statuses" options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} /></div>
                    </FilterToolbar>
                </div>
                <div className="flex flex-wrap gap-2">{needsCanonicalConservationSetup && <button type="button" title="Adds only missing canonical generic PA and native PAMO definitions. Existing settings and inactive status are preserved; Management Plans is excluded." onClick={() => { setMutationError(''); setPendingMutation({ type: 'ensure-conservation' }); }} className="h-10 rounded-lg border border-emerald-700 px-4 text-xs font-bold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-emerald-300 dark:hover:bg-emerald-950/40" data-cds-action="true" data-cds-action-variant="primary">Add missing canonical Conservation definitions</button>}{needsCanonicalEngpSetup && <button type="button" title="Adds only missing ENGP definitions from the registered workflow metadata. Existing settings and inactive status are preserved." onClick={() => { setMutationError(''); setPendingMutation({ type: 'ensure-engp' }); }} className="h-10 rounded-lg border border-emerald-700 px-4 text-xs font-bold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-emerald-300 dark:hover:bg-emerald-950/40" data-cds-action="true" data-cds-action-variant="primary">Add missing canonical ENGP definitions</button>}<button type="button" onClick={startCreate} className="h-10 rounded-lg bg-gradient-to-r from-emerald-600 to-green-700 px-4 text-xs font-bold text-white shadow-sm transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600" data-cds-action="true" data-cds-action-variant="primary">+ Create Module</button></div>
            </div>
            <div className="mt-4 max-h-[min(34rem,70vh)] overflow-auto custom-table-scrollbar" role="region" aria-label="Module definitions; scroll to view all rows" tabIndex={0}>
                <table className="cds-data-table min-w-[880px] w-full text-left"><thead className="sticky top-0 z-20 bg-white shadow-sm dark:bg-gray-900"><tr className="border-y border-slate-100 bg-white text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400"><th className="px-3 py-3">Module Name</th><th className="px-3 py-3">Program Area</th><th className="px-3 py-3">Type</th><th className="px-3 py-3">Frequency / Duration</th><th className="px-3 py-3">Deadline</th><th className="px-3 py-3">Status</th><th className="px-3 py-3 text-right">Actions</th></tr></thead>
                    <tbody>{definitions.map(definition => <tr key={definition.id} className="border-b border-slate-100 text-xs text-slate-700 dark:border-gray-800 dark:text-gray-200"><td className="px-3 py-3"><p className="font-bold text-slate-900 dark:text-white">{definition.name}</p><p className="mt-0.5 font-mono text-[10px] text-slate-400">{definition.code}{definition.implementation_type === 'specialized' ? ' · Specialized' : ''}</p></td><td className="px-3 py-3">{definition.program_area_label}</td><td className="px-3 py-3">{definition.module_type === 'plan' ? 'Plan' : 'Regular Target'}</td><td className="px-3 py-3">{definition.module_type === 'plan' ? `${definition.plan_duration_years || 'Not recorded'} Years` : titleCase(definition.reporting_frequency) || 'Not recorded'}</td><td className="px-3 py-3">{definition.deadline_mode === 'standard_working_days' ? `${definition.default_deadline_days} Working Days` : deadlineLabels[definition.deadline_mode]}</td><td className="px-3 py-3"><span className={`rounded-full px-2 py-1 text-[10px] font-bold ${definition.is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-gray-800 dark:text-gray-300'}`}>{definition.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-3 py-3 text-right"><ModuleDefinitionActions definition={definition} onEdit={() => startEdit(definition)} onToggle={() => { setMutationError(''); setPendingMutation({ type: 'toggle', definition }); }} /></td></tr>)}{definitions.length === 0 && <tr><td colSpan="7" className="px-3 py-10 text-center text-sm text-slate-500">No module definitions match these filters.</td></tr>}</tbody>
                </table>
            </div>
        </section>
        <ModuleForm open={open} form={form} editing={editing} programAreas={programAreas} frequencies={frequencies} developmentOffices={developmentOffices} onClose={() => !form.processing && setOpen(false)} onSubmit={submit} />
        <ConfirmDialog open={Boolean(pendingMutation)} title={pendingMutation?.type === 'toggle' ? `${pendingMutation.definition.is_active ? 'Deactivate' : 'Activate'} module?` : 'Add module definitions?'} message={<><span>{pendingMutation?.type === 'toggle' ? `This changes whether ${pendingMutation.definition.name} is active for future submissions and navigation.` : pendingMutation?.type === 'ensure-conservation' ? 'This adds only missing canonical Conservation definitions; existing settings and inactive status are preserved.' : 'This adds only missing ENGP definitions from the registered workflow metadata; existing settings and inactive status are preserved.'}</span>{mutationError && <span role="alert" className="mt-3 block rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-left text-xs leading-5 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{mutationError}</span>}</>} confirmLabel={pendingMutation?.type === 'toggle' ? (pendingMutation.definition.is_active ? 'Deactivate Module' : 'Activate Module') : 'Add Missing Definitions'} cancelLabel="Go Back" processing={mutationProcessing} onConfirm={confirmMutation} onCancel={() => { if (!mutationProcessing) { setPendingMutation(null); setMutationError(''); } }} />
    </SettingsShell>;
}

export function ModuleForm({ open = true, form, editing, programAreas, frequencies, developmentOffices = [], onClose, onSubmit }) {
    const overlayRef = useRef(null);
    const formRef = useRef(null);
    const saveBypassRef = useRef(false);
    const saveInFlightRef = useRef(false);
    const wasProcessingRef = useRef(form.processing);
    const [saveConfirmationOpen, setSaveConfirmationOpen] = useState(false);
    const { present, phase, onTransitionEnd } = useModalMotion(open, { onClose, panelRef: formRef, layerRef: overlayRef });
    useEffect(() => {
        if (form.processing) {
            wasProcessingRef.current = true;
            saveInFlightRef.current = true;
            return;
        }
        if (wasProcessingRef.current) {
            wasProcessingRef.current = false;
            saveInFlightRef.current = false;
            saveBypassRef.current = false;
        }
    }, [form.processing]);
    const isGeneric = !editing || editing.implementation_type === 'generic';
    const isPlan = form.data.module_type === 'plan';
    const standard = ['standard_working_days', 'calendar_days'].includes(form.data.deadline_mode);
    const custom = form.data.deadline_mode === 'custom';
    const configurableFlow = isGeneric && (!editing || form.data.configure_workflow === true);
    const flowRequired = !editing || editing.configured_generic_flow === true || form.data.configure_workflow === true;
    const supportedArea = form.data.unit === 'development' ? 'engp' : 'protected_area_management_and_development';
    const areaOptions = programAreas.filter(area => area.value === supportedArea || (editing && area.value === form.data.program_area));
    const set = (field, value) => form.setData(field, value);
    const setUnit = unit => {
        set('unit', unit);
        set('program_area', unit === 'development' ? 'engp' : 'protected_area_management_and_development');
        if (unit !== 'development') { set('offices', []); set('reminder_offices', []); }
        else if (!(form.data.offices || []).length) set('offices', [...developmentOffices]);
    };
    if (!present) return null;
    return <ModalPortal><div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" role="presentation">
        <form ref={formRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} onSubmit={event => { if (saveBypassRef.current) { saveBypassRef.current = false; if (!saveInFlightRef.current && !form.processing) { saveInFlightRef.current = true; onSubmit(event); } else event.preventDefault(); return; } event.preventDefault(); if (!saveInFlightRef.current && !form.processing) setSaveConfirmationOpen(true); }} inert={saveConfirmationOpen || undefined} aria-hidden={saveConfirmationOpen || undefined} role="dialog" aria-modal="true" aria-label={editing ? 'Edit module definition' : 'Create module definition'} data-motion-state={phase} className="cds-modal-motion-panel max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <header className="flex items-start justify-between border-b border-slate-100 px-5 py-4 dark:border-gray-800"><div><h2 className="text-base font-bold text-slate-900 dark:text-white">{editing ? 'Edit Module' : 'Create Module'}</h2><p className="mt-1 text-xs text-slate-500">Configure a shared report workflow for Conservation or Development. Existing canonical adapters remain in place.</p></div><button type="button" onClick={onClose} className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="Close module form"><Icon icon="lucide:x" width="16" height="16" aria-hidden="true" /></button></header>
            <div className="grid gap-4 p-5 sm:grid-cols-2">
                <Field label="Module Name" error={form.errors.name} required className="sm:col-span-2"><input required value={form.data.name} onChange={event => set('name', event.target.value)} /></Field>
                <Field label="Unit" error={form.errors.unit} required><FloatingSelect label="" size="sm" required disabled={Boolean(editing)} value={form.data.unit} onChange={event => setUnit(event.target.value)}><option value="conservation">Conservation</option><option value="development">Development</option></FloatingSelect></Field>
                <Field label="Program Area" error={form.errors.program_area} required><FloatingSelect label="" size="sm" required disabled={Boolean(editing)} value={form.data.program_area || supportedArea} onChange={event => set('program_area', event.target.value)}><option value="">Select Program Area</option>{areaOptions.map(area => <option key={area.value} value={area.value}>{area.label}</option>)}</FloatingSelect></Field>
                <Field label="Module Type" error={form.errors.module_type} required><FloatingSelect label="" size="sm" required value={form.data.module_type} onChange={event => set('module_type', event.target.value)}><option value="regular_target">Regular Target</option><option value="plan">Plan</option></FloatingSelect></Field>
                <Field label="Reporting Frequency" error={form.errors.reporting_frequency} required><FloatingSelect label="" size="sm" required value={form.data.reporting_frequency || ''} onChange={event => set('reporting_frequency', event.target.value)}><option value="">Select Frequency</option>{frequencies.map(frequency => <option key={frequency} value={frequency}>{titleCase(frequency)}</option>)}</FloatingSelect></Field>
                {editing && isGeneric && <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-gray-200 sm:col-span-2"><input type="checkbox" checked={form.data.configure_workflow === true} onChange={event => set('configure_workflow', event.target.checked)} className="rounded border-slate-300 text-green-700 focus:ring-green-600" />Configure this generic module's report workflow</label>}
                {configurableFlow && form.data.reporting_frequency === 'custom' && <Field label="Custom Reporting Periods" error={form.errors.period_labels} required={flowRequired} className="sm:col-span-2"><textarea required={flowRequired} rows="3" value={form.data.period_labels || ''} onChange={event => set('period_labels', event.target.value)} placeholder="Annual survey | 2027-01-01 | 2027-12-31" /><span className="mt-1 block text-[11px] text-slate-500">Enter one period per line: Label | start date | end date (YYYY-MM-DD).</span></Field>}
                {configurableFlow && <Field label="Activity Name" error={form.errors.activity_name} required={flowRequired}><input required={flowRequired} value={form.data.activity_name || ''} onChange={event => set('activity_name', event.target.value)} /></Field>}
                {configurableFlow && <Field label="Required Document Types" error={form.errors.document_types} required={flowRequired}><textarea required={flowRequired} rows="2" value={form.data.document_types || ''} onChange={event => set('document_types', event.target.value)} placeholder="Progress Report, Final Report" /><span className="mt-1 block text-[11px] text-slate-500">Separate document choices with commas or new lines.</span></Field>}
                {configurableFlow && form.data.unit === 'development' && <fieldset className="sm:col-span-2"><legend className="mb-2 text-xs font-semibold text-slate-700 dark:text-gray-200">Offices allowed to submit</legend><p className="mb-2 text-[11px] text-slate-500">These offices can create the report. Reminder recipients are configured separately.</p><div className="grid gap-2 sm:grid-cols-2">{developmentOffices.map(office => <label key={office} className="flex items-center gap-2 text-sm text-slate-700 dark:text-gray-200"><input type="checkbox" checked={(form.data.offices || []).includes(office)} onChange={event => set('offices', event.target.checked ? [...(form.data.offices || []), office] : (form.data.offices || []).filter(item => item !== office))} className="rounded border-slate-300 text-green-700 focus:ring-green-600" />{office}</label>)}</div>{form.errors.offices && <span className="mt-1 block text-xs text-red-600" role="alert">{form.errors.offices}</span>}</fieldset>}
                {configurableFlow && form.data.unit === 'development' && <fieldset className="sm:col-span-2"><legend className="mb-2 text-xs font-semibold text-slate-700 dark:text-gray-200">Reminder applicability by office</legend><p className="mb-2 text-[11px] text-slate-500">Leave all offices unchecked to send reminders only for reports already recorded.</p><div className="grid gap-2 sm:grid-cols-2">{developmentOffices.map(office => <label key={office} className="flex items-center gap-2 text-sm text-slate-700 dark:text-gray-200"><input type="checkbox" checked={(form.data.reminder_offices || []).includes(office)} onChange={event => set('reminder_offices', event.target.checked ? [...(form.data.reminder_offices || []), office] : (form.data.reminder_offices || []).filter(item => item !== office))} className="rounded border-slate-300 text-green-700 focus:ring-green-600" />{office}</label>)}</div>{form.errors.reminder_offices && <span className="mt-1 block text-xs text-red-600" role="alert">{form.errors.reminder_offices}</span>}</fieldset>}
                <Field label="Deadline Type" error={form.errors.deadline_mode} required><FloatingSelect label="" size="sm" required value={form.data.deadline_mode} onChange={event => set('deadline_mode', event.target.value)}><option value="standard_working_days">Standard Working Days</option><option value="calendar_days">Calendar Days</option><option value="custom">Custom Deadline</option><option value="none">No Deadline</option></FloatingSelect></Field>
                {(standard || custom) && <Field label="Default Deadline Days" error={form.errors.default_deadline_days} required={standard}><input required={standard} type="number" min="1" max="366" value={form.data.default_deadline_days || ''} onChange={event => set('default_deadline_days', event.target.value)} /></Field>}
                {custom && <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700 dark:text-gray-200"><input type="checkbox" checked={form.data.allow_deadline_override} onChange={event => set('allow_deadline_override', event.target.checked)} className="rounded border-slate-300 text-green-700 focus:ring-green-600" />Allow workflow deadline override</label>}
                <Field label="Description" error={form.errors.description} className="sm:col-span-2"><textarea value={form.data.description} onChange={event => set('description', event.target.value)} rows="3" /></Field>
                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-gray-200"><input type="checkbox" checked={form.data.is_active} onChange={event => set('is_active', event.target.checked)} className="rounded border-slate-300 text-green-700 focus:ring-green-600" />Active for new submissions/navigation</label>
                {editing && <p className="self-center text-xs text-slate-500">Code: <span className="font-mono">{editing.code}</span> (stable)</p>}
            </div>
            <footer className="flex justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-gray-800"><button type="button" onClick={onClose} className="h-10 rounded-lg border border-slate-200 px-4 text-xs font-bold text-slate-700 dark:border-gray-700 dark:text-gray-200" data-cds-action="true" data-cds-action-variant="cancel">Cancel</button><button type="submit" disabled={form.processing} className="h-10 rounded-lg bg-green-700 px-4 text-xs font-bold text-white disabled:opacity-50" data-cds-action="true" data-cds-action-variant="primary">{form.processing ? 'Saving\u2026' : editing ? 'Save Changes' : 'Create Module'}</button></footer>
        </form>
        <ConfirmDialog open={saveConfirmationOpen} title={editing ? 'Save module changes?' : 'Create this module?'} message="This saves the module definition and its configuration for future submissions." confirmLabel={editing ? 'Save Changes' : 'Create Module'} cancelLabel="Go Back" processing={form.processing} onConfirm={() => { if (!formRef.current || saveInFlightRef.current || form.processing) return; if (!formRef.current.checkValidity()) { setSaveConfirmationOpen(false); window.requestAnimationFrame(() => formRef.current?.reportValidity()); return; } saveBypassRef.current = true; setSaveConfirmationOpen(false); formRef.current.requestSubmit(); }} onCancel={() => !form.processing && setSaveConfirmationOpen(false)} />
    </div></ModalPortal>;
}

export function buildModuleFilterParams(filters, name, value) { return { ...filters, [name]: value || undefined }; }
export function FilterSelect({ value, onChange, label, options }) { const fieldLabel = label.replace(/^All\s+/i, ''); return <div className="min-w-0"><FloatingSelect label={fieldLabel} size="sm" value={value} onChange={event => onChange(event.target.value)}><option value="">{label}</option>{options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</FloatingSelect></div>; }
export function ModuleDefinitionActions({ definition, onEdit, onToggle }) { return <div className="inline-flex flex-wrap items-center gap-2"><button type="button" onClick={onEdit} className="cds-compact-action rounded-md font-semibold text-green-800 hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300 dark:hover:bg-green-950/30" data-cds-action="true" data-cds-action-variant="primary">Edit</button><button type="button" onClick={onToggle} className="cds-compact-action rounded-md font-semibold text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-300 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant={definition.is_active ? 'warning' : 'primary'}>{definition.is_active ? 'Deactivate' : 'Activate'}</button></div>; }
function Field({ label, error, required, className = '', children }) {
    const nodes = Children.toArray(children);
    const index = nodes.findIndex(node => isValidElement(node) && (
        node.type === FloatingSelect
        || node.type === 'input'
        || node.type === 'textarea'
    ));
    if (index < 0) return <div className={`block min-w-0 ${className}`}>{children}{error && <span className="mt-1 block text-xs text-red-600" role="alert">{error}</span>}</div>;

    const [control] = nodes.splice(index, 1);
    const trailing = nodes;
    if (control.type === FloatingSelect) {
        return <div className={`min-w-0 ${className}`}>{cloneElement(control, { label, error, required: required ?? control.props.required, size: control.props.size || 'sm' })}{trailing}</div>;
    }

    const controlProps = { ...control.props };
    delete controlProps.className;
    const Component = control.type === 'textarea' ? FloatingTextarea : FloatingInput;
    return <div className={`min-w-0 ${className}`}><Component {...controlProps} label={label} error={error} required={required ?? controlProps.required} size="sm" />{trailing}</div>;
}
function titleCase(value) { return value ? value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) : ''; }
