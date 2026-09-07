import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import CalendarPanel from './Calendar/CalendarPanel';
import CalendarPopover from './Calendar/CalendarPopover';
import { formatDateKey, formatDisplayDate, monthStart, parseDateOnly, todayDate } from './Calendar/calendarUtils';

export default function DateRangePicker({ value = {}, onChange, label, placeholder = 'Select date range', minDate = '', maxDate = '', disabled = false, error = '', helperText = '', id, className = '' }) {
    const triggerRef = useRef(null);
    const popoverRef = useRef(null);
    const applied = { from: formatDateKey(value?.from), to: formatDateKey(value?.to) };
    const [open, setOpen] = useState(false);
    const [viewMonth, setViewMonth] = useState(monthStart(parseDateOnly(applied.from) || todayDate()));
    const [draft, setDraft] = useState(applied);
    const close = () => { setDraft(applied); setOpen(false); window.requestAnimationFrame(() => triggerRef.current?.focus()); };

    useEffect(() => {
        if (!open) return undefined;
        const handlePointer = (event) => { if (!triggerRef.current?.contains(event.target) && !popoverRef.current?.contains(event.target)) close(); };
        const handleKeyDown = (event) => { if (event.key === 'Escape') { event.preventDefault(); close(); } };
        document.addEventListener('mousedown', handlePointer);
        document.addEventListener('keydown', handleKeyDown);
        return () => { document.removeEventListener('mousedown', handlePointer); document.removeEventListener('keydown', handleKeyDown); };
    }, [open, applied.from, applied.to]);

    const openPicker = () => { if (disabled) return; setDraft(applied); setViewMonth(monthStart(parseDateOnly(applied.from) || todayDate())); setOpen(true); };
    const selectDate = (nextValue) => {
        const next = formatDateKey(nextValue);
        if (!next) return;
        if (!draft.from || draft.to) return setDraft({ from: next, to: '' });
        setDraft(next < draft.from ? { from: next, to: draft.from } : { from: draft.from, to: next });
    };
    const apply = () => { if (!draft.from || !draft.to) return; onChange?.({ ...draft }); setOpen(false); window.requestAnimationFrame(() => triggerRef.current?.focus()); };
    const clear = () => { onChange?.({ from: '', to: '' }); setDraft({ from: '', to: '' }); setOpen(false); window.requestAnimationFrame(() => triggerRef.current?.focus()); };
    const arrow = String.fromCharCode(0x2192);
    const display = applied.from && applied.to ? [formatDisplayDate(applied.from), formatDisplayDate(applied.to)].join(' ' + arrow + ' ') : '';
    const draftDisplay = draft.from ? formatDisplayDate(draft.from) + (draft.to ? ' ' + arrow + ' ' + formatDisplayDate(draft.to) : ' ' + arrow + ' Select end date') : 'Select a start date';
    const describedBy = [error ? id + '-error' : '', helperText ? id + '-help' : ''].filter(Boolean).join(' ') || undefined;

    return <div className={`relative ${className}`}>
        {label && <label htmlFor={id} className="mb-1.5 block text-xs font-semibold text-gray-700 dark:text-gray-300">{label}</label>}
        <button ref={triggerRef} id={id} type="button" disabled={disabled} onClick={openPicker} aria-haspopup="dialog" aria-expanded={open} aria-describedby={describedBy} className={`flex h-11 w-full items-center justify-between gap-3 rounded-lg border bg-white px-3 text-left text-sm outline-none transition focus:border-green-700 focus:ring-2 focus:ring-green-700/15 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-800 dark:text-gray-100 ${error ? 'border-red-400' : 'border-gray-300 dark:border-gray-700'} ${display ? 'text-gray-800' : 'text-gray-400 dark:text-gray-500'}`}><span className="truncate">{display || placeholder}</span><Icon icon="solar:calendar-date-linear" width="18" height="18" className="shrink-0 text-gray-400" aria-hidden="true" /></button>
        {helperText && !error && <p id={`${id}-help`} className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{helperText}</p>}
        {error && <p id={`${id}-error`} className="mt-1 text-[11px] text-red-600 dark:text-red-400" role="alert">{error}</p>}
        <CalendarPopover open={open} anchorRef={triggerRef} popoverRef={popoverRef} wide><CalendarPanel month={viewMonth} onMonthChange={setViewMonth} months={2} rangeStart={draft.from} rangeEnd={draft.to} onSelectDate={selectDate} minDate={minDate} maxDate={maxDate} /><div className="border-t border-gray-100 px-4 py-3 dark:border-gray-800"><p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Selected Range</p><p className="mt-1 text-xs font-bold text-gray-900 dark:text-white">{draftDisplay}</p><div className="mt-3 flex items-center justify-between gap-2"><div className="flex gap-3">{(applied.from || applied.to) && <button type="button" onClick={clear} className="text-xs font-semibold text-gray-500 hover:text-red-700 dark:text-gray-400">Clear</button>}</div><div className="flex gap-2"><button type="button" onClick={close} className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</button><button type="button" onClick={apply} disabled={!draft.from || !draft.to} className="rounded-lg bg-green-700 px-4 py-2 text-xs font-bold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50">Apply</button></div></div></div></CalendarPopover>
    </div>;
}
