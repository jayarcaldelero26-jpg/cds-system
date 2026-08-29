import { useEffect, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BusinessCalendarMonth from '@/Components/BusinessCalendarMonth';
import PageHeader from '@/Components/PageHeader';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { formatReportDate } from '@/Utils/dateFormatters';

const initialForm = { date: '', name: '', type: 'NATIONAL_HOLIDAY', scope: 'NATIONAL', location: '', reference: '', remarks: '', is_active: true };
const typeLabels = {
    NATIONAL_HOLIDAY: 'National Holiday',
    LOCAL_HOLIDAY: 'Local Holiday',
    SPECIAL_NON_WORKING_DAY: 'Special Non-Working Day',
    OFFICE_DECLARED_NON_WORKING_DAY: 'Office-Declared Non-Working Day',
    OTHER: 'Other',
};

function CalendarIcon() {
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2" /><path d="M7.5 3.5v3M16.5 3.5v3M3.5 9.5h17M7.5 13h.01M11.5 13h.01M15.5 13h.01M7.5 16.5h.01M11.5 16.5h.01" /></svg>;
}

function CalendarEventFormModal({ open, editing, form, onClose, onSubmit }) {
    useEffect(() => {
        if (!open || form.processing) return undefined;
        const onKeyDown = event => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [form.processing, onClose, open]);

    if (!open) return null;
    const title = editing ? 'Edit Non-Working Day' : 'Add Non-Working Day';

    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-[1px]" role="presentation">
        <form onSubmit={onSubmit} role="dialog" aria-modal="true" aria-label={title} className="flex max-h-[calc(100vh-2rem)] w-full max-w-[52rem] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <header className="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-100 text-green-700 dark:bg-green-950/60 dark:text-green-300"><CalendarIcon /></span>
                    <div className="min-w-0"><h2 className="truncate text-sm font-semibold text-gray-900 dark:text-white">{title}</h2><p className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">Business calendar configuration</p></div>
                </div>
                <button type="button" onClick={onClose} disabled={form.processing} className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-400 transition hover:bg-gray-50 hover:text-gray-700 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200" aria-label="Close form">&times;</button>
            </header>

            <div className="custom-table-scrollbar min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                    <CalendarField label="Date" required error={form.errors.date}><CalendarInput type="date" required value={form.data.date || ''} onChange={event => form.setData('date', event.target.value)} /></CalendarField>
                    <CalendarField label="Name / Description" required error={form.errors.name}><CalendarInput required value={form.data.name || ''} placeholder="Enter name or description" onChange={event => form.setData('name', event.target.value)} /></CalendarField>
                    <CalendarField label="Type" required error={form.errors.type}><CalendarSelect required value={form.data.type || ''} onChange={event => form.setData('type', event.target.value)}><option value="NATIONAL_HOLIDAY">National Holiday</option><option value="LOCAL_HOLIDAY">Local Holiday</option><option value="SPECIAL_NON_WORKING_DAY">Special Non-Working Day</option><option value="OFFICE_DECLARED_NON_WORKING_DAY">Office-Declared Non-Working Day</option><option value="OTHER">Other</option></CalendarSelect></CalendarField>
                    <CalendarField label="Scope" required error={form.errors.scope}><CalendarSelect required value={form.data.scope || ''} onChange={event => form.setData('scope', event.target.value)}><option value="NATIONAL">National</option><option value="DAVAO_ORIENTAL">Davao Oriental</option><option value="OFFICE">Office</option></CalendarSelect></CalendarField>
                    {form.data.scope === 'OFFICE' && <CalendarField label="Office / Location" required error={form.errors.location} className="sm:col-span-2"><CalendarInput required value={form.data.location || ''} placeholder="Enter office or location" onChange={event => form.setData('location', event.target.value)} /></CalendarField>}
                    <CalendarField label="Status" required error={form.errors.is_active}><CalendarSelect required value={form.data.is_active ? '1' : '0'} onChange={event => form.setData('is_active', event.target.value === '1')}><option value="1">Active</option><option value="0">Inactive</option></CalendarSelect></CalendarField>
                    <CalendarField label="Reference / Proclamation No. (optional)" error={form.errors.reference}><CalendarInput value={form.data.reference || ''} placeholder="Enter reference or proclamation number" onChange={event => form.setData('reference', event.target.value)} /></CalendarField>
                    <CalendarField label="Remarks (optional)" error={form.errors.remarks} className="sm:col-span-2"><textarea value={form.data.remarks || ''} onChange={event => form.setData('remarks', event.target.value)} placeholder="Enter any additional remarks" rows={4} className="min-h-[100px] w-full resize-y rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm leading-5 text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-green-500 dark:focus:ring-green-500/20" /></CalendarField>
                </div>
            </div>

            <footer className="flex items-center justify-end gap-2 border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 sm:px-6"><button type="button" onClick={onClose} disabled={form.processing} className="h-10 rounded-lg border border-gray-300 bg-white px-4 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</button><button type="submit" disabled={form.processing} className="h-10 rounded-lg bg-green-700 px-4 text-xs font-semibold text-white shadow-sm transition hover:bg-green-800 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600">{form.processing ? (editing ? 'Saving Changes...' : 'Saving...') : (editing ? 'Save Changes' : 'Save Non-Working Day')}</button></footer>
        </form>
    </div>;
}

function CalendarField({ label, required = false, error, className = '', children }) {
    return <label className={`block ${className}`}><span className="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-200">{label}{required && <span className="ml-0.5 text-red-600 dark:text-red-400">*</span>}</span>{children}{error && <span className="mt-1 block text-[11px] text-red-600 dark:text-red-400">{error}</span>}</label>;
}

function CalendarInput({ type = 'text', value, placeholder, onChange, required = false }) {
    return <input type={type} required={required} value={value} placeholder={placeholder} onChange={onChange} className="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm leading-5 text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-green-500 dark:focus:ring-green-500/20" />;
}

function CalendarSelect({ value, onChange, children, required = false }) {
    return <div className="relative"><select required={required} value={value} onChange={onChange} className="h-11 w-full appearance-none !bg-none rounded-lg border border-gray-300 bg-white px-3 pr-9 text-sm leading-5 text-gray-800 outline-none transition focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:focus:border-green-500 dark:focus:ring-green-500/20">{children}</select><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg></div>;
}

export default function CalendarIndex({ nonWorkingDays = [] }) {
    const { auth } = usePage().props;
    const canManage = Boolean(auth?.canManageComplianceAlerts);
    const [selected, setSelected] = useState(null);
    const [editing, setEditing] = useState(null);
    const [formOpen, setFormOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const form = useForm(initialForm);
    const resetForm = () => { setEditing(null); setFormOpen(false); form.reset(); form.clearErrors(); };
    const openCreate = date => { setEditing(null); form.setData({ ...initialForm, date: date || '' }); form.clearErrors(); setFormOpen(true); };
    const openEdit = day => { setSelected(null); setEditing(day); form.setData({ date: day.date || '', name: day.name || '', type: day.type || 'NATIONAL_HOLIDAY', scope: day.scope || 'NATIONAL', location: day.location || '', reference: day.reference || '', remarks: day.remarks || '', is_active: Boolean(day.is_active) }); form.clearErrors(); setFormOpen(true); };
    const toggleActive = day => {
        if (!day || form.processing) return;
        form.setData({ date: day.date || '', name: day.name || '', type: day.type || 'NATIONAL_HOLIDAY', scope: day.scope || 'NATIONAL', location: day.location || '', reference: day.reference || '', remarks: day.remarks || '', is_active: !day.is_active });
        form.put(route('compliance-alerts.non-working-days.update', day.id), { preserveScroll: true, onSuccess: () => setSelected(null) });
    };
    const submit = event => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: resetForm };
        editing ? form.put(route('compliance-alerts.non-working-days.update', editing.id), options) : form.post(route('compliance-alerts.non-working-days.store'), options);
    };
    const remove = () => { if (!deleteTarget) return; form.delete(route('compliance-alerts.non-working-days.destroy', deleteTarget.id), { preserveScroll: true, onSuccess: () => { setDeleteTarget(null); setSelected(null); } }); };

    return <AuthenticatedLayout title="Calendar">
        <Head title="Calendar" />
        <PageHeader title="Calendar" description="Manage holidays and declared non-working days used by the centralized report business calendar." />
        <div className="mt-5">
            <BusinessCalendarMonth nonWorkingDays={nonWorkingDays} onSelectEvent={setSelected} onAdd={openCreate} canManage={canManage} />
        </div>

        {selected && <CalendarEventDrawer event={selected} canManage={canManage} processing={form.processing} onClose={() => setSelected(null)} onEdit={() => openEdit(selected)} onToggleActive={() => toggleActive(selected)} onDelete={() => setDeleteTarget(selected)} />}

        <div className="lg:hidden">
            <CrudDetailsModal open={Boolean(selected)} icon={<CalendarIcon />} title="Non-Working Day Details" subtitle={selected ? formatReportDate(selected.date) : ''} onClose={() => setSelected(null)} canEdit={canManage} canDelete={canManage} onEdit={() => openEdit(selected)} onDelete={() => setDeleteTarget(selected)} editLabel="Edit" deleteLabel="Delete Non-Working Day" summary={selected && <CrudSummaryGrid items={[{ label: 'Date', value: formatReportDate(selected.date) }, { label: 'Type', value: typeLabels[selected.type] || selected.type }, { label: 'Scope', value: selected.scope }, { label: 'Status', value: selected.is_active ? 'Active' : 'Inactive' }]} />}>
                <CalendarEventContent event={selected} />
            </CrudDetailsModal>
        </div>

        <CalendarEventFormModal open={formOpen} editing={editing} form={form} onClose={resetForm} onSubmit={submit} />

        <ConfirmDialog open={Boolean(deleteTarget)} title="Delete non-working day?" message={`Delete ${deleteTarget?.name || 'this configured non-working day'}? This removes the event from the business calendar.`} confirmLabel="Delete" variant="danger" processing={form.processing} onConfirm={remove} onCancel={() => !form.processing && setDeleteTarget(null)} />
    </AuthenticatedLayout>;
}

function CalendarEventDrawer({ event, canManage, processing, onClose, onEdit, onToggleActive, onDelete }) {
    return <div className="fixed inset-0 z-40 hidden lg:block" role="presentation">
        <button type="button" className="absolute inset-0 h-full w-full bg-gray-950/25 backdrop-blur-[1px]" onClick={onClose} aria-label="Close event details" />
        <aside className="absolute inset-y-0 right-0 z-10 flex w-full max-w-sm flex-col border-l border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900" role="dialog" aria-modal="true" aria-label="Non-working day details">
            <header className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div className="flex min-w-0 items-center gap-3"><span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300"><CalendarIcon /></span><div className="min-w-0"><h2 className="truncate text-sm font-bold text-gray-900 dark:text-white">Event Details</h2><p className="truncate text-xs text-gray-500 dark:text-gray-400">{formatReportDate(event.date)}</p></div></div><button type="button" onClick={onClose} className="rounded-lg px-2 py-1 text-lg text-gray-400 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:hover:bg-gray-800" aria-label="Close event details">&times;</button></header>
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5"><div><p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">{typeLabels[event.type] || event.type || 'Other'}</p><h3 className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{event.name}</h3></div><div className="grid gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800"><DetailRow label="Date" value={formatReportDate(event.date)} /><DetailRow label="Type" value={typeLabels[event.type] || event.type} /><DetailRow label="Status" value={event.is_active ? 'Active' : 'Inactive'} /><DetailRow label="Scope" value={event.scope} />{event.location && <DetailRow label="Location" value={event.location} />}</div><div className="space-y-3 text-sm"><DetailRow label="Reference" value={event.reference} /><div><p className="text-xs font-bold text-gray-500 dark:text-gray-400">Remarks</p><p className="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-200">{event.remarks || '—'}</p></div></div></div>
            <footer className="flex flex-wrap items-center gap-2 border-t border-gray-100 p-4 dark:border-gray-800">{canManage && <><button type="button" onClick={onEdit} className="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs font-bold text-green-700 hover:bg-green-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300">Edit</button><button type="button" onClick={onToggleActive} disabled={processing} className="rounded-lg border border-gray-200 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-gray-50 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">{event.is_active ? 'Deactivate' : 'Activate'}</button><button type="button" onClick={onDelete} className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">Delete</button></>}<button type="button" onClick={onClose} className="ml-auto rounded-lg bg-green-700 px-3.5 py-2 text-xs font-bold text-white hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600">Close</button></footer>
        </aside>
    </div>;
}

function CalendarEventContent({ event }) {
    return <div className="space-y-2 text-sm"><p><span className="font-bold">Name / Description:</span> {event?.name || '—'}</p><p><span className="font-bold">Scope:</span> {event?.scope || '—'}{event?.location ? ` — ${event.location}` : ''}</p><p><span className="font-bold">Reference:</span> {event?.reference || '—'}</p><p><span className="font-bold">Remarks:</span> {event?.remarks || '—'}</p></div>;
}

function DetailRow({ label, value }) {
    return <div className="flex items-start justify-between gap-4"><span className="text-xs text-gray-500 dark:text-gray-400">{label}</span><span className="text-right text-xs font-semibold text-gray-800 dark:text-gray-100">{value || '—'}</span></div>;
}
