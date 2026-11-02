import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BusinessCalendarMonth from '@/Components/BusinessCalendarMonth';
import PageHeader from '@/Components/PageHeader';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import ConfirmDialog from '@/Components/ConfirmDialog';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import Button from '@/Components/Button';
import { formatReportDate } from '@/Utils/dateFormatters';
import DatePicker from '@/Components/DatePicker';
import DateRangePicker from '@/Components/DateRangePicker';
import PremiumTimePicker from '@/Components/PremiumTimePicker';
import FloatingSelect from '@/Components/Form/FloatingSelect';
import FloatingInput from '@/Components/Form/FloatingInput';
import FloatingTextarea from '@/Components/Form/FloatingTextarea';
import UtilityIconButton from '@/Components/UtilityIconButton';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';

const initialForm = { date: '', name: '', type: 'NATIONAL_HOLIDAY', scope: 'NATIONAL', location: '', reference: '', remarks: '', is_active: true };
const initialTask = { title: '', scheduled_date: '', scheduled_end_date: '', is_all_day: true, start_time: '', end_time: '', notes: '' };
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

export function CalendarEventFormModal({ open, editing, form, onClose, onSubmit }) {
    const overlayRef = useRef(null);
    const panelRef = useRef(null);
    const processingRef = useRef(form.processing);
    const saveBypassRef = useRef(false);
    const saveInFlightRef = useRef(false);
    const wasProcessingRef = useRef(form.processing);
    const [saveConfirmationOpen, setSaveConfirmationOpen] = useState(false);
    processingRef.current = form.processing;
    const { present, phase, onTransitionEnd } = useModalMotion(open, { onClose: () => { if (!processingRef.current) onClose?.(); }, panelRef, layerRef: overlayRef });
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
    if (!present) return null;
    const title = editing ? 'Edit Non-Working Day' : 'Add Non-Working Day';
    const handleSubmit = event => {
        if (saveBypassRef.current) {
            saveBypassRef.current = false;
            onSubmit(event);
            return;
        }
        event.preventDefault();
        if (!saveInFlightRef.current && !processingRef.current) setSaveConfirmationOpen(true);
    };
    const confirmSave = () => {
        if (!panelRef.current || saveInFlightRef.current || processingRef.current) return;
        if (!panelRef.current.checkValidity()) {
            setSaveConfirmationOpen(false);
            panelRef.current.reportValidity();
            return;
        }
        saveBypassRef.current = true;
        setSaveConfirmationOpen(false);
        panelRef.current.requestSubmit();
    };

    return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-[1px]" role="presentation">
        <form ref={panelRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} onSubmit={handleSubmit} role="dialog" aria-modal="true" aria-label={title} data-motion-state={phase} inert={saveConfirmationOpen || undefined} aria-hidden={saveConfirmationOpen || undefined} className="cds-modal-motion-panel flex max-h-[calc(100vh-2rem)] w-full max-w-[52rem] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <header className="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-100 text-green-700 dark:bg-green-950/60 dark:text-green-300"><CalendarIcon /></span>
                    <div className="min-w-0"><h2 className="truncate text-sm font-semibold text-gray-900 dark:text-white">{title}</h2><p className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">Business calendar configuration</p></div>
                </div>
                <CalendarFormCloseButton onClose={onClose} disabled={form.processing} />
            </header>

            <div className="custom-table-scrollbar min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                <div className="grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                    <CalendarField label="Date" required error={form.errors.date}><DatePicker id="calendar-event-date" value={form.data.date || ''} onChange={(value) => form.setData('date', value)} required /></CalendarField>
                    <CalendarField label="Name / Description" required error={form.errors.name}><CalendarInput required value={form.data.name || ''} placeholder="Enter name or description" onChange={event => form.setData('name', event.target.value)} /></CalendarField>
                    <CalendarField label="Type" required error={form.errors.type}><CalendarSelect required value={form.data.type || ''} onChange={event => form.setData('type', event.target.value)}><option value="NATIONAL_HOLIDAY">National Holiday</option><option value="LOCAL_HOLIDAY">Local Holiday</option><option value="SPECIAL_NON_WORKING_DAY">Special Non-Working Day</option><option value="OFFICE_DECLARED_NON_WORKING_DAY">Office-Declared Non-Working Day</option><option value="OTHER">Other</option></CalendarSelect></CalendarField>
                    <CalendarField label="Scope" required error={form.errors.scope}><CalendarSelect required value={form.data.scope || ''} onChange={event => form.setData('scope', event.target.value)}><option value="NATIONAL">National</option><option value="DAVAO_ORIENTAL">Davao Oriental</option><option value="OFFICE">Office</option></CalendarSelect></CalendarField>
                    {form.data.scope === 'OFFICE' && <CalendarField label="Office / Location" required error={form.errors.location} className="sm:col-span-2"><CalendarInput required value={form.data.location || ''} placeholder="Enter office or location" onChange={event => form.setData('location', event.target.value)} /></CalendarField>}
                    <CalendarField label="Status" required error={form.errors.is_active}><CalendarSelect required value={form.data.is_active ? '1' : '0'} onChange={event => form.setData('is_active', event.target.value === '1')}><option value="1">Active</option><option value="0">Inactive</option></CalendarSelect></CalendarField>
                    <CalendarField label="Reference / Proclamation No. (optional)" error={form.errors.reference}><CalendarInput value={form.data.reference || ''} placeholder="Enter reference or proclamation number" onChange={event => form.setData('reference', event.target.value)} /></CalendarField>
                    <CalendarField label="Remarks (optional)" error={form.errors.remarks} className="sm:col-span-2"><textarea value={form.data.remarks || ''} onChange={event => form.setData('remarks', event.target.value)} placeholder="Enter any additional remarks" rows={4} className="min-h-[100px] w-full resize-y rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm leading-5 text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-green-500 dark:focus:ring-green-500/20" /></CalendarField>
                </div>
            </div>

            <footer className="flex items-center justify-end gap-2 border-t border-gray-100 px-5 py-3.5 dark:border-gray-800 sm:px-6"><button type="button" onClick={onClose} disabled={form.processing} className="h-10 rounded-lg border border-gray-300 bg-white px-4 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant="cancel">Cancel</button><button type="submit" disabled={form.processing} className="h-10 rounded-lg bg-green-700 px-4 text-xs font-semibold text-white shadow-sm transition hover:bg-green-800 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600" data-cds-action="true" data-cds-action-variant="primary">{form.processing ? (editing ? 'Saving Changes...' : 'Saving...') : (editing ? 'Save Changes' : 'Save Non-Working Day')}</button></footer>
        </form>
        <ConfirmDialog open={saveConfirmationOpen} title={editing ? 'Save non-working day changes?' : 'Add this non-working day?'} message="This updates the configured business calendar dates used by existing deadline calculations." confirmLabel={editing ? 'Save Changes' : 'Add Non-Working Day'} cancelLabel="Go Back" processing={form.processing} onConfirm={confirmSave} onCancel={() => !form.processing && setSaveConfirmationOpen(false)} />
    </div>;
}

export function CalendarFormCloseButton({ onClose, disabled = false }) {
    return <UtilityIconButton compact type="button" onClick={onClose} disabled={disabled} aria-label="Close form" className="shrink-0 border border-gray-200 text-gray-400 hover:bg-gray-50 hover:text-gray-700 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200">&times;</UtilityIconButton>;
}

function CalendarField({ label, required = false, error, className = '', children }) {
    return <label className={`block ${className}`}><span className="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-200">{label}{required && <span className="ml-0.5 text-red-600 dark:text-red-400">*</span>}</span>{children}{error && <span className="mt-1 block text-[11px] text-red-600 dark:text-red-400">{error}</span>}</label>;
}

function CalendarInput({ type = 'text', value, placeholder, onChange, required = false }) {
    return <input type={type} required={required} value={value} placeholder={placeholder} onChange={onChange} className="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm leading-5 text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-green-500 dark:focus:ring-green-500/20" />;
}

export function CalendarSelect({ value, onChange, children, required = false }) {
    return <FloatingSelect label="" size="sm" variant="calendar" required={required} value={value} onChange={onChange}>{children}</FloatingSelect>;
}

function clockValue(value) {
    return String(value || '').match(/(?:^|T)(\d{2}:\d{2})$/)?.[1] || '';
}

function taskStatusLabel(status) {
    return status === 'done' ? 'Completed' : status === 'cancelled' ? 'Cancelled' : 'Scheduled';
}

function attributionTimestamp(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(date);
}

export default function CalendarIndex({ view = 'month', year, month, filters = {}, modules = [], protectedAreas = [], movEvents = [], yearSummary = null, nonWorkingDays = [], tasks = [], taskStorageReady = false, taskRangeReady = false }) {
    const { auth } = usePage().props;
    const canManage = Boolean(auth?.canManageComplianceAlerts);
    const [selected, setSelected] = useState(null);
    const [selectedMov, setSelectedMov] = useState(null);
    const [editing, setEditing] = useState(null);
    const [formOpen, setFormOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteError, setDeleteError] = useState('');
    const [nonWorkingDayStatusAction, setNonWorkingDayStatusAction] = useState(null);
    const [nonWorkingDayStatusProcessing, setNonWorkingDayStatusProcessing] = useState(false);
    const [nonWorkingDayStatusError, setNonWorkingDayStatusError] = useState('');
    const [selectedTask, setSelectedTask] = useState(null);
    const [editingTask, setEditingTask] = useState(null);
    const [taskDateMode, setTaskDateMode] = useState('single');
    const [taskFormOpen, setTaskFormOpen] = useState(false);
    const [taskStatusAction, setTaskStatusAction] = useState(null);
    const [taskStatusProcessing, setTaskStatusProcessing] = useState(false);
    const [taskStatusError, setTaskStatusError] = useState('');
    const [taskFormNotice, setTaskFormNotice] = useState('');
    const taskStatusInFlight = useRef(false);
    const nonWorkingDayStatusInFlight = useRef(false);
    const form = useForm(initialForm);
    const taskForm = useForm(initialTask);
    const resetForm = useCallback(() => { setEditing(null); setFormOpen(false); form.reset(); form.clearErrors(); }, [form.reset, form.clearErrors]);
    const openCreate = useCallback(date => { setEditing(null); form.setData({ ...initialForm, date: date || '' }); form.clearErrors(); setFormOpen(true); }, [form.setData, form.clearErrors]);
    const openEdit = day => { setSelected(null); setEditing(day); form.setData({ date: day.date || '', name: day.name || '', type: day.type || 'NATIONAL_HOLIDAY', scope: day.scope || 'NATIONAL', location: day.location || '', reference: day.reference || '', remarks: day.remarks || '', is_active: Boolean(day.is_active) }); form.clearErrors(); setFormOpen(true); };
    const toggleActive = day => {
        if (!canManage || !day || nonWorkingDayStatusInFlight.current) return;
        setNonWorkingDayStatusError('');
        setNonWorkingDayStatusAction(day);
    };
    const submitNonWorkingDayStatus = () => {
        const day = nonWorkingDayStatusAction;
        if (!canManage || !day || nonWorkingDayStatusInFlight.current) return;
        nonWorkingDayStatusInFlight.current = true;
        setNonWorkingDayStatusProcessing(true);
        setNonWorkingDayStatusError('');
        router.put(route('compliance-alerts.non-working-days.update', day.id), {
            date: day.date || '', name: day.name || '', type: day.type || 'NATIONAL_HOLIDAY',
            scope: day.scope || 'NATIONAL', location: day.location || '', reference: day.reference || '',
            remarks: day.remarks || '', is_active: !day.is_active,
        }, {
            preserveScroll: true,
            onSuccess: () => { setNonWorkingDayStatusAction(null); setNonWorkingDayStatusError(''); setSelected(null); },
            onError: errors => setNonWorkingDayStatusError(Object.values(errors || {}).find(value => typeof value === 'string' && value.trim()) || 'The non-working day status was not changed. Review its current state and try again.'),
            onHttpException: () => setNonWorkingDayStatusError('The server could not update this business-calendar date. Details remain open; review the event and try again.'),
            onNetworkError: () => setNonWorkingDayStatusError('The request did not reach the server. Details remain open; check the connection and try again.'),
            onFinish: () => { setNonWorkingDayStatusProcessing(false); nonWorkingDayStatusInFlight.current = false; },
        });
    };
    const submit = event => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: resetForm };
        editing ? form.put(route('compliance-alerts.non-working-days.update', editing.id), options) : form.post(route('compliance-alerts.non-working-days.store'), options);
    };
    const openDelete = day => { if (!canManage || !day || form.processing) return; setDeleteError(''); setDeleteTarget(day); };
    const remove = () => {
        if (!deleteTarget || form.processing) return;
        setDeleteError('');
        form.delete(route('compliance-alerts.non-working-days.destroy', deleteTarget.id), {
            preserveScroll: true,
            onSuccess: () => { setDeleteTarget(null); setDeleteError(''); setSelected(null); },
            onError: errors => setDeleteError(Object.values(errors || {}).find(value => typeof value === 'string' && value.trim()) || 'The non-working day was not deleted. Review its current state and try again.'),
            onHttpException: () => setDeleteError('The server could not delete this non-working day. Details remain open; review the event and try again.'),
            onNetworkError: () => setDeleteError('The request did not reach the server. Details remain open; check the connection and try again.'),
        });
    };
    const resetTaskForm = useCallback(() => { setEditingTask(null); setTaskFormOpen(false); setTaskFormNotice(''); taskForm.reset(); taskForm.clearErrors(); }, [taskForm.reset, taskForm.clearErrors]);
    const openCreateTask = useCallback(date => { if (!taskStorageReady) return; setSelectedTask(null); setEditingTask(null); setTaskDateMode('single'); setTaskFormNotice(''); taskForm.setData({ ...initialTask, scheduled_date: date || '' }); taskForm.clearErrors(); setTaskFormOpen(true); }, [taskStorageReady, taskForm.setData, taskForm.clearErrors]);
    const openEditTask = task => { if (!task || task.status !== 'scheduled') return; setSelectedTask(null); setEditingTask(task); setTaskDateMode(task.scheduled_end_date ? 'range' : 'single'); setTaskFormNotice(''); taskForm.setData({ title: task.title || '', scheduled_date: task.scheduled_date || '', scheduled_end_date: task.scheduled_end_date || '', is_all_day: Boolean(task.is_all_day), start_time: task.start_time || '', end_time: task.end_time || '', notes: task.notes || '' }); taskForm.clearErrors(); setTaskFormOpen(true); };
    const beforeTaskConfirmation = () => {
        taskForm.clearErrors();
        const nextErrors = {};
        const addError = (field, message) => { nextErrors[field] = message; taskForm.setError(field, message); };
        const title = String(taskForm.data.title || '').trim();
        const date = String(taskForm.data.scheduled_date || '');
        const endDate = String(taskForm.data.scheduled_end_date || '');
        if (!title) addError('title', 'Enter a task title.');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) addError('scheduled_date', 'Choose a scheduled date.');
        if (taskDateMode === 'range' && !taskRangeReady) addError('scheduled_end_date', 'Date ranges are not available in task storage yet.');
        if (taskDateMode === 'range' && !endDate) addError('scheduled_end_date', 'Choose an end date.');
        if (taskDateMode === 'range' && endDate && date && endDate < date) addError('scheduled_end_date', 'The end date must be the same as or later than the start date.');
        const startTime = clockValue(taskForm.data.start_time);
        const endTime = clockValue(taskForm.data.end_time);
        const timeEndDate = taskDateMode === 'range' ? endDate || date : date;
        if (!taskForm.data.is_all_day && startTime && endTime && timeEndDate + endTime <= date + startTime) addError('end_time', 'The end must be later than the start date and time.');
        return Object.keys(nextErrors).length === 0;
    };
    const submitTask = event => {
        event.preventDefault();
        if (taskForm.processing) return;
        setTaskFormNotice('');
        const options = {
            preserveScroll: true,
            onSuccess: resetTaskForm,
            onHttpException: () => setTaskFormNotice('The server could not save this Calendar task. Your form remains open; review the values and try again.'),
            onNetworkError: () => setTaskFormNotice('The request did not reach the server. Your task form remains open; check the connection and try again.'),
        };
        editingTask ? taskForm.put(route('calendar.tasks.update', editingTask.id), options) : taskForm.post(route('calendar.tasks.store'), options);
    };
    const openTaskStatusConfirmation = status => { if (taskStatusInFlight.current || !selectedTask || selectedTask.status !== 'scheduled') return; setTaskStatusError(''); setTaskStatusAction({ task: selectedTask, status }); };
    const submitTaskStatus = () => {
        if (!taskStatusAction || taskStatusInFlight.current) return;
        taskStatusInFlight.current = true;
        setTaskStatusProcessing(true);
        setTaskStatusError('');
        const { task, status } = taskStatusAction;
        router.patch(route('calendar.tasks.status', task.id), { status }, {
            preserveScroll: true,
            onSuccess: () => { setTaskStatusAction(null); setTaskStatusError(''); setSelectedTask(null); },
            onError: errors => setTaskStatusError(Object.values(errors || {}).find(value => typeof value === 'string' && value.trim()) || 'The task status was not changed. Review the task and try again.'),
            onHttpException: () => setTaskStatusError('The server could not update this task. The details remain open; review the task and try again.'),
            onNetworkError: () => setTaskStatusError('The request did not reach the server. The details remain open; check the connection and try again.'),
            onFinish: () => { setTaskStatusProcessing(false); taskStatusInFlight.current = false; },
        });
    };

    return <AuthenticatedLayout title="Calendar">
        <Head title="Calendar" />
        <PageHeader title="Calendar" description="Visual monitoring of submitted reports, MOVs, and non-working days." />
        {!taskStorageReady && <p role="status" className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/35 dark:text-amber-100">Calendar task scheduling is unavailable until the separate task storage is installed. Reports and non-working days remain available.</p>}
        <div className="mt-5">
            <BusinessCalendarMonth view={view} year={year} month={month} filters={filters} modules={modules} protectedAreas={protectedAreas} movEvents={movEvents} yearSummary={yearSummary} nonWorkingDays={nonWorkingDays} tasks={tasks} taskStorageReady={taskStorageReady} onSelectMov={setSelectedMov} onSelectHoliday={setSelected} onSelectTask={setSelectedTask} onAdd={openCreate} onAddTask={openCreateTask} canManage={canManage} />
        </div>

        <MovDetailsModal event={selectedMov} onClose={() => setSelectedMov(null)} />

        <CalendarEventDrawer event={selected} canManage={canManage} processing={nonWorkingDayStatusProcessing || form.processing} onClose={() => setSelected(null)} onEdit={() => openEdit(selected)} onToggleActive={() => toggleActive(selected)} onDelete={() => openDelete(selected)} />

        <div className="lg:hidden">
            <CrudDetailsModal open={Boolean(selected)} icon={<CalendarIcon />} title="Non-Working Day Details" subtitle={selected ? formatReportDate(selected.date) : ''} onClose={() => setSelected(null)} canEdit={canManage} canDelete={canManage} onEdit={() => openEdit(selected)} onDelete={() => openDelete(selected)} editLabel="Edit" deleteLabel="Delete Non-Working Day" summary={selected && <CrudSummaryGrid items={[{ label: 'Date', value: formatReportDate(selected.date) }, { label: 'Type', value: typeLabels[selected.type] || selected.type }, { label: 'Scope', value: selected.scope }, { label: 'Status', value: selected.is_active ? 'Active' : 'Inactive' }]} />}>
                <CalendarEventContent event={selected} />
            </CrudDetailsModal>
        </div>

        <CalendarEventFormModal open={formOpen} editing={editing} form={form} onClose={resetForm} onSubmit={submit} />

        <CrudFormModal open={taskFormOpen} mode={editingTask ? 'edit' : 'create'} icon={<CalendarIcon />} title={editingTask ? 'Edit Calendar Task' : 'Create Calendar Task'} subtitle="Private to your account - dates and times use Asia/Manila" onClose={resetTaskForm} onSubmit={submitTask} beforeConfirmation={beforeTaskConfirmation} processing={taskForm.processing} errors={taskForm.errors} systemNotice={taskFormNotice ? { title: 'Task not saved', message: taskFormNotice } : null} saveLabel={editingTask ? 'Save Task Changes' : 'Create Task'} confirmation={{ title: editingTask ? 'Save task changes?' : 'Create this calendar task?', message: 'This saves a private Calendar task. It does not change a business-calendar date, report deadline, or workflow status.', confirmLabel: editingTask ? 'Save Task' : 'Create Task', cancelLabel: 'Go Back' }} maxWidth="max-w-2xl">
            <div className="grid gap-4 sm:grid-cols-2">
                <FloatingInput id="calendar-task-title" name="title" label="Task or schedule title" required maxLength={180} value={taskForm.data.title} onChange={event => taskForm.setData('title', event.target.value)} error={taskForm.errors.title} className="sm:col-span-2" />
                <div className="sm:col-span-2">
                    <div role="group" aria-label="Task date span" className="inline-flex rounded-xl bg-slate-100 p-1 dark:bg-gray-800">
                        {['single', 'range'].map(mode => <button key={mode} type="button" aria-pressed={taskDateMode === mode} disabled={mode === 'range' && !taskRangeReady} title={mode === 'range' && !taskRangeReady ? 'Date ranges are unavailable until task storage supports an end date.' : undefined} onClick={() => { setTaskDateMode(mode); if (mode === 'single') taskForm.setData('scheduled_end_date', ''); }} className={'min-h-9 rounded-lg px-4 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 disabled:cursor-not-allowed disabled:opacity-50 ' + (taskDateMode === mode ? 'bg-gradient-to-r from-emerald-600 to-sky-700 text-white shadow-sm' : 'text-slate-600 hover:bg-white dark:text-gray-300 dark:hover:bg-gray-700')}>{mode === 'single' ? 'Single date' : 'Date range'}</button>)}
                    </div>
                    {!taskRangeReady && <p className="mt-1.5 text-[11px] text-gray-500 dark:text-gray-400">Single-date tasks remain available. Date ranges need the scheduled end date storage update.</p>}
                </div>
                {taskDateMode === 'range'
                    ? <DateRangePicker id="calendar-task-date-range" label="Scheduled dates" required value={{ from: taskForm.data.scheduled_date || '', to: taskForm.data.scheduled_end_date || '' }} onChange={range => taskForm.setData({ ...taskForm.data, scheduled_date: range.from, scheduled_end_date: range.to })} error={taskForm.errors.scheduled_end_date} helperText="The selected end date is included for all-day tasks." className="sm:col-span-2" />
                    : <DatePicker id="calendar-task-date" label="Scheduled date" required value={taskForm.data.scheduled_date || ''} onChange={value => taskForm.setData('scheduled_date', value)} error={taskForm.errors.scheduled_date} />}
                <label htmlFor="calendar-task-all-day" className="flex min-h-11 items-center gap-2 rounded-lg border border-gray-200 px-3 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200"><input id="calendar-task-all-day" name="is_all_day" type="checkbox" checked={Boolean(taskForm.data.is_all_day)} onChange={event => taskForm.setData({ ...taskForm.data, is_all_day: event.target.checked, start_time: event.target.checked ? '' : taskForm.data.start_time, end_time: event.target.checked ? '' : taskForm.data.end_time })} className="h-4 w-4 rounded border-gray-300 text-green-700 focus:ring-green-600 dark:border-gray-600 dark:bg-gray-800" />All-day task</label>
                {!taskForm.data.is_all_day && <>
                    <PremiumTimePicker id="calendar-task-start" name="start_time" label="Start time (optional)" value={taskForm.data.start_time ? taskForm.data.scheduled_date + 'T' + taskForm.data.start_time : ''} dateContext={taskForm.data.scheduled_date} onChange={value => taskForm.setData('start_time', clockValue(value))} error={taskForm.errors.start_time} />
                    <PremiumTimePicker id="calendar-task-end" name="end_time" label="End time (optional)" value={taskForm.data.end_time ? (taskDateMode === 'range' ? taskForm.data.scheduled_end_date : taskForm.data.scheduled_date) + 'T' + taskForm.data.end_time : ''} dateContext={taskDateMode === 'range' ? taskForm.data.scheduled_end_date : taskForm.data.scheduled_date} onChange={value => taskForm.setData('end_time', clockValue(value))} error={taskForm.errors.end_time} />
                </>}
                <FloatingTextarea id="calendar-task-notes" name="notes" label="Notes (optional)" rows={4} maxLength={5000} value={taskForm.data.notes} onChange={event => taskForm.setData('notes', event.target.value)} error={taskForm.errors.notes} className="sm:col-span-2" />
            </div>
        </CrudFormModal>

        <CrudDetailsModal open={Boolean(selectedTask)} icon={<CalendarIcon />} title="Calendar Task" subtitle={selectedTask ? formatReportDate(selectedTask.scheduled_date) + (selectedTask.scheduled_end_date ? ' to ' + formatReportDate(selectedTask.scheduled_end_date) : '') : ''} onClose={() => setSelectedTask(null)} footerActions={selectedTask?.status === 'scheduled' && <><Button type="button" size="compact" variant="primary" onClick={() => openEditTask(selectedTask)}>Edit Task</Button><Button type="button" size="compact" variant="primary" onClick={() => openTaskStatusConfirmation('done')}>Mark Done</Button><Button type="button" size="compact" variant="warning" onClick={() => openTaskStatusConfirmation('cancelled')}>Cancel Task</Button></>} summary={selectedTask && <CrudSummaryGrid items={[{ label: 'Date', value: selectedTask.scheduled_end_date ? formatReportDate(selectedTask.scheduled_date) + ' to ' + formatReportDate(selectedTask.scheduled_end_date) : formatReportDate(selectedTask.scheduled_date) }, { label: 'Time', value: selectedTask.is_all_day ? 'All day' : [selectedTask.start_time, selectedTask.end_time].filter(Boolean).join(' - ') || 'Not specified' }, { label: 'Status', value: taskStatusLabel(selectedTask.status) }]} />}>
            <div className="space-y-5">
                <div><h3 className="break-words text-base font-bold text-gray-900 dark:text-white">{selectedTask?.title}</h3><p className="mt-2 whitespace-pre-wrap text-sm leading-5 text-gray-700 dark:text-gray-200">{selectedTask?.notes || 'No notes.'}</p></div>
                <section aria-label="Task creator context" className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-gray-700 dark:bg-gray-800/50">
                    <h3 className="text-xs font-bold text-gray-900 dark:text-white">Created by</h3>
                    <p className="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-100">{selectedTask?.creator_attribution?.actor_name || 'Creator details not recorded'}</p>
                    <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{selectedTask?.creator_attribution?.label || 'Creator details not recorded'} - {selectedTask?.creator_attribution?.office_name || 'Office not assigned'}</p>
                    {selectedTask?.creator_attribution?.unit && <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{selectedTask.creator_attribution.unit} unit</p>}
                    {selectedTask?.creator_attribution?.protected_area_name && <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{selectedTask.creator_attribution.protected_area_name}</p>}
                </section>
                <section aria-label="Task status action" className="rounded-xl border border-slate-200 p-4 dark:border-gray-700">
                    <h3 className="text-xs font-bold text-gray-900 dark:text-white">Status action</h3>
                    <p className="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-100">{selectedTask?.status_attribution?.label || (selectedTask?.status === 'scheduled' ? 'No status action yet' : 'Action details not recorded')}</p>
                    {selectedTask?.status_attribution?.actor_name && <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{selectedTask.status_attribution.actor_name} - {selectedTask.status_attribution.office_name || 'Office not assigned'}</p>}
                    {selectedTask?.status_attribution?.timestamp && <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{attributionTimestamp(selectedTask.status_attribution.timestamp)}</p>}
                </section>
            </div>
        </CrudDetailsModal>

        <ConfirmDialog open={Boolean(taskStatusAction)} title={taskStatusAction?.status === 'done' ? 'Mark this task done?' : 'Cancel this task?'} message={<><span>{taskStatusAction?.status === 'done' ? `Mark “${taskStatusAction?.task?.title}” as done?` : `Cancel “${taskStatusAction?.task?.title}”?`}</span><span className="mt-1 block">This only updates your Calendar task status.</span>{taskStatusError && <span role="alert" className="mt-3 block rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-left text-xs leading-5 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{taskStatusError}</span>}</>} confirmLabel={taskStatusAction?.status === 'done' ? 'Mark Done' : 'Cancel Task'} cancelLabel="Go Back" processing={taskStatusProcessing} onConfirm={submitTaskStatus} onCancel={() => { if (!taskStatusProcessing) { setTaskStatusAction(null); setTaskStatusError(''); } }} />

        <ConfirmDialog open={Boolean(deleteTarget)} title="Delete non-working day?" message={<><span>Delete {deleteTarget?.name || 'this configured non-working day'}? This removes the event from the business calendar.</span>{deleteError && <span role="alert" className="mt-3 block rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-left text-xs leading-5 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{deleteError}</span>}</>} confirmLabel="Delete" variant="danger" processing={form.processing} onConfirm={remove} onCancel={() => { if (!form.processing) { setDeleteTarget(null); setDeleteError(''); } }} />
        <ConfirmDialog open={Boolean(nonWorkingDayStatusAction)} title={`${nonWorkingDayStatusAction?.is_active ? 'Deactivate' : 'Activate'} this non-working day?`} message={<><span>This changes the date's business-calendar status and may affect deadline calculations.</span>{nonWorkingDayStatusError && <span role="alert" className="mt-3 block rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-left text-xs leading-5 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">{nonWorkingDayStatusError}</span>}</>} confirmLabel={nonWorkingDayStatusAction?.is_active ? 'Deactivate' : 'Activate'} cancelLabel="Go Back" processing={nonWorkingDayStatusProcessing} onConfirm={submitNonWorkingDayStatus} onCancel={() => { if (!nonWorkingDayStatusProcessing) { setNonWorkingDayStatusAction(null); setNonWorkingDayStatusError(''); } }} />
    </AuthenticatedLayout>;
}

function MovDetailsModal({ event: eventProp, onClose }) {
    const lastEventRef = useRef(null);
    const overlayRef = useRef(null);
    const panelRef = useRef(null);
    if (eventProp) lastEventRef.current = eventProp;
    const event = eventProp || lastEventRef.current;
    const { present, phase, onTransitionEnd } = useModalMotion(Boolean(eventProp), { onClose, panelRef, layerRef: overlayRef });
    if (!present || !event) return null;
    const rows = [['Module / Workflow', event.module], ['Protected Area', event.protected_area_name], ['Office', event.office], ['Reporting Period', event.reporting_period], ['Date Accomplished', event.date_accomplished ? formatReportDate(event.date_accomplished) : null], ['Date Submitted', event.submission_date ? formatReportDate(event.submission_date) : null], ['Timeliness', event.timeliness], ['Status', event.status]].filter(([, value]) => value);
    if (event.is_historical) {
        const ranges = (event.activity_ranges || []).map(range => range.to && range.to !== range.from
            ? `${formatReportDate(range.from)} – ${formatReportDate(range.to)}`
            : formatReportDate(range.from)).join(', ');
        const historicalRows = [
            ['Source record', `${event.original_source} #${event.source_record_id}`],
            ['Calendar date', event.event_date ? formatReportDate(event.event_date) : null],
            ['Date basis', event.calendar_basis],
            ['Activity date range', ranges || null],
            ['Module / Workflow', event.module],
            ['Protected Area', event.protected_area_name],
            ['Office', event.office],
            ['Reporting Period', event.reporting_period],
            ['Date Accomplished', event.date_accomplished ? formatReportDate(event.date_accomplished) : null],
            ['Original CENRO release', event.original_milestones?.date_report_released_cenro ? formatReportDate(event.original_milestones.date_report_released_cenro) : null],
            ['Original PENRO receipt', event.original_milestones?.date_received_penro ? formatReportDate(event.original_milestones.date_received_penro) : null],
            ['Original regional endorsement', event.original_milestones?.date_endorsed_regional ? formatReportDate(event.original_milestones.date_endorsed_regional) : null],
            ['Timeliness', event.timeliness],
            ['Original status', event.status],
        ].filter(([, value]) => value);
        return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-[1px]" role="presentation"><section ref={panelRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} role="dialog" aria-modal="true" aria-label="Historical report details" data-motion-state={phase} className="cds-modal-motion-panel flex max-h-[calc(100vh-2rem)] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"><header className="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div className="min-w-0"><p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-amber-800 dark:text-amber-300">Historical report evidence</p><h2 className="mt-1 break-words text-base font-bold text-gray-900 dark:text-white">{event.title}</h2><p className="mt-0.5 break-words text-xs text-gray-500 dark:text-gray-400">{event.source_name || event.office || event.module}</p></div><span className="inline-flex shrink-0 items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-[9px] font-extrabold uppercase tracking-wide text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">Historical</span></header><div className="min-h-0 flex-1 overflow-y-auto p-5"><p className="mb-4 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">Read-only historical evidence. Calendar placement uses the recorded original date basis; this entry does not represent current routing.</p><div className="grid gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">{historicalRows.map(([label, value]) => <DetailRow key={label} label={label} value={value} />)}</div>{event.remarks && <div className="mt-5"><p className="text-xs font-bold text-gray-500 dark:text-gray-400">Remarks</p><p className="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{event.remarks}</p></div>}{event.attachment?.exists && <div className="mt-5 rounded-xl bg-amber-50 p-4 dark:bg-amber-950/25"><p className="text-[10px] font-extrabold uppercase tracking-[0.14em] text-amber-900 dark:text-amber-300">Protected attachment</p><p className="mt-1 break-words text-xs text-gray-600 dark:text-gray-300">{event.attachment.name || 'Attachment available in protected historical details'}</p></div>}</div><footer className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-5 py-4 dark:border-gray-800"><a href={event.detail_url} className="rounded-lg bg-amber-700 px-3.5 py-2 text-xs font-bold text-white hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">Open protected Historical Detail</a><button type="button" onClick={onClose} className="rounded-lg border border-gray-200 px-3.5 py-2 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant="primary">Close</button></footer></section></div>;
    }
    return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-[1px]" role="presentation"><section ref={panelRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} role="dialog" aria-modal="true" aria-label="Submitted report details" data-motion-state={phase} className="cds-modal-motion-panel flex max-h-[calc(100vh-2rem)] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900"><header className="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div className="min-w-0"><p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-green-700 dark:text-green-300">Submitted MOV</p><h2 className="mt-1 truncate text-base font-bold text-gray-900 dark:text-white">{event.title}</h2><p className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{event.source_name || event.office || event.module}</p></div></header><div className="min-h-0 flex-1 overflow-y-auto p-5"><div className="grid gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">{rows.map(([label, value]) => <DetailRow key={label} label={label} value={value} />)}</div>{event.remarks && <div className="mt-5"><p className="text-xs font-bold text-gray-500 dark:text-gray-400">Remarks</p><p className="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{event.remarks}</p></div>}{event.attachment?.exists && <div className="mt-5 rounded-xl bg-green-50 p-4 dark:bg-green-950/25"><p className="text-[10px] font-extrabold uppercase tracking-[0.14em] text-green-800 dark:text-green-300">MOV / Attachment</p><p className="mt-1 truncate text-xs text-gray-600 dark:text-gray-300">{event.attachment.name || 'Attached MOV'}</p></div>}</div><footer className="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-5 py-4 dark:border-gray-800">{event.attachment?.exists && event.attachment.url && <a href={event.attachment.url} target="_blank" rel="noreferrer" className="rounded-lg border border-green-700 px-3.5 py-2 text-xs font-bold text-green-800 hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-green-300 dark:hover:bg-green-950/30">View MOV</a>}{event.detail_url && <a href={event.detail_url} className="rounded-lg bg-green-700 px-3.5 py-2 text-xs font-bold text-white hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600">Open Full Report</a>}<button type="button" onClick={onClose} className="rounded-lg border border-gray-200 px-3.5 py-2 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant="primary">Close</button></footer></section></div>;
}

function CalendarEventDrawer({ event: eventProp, canManage, processing, onClose, onEdit, onToggleActive, onDelete }) {
    const lastEventRef = useRef(null);
    const overlayRef = useRef(null);
    const panelRef = useRef(null);
    if (eventProp) lastEventRef.current = eventProp;
    const event = eventProp || lastEventRef.current;
    const { present, phase, onTransitionEnd } = useModalMotion(Boolean(eventProp), { onClose, panelRef, layerRef: overlayRef });
    if (!present || !event) return null;
    return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className="cds-modal-motion-backdrop fixed inset-0 z-40 hidden lg:block" role="presentation">
        <button type="button" className="absolute inset-0 h-full w-full bg-gray-950/25 backdrop-blur-[1px]" onClick={onClose} aria-label="Close event details" />
        <aside ref={panelRef} onTransitionEnd={onTransitionEnd} tabIndex={-1} data-motion-state={phase} className="cds-modal-motion-panel absolute inset-y-0 right-0 z-10 flex w-full max-w-sm flex-col border-l border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900" role="dialog" aria-modal="true" aria-label="Non-working day details">
            <header className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800"><div className="flex min-w-0 items-center gap-3"><span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300"><CalendarIcon /></span><div className="min-w-0"><h2 className="truncate text-sm font-bold text-gray-900 dark:text-white">Event Details</h2><p className="truncate text-xs text-gray-500 dark:text-gray-400">{formatReportDate(event.date)}</p></div></div></header>
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5"><div><p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-gray-400 dark:text-gray-500">{typeLabels[event.type] || event.type || 'Other'}</p><h3 className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{event.name}</h3></div><div className="grid gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800"><DetailRow label="Date" value={formatReportDate(event.date)} /><DetailRow label="Type" value={typeLabels[event.type] || event.type} /><DetailRow label="Status" value={event.is_active ? 'Active' : 'Inactive'} /><DetailRow label="Scope" value={event.scope} />{event.location && <DetailRow label="Location" value={event.location} />}</div><div className="space-y-3 text-sm"><DetailRow label="Reference" value={event.reference} /><div><p className="text-xs font-bold text-gray-500 dark:text-gray-400">Remarks</p><p className="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-200">{event.remarks || 'Not provided'}</p></div></div></div>
            <footer className="flex flex-wrap items-center gap-2 border-t border-gray-100 p-4 dark:border-gray-800">{canManage && <><button type="button" onClick={onEdit} className="cds-compact-action rounded-lg border border-green-200 bg-green-50 text-xs font-bold text-green-700 hover:bg-green-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300" data-cds-action="true" data-cds-action-variant="primary">Edit</button><button type="button" onClick={onToggleActive} disabled={processing} className="cds-compact-action rounded-lg border border-gray-200 text-xs font-bold text-gray-700 hover:bg-gray-50 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant={event.is_active ? 'warning' : 'primary'}>{event.is_active ? 'Deactivate' : 'Activate'}</button><button type="button" onClick={onDelete} className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" data-cds-action="true" data-cds-action-variant="danger">Delete</button></>}<button type="button" onClick={onClose} className="ml-auto rounded-lg bg-green-700 px-3.5 py-2 text-xs font-bold text-white hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600" data-cds-action="true" data-cds-action-variant="primary">Close</button></footer>
        </aside>
    </div>;
}

function CalendarEventContent({ event }) {
    return <div className="space-y-2 text-sm"><p><span className="font-bold">Name / Description:</span> {event?.name || 'Not provided'}</p><p><span className="font-bold">Scope:</span> {event?.scope || 'Not recorded'}{event?.location ? ` — ${event.location}` : ''}</p><p><span className="font-bold">Reference:</span> {event?.reference || 'Not provided'}</p><p><span className="font-bold">Remarks:</span> {event?.remarks || 'Not provided'}</p></div>;
}

function DetailRow({ label, value }) {
    return <div className="flex items-start justify-between gap-4"><span className="text-xs text-gray-500 dark:text-gray-400">{label}</span><span className="text-right text-xs font-semibold text-gray-800 dark:text-gray-100">{value === null || value === undefined || value === '' ? 'Not recorded' : value}</span></div>;
}
