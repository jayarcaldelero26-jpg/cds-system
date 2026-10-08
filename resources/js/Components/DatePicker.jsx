import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import CalendarPanel from './Calendar/CalendarPanel';
import CalendarPopover from './Calendar/CalendarPopover';
import { formatDateKey, formatDisplayDate, monthStart, parseDateOnly, philippinesTodayDate, todayDate } from './Calendar/calendarUtils';

export default function DatePicker({ value = '', onChange, label, placeholder = 'Select date', minDate = '', maxDate = '', actualDate = false, disabled = false, error = '', helperText = '', id, required = false, className = '' }) {
    const triggerRef = useRef(null);
    const popoverRef = useRef(null);
    const [open, setOpen] = useState(false);
    const today = actualDate ? philippinesTodayDate() : todayDate();
    const todayKey = formatDateKey(today);
    const effectiveMaxDate = actualDate ? todayKey : maxDate;
    const effectiveHelperText = [helperText, actualDate ? 'Choose today or an earlier date (Philippines time).' : ''].filter(Boolean).join(' ');
    const [viewMonth, setViewMonth] = useState(monthStart(parseDateOnly(value) || today));
    const close = () => { setOpen(false); window.requestAnimationFrame(() => triggerRef.current?.focus()); };

    useEffect(() => {
        if (!open) return undefined;
        const handlePointer = (event) => { if (!triggerRef.current?.contains(event.target) && !popoverRef.current?.contains(event.target)) close(); };
        const handleKeyDown = (event) => { if (event.key === 'Escape') { event.preventDefault(); close(); } };
        document.addEventListener('mousedown', handlePointer);
        document.addEventListener('keydown', handleKeyDown);
        return () => { document.removeEventListener('mousedown', handlePointer); document.removeEventListener('keydown', handleKeyDown); };
    }, [open]);

    const openPicker = () => { if (disabled) return; setViewMonth(monthStart(parseDateOnly(value) || today)); setOpen(true); };
    const select = (nextValue) => { onChange?.(formatDateKey(nextValue)); close(); };
    const describedBy = [error ? `${id}-error` : '', effectiveHelperText ? `${id}-help` : ''].filter(Boolean).join(' ') || undefined;

    return <div className={`relative ${className}`}>
        {label && <label htmlFor={id} className="mb-1.5 block text-xs font-semibold text-gray-700 dark:text-gray-300">{label}{required && <span className="ml-0.5 text-red-600 dark:text-red-400" aria-hidden="true">*</span>}</label>}
        <button ref={triggerRef} id={id} type="button" disabled={disabled} onClick={openPicker} aria-required={required || undefined} aria-invalid={Boolean(error) || undefined} aria-haspopup="dialog" aria-expanded={open} aria-describedby={describedBy} className={`flex h-11 w-full items-center justify-between gap-3 rounded-lg border bg-white px-3 text-left text-sm outline-none transition focus:border-green-700 focus:ring-2 focus:ring-green-700/15 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-800 dark:text-gray-100 ${error ? 'border-red-400' : 'border-gray-300 dark:border-gray-700'} ${value ? 'text-gray-800' : 'text-gray-400 dark:text-gray-500'}`}>
            <span className="truncate">{formatDisplayDate(value) || placeholder}</span><Icon icon="solar:calendar-date-linear" width="18" height="18" className="shrink-0 text-gray-400" aria-hidden="true" />
        </button>
        {effectiveHelperText && !error && <p id={`${id}-help`} className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{effectiveHelperText}</p>}
        {error && <p id={`${id}-error`} className="mt-1 text-[11px] text-red-600 dark:text-red-400" role="alert">{error}</p>}
        <CalendarPopover open={open} anchorRef={triggerRef} popoverRef={popoverRef}><CalendarPanel month={viewMonth} onMonthChange={setViewMonth} selectedDate={value} onSelectDate={select} minDate={minDate} maxDate={effectiveMaxDate} todayDateKey={todayKey} /><div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-800"><button type="button" onClick={() => select(todayKey)} disabled={Boolean(minDate && todayKey < formatDateKey(minDate)) || Boolean(effectiveMaxDate && todayKey > formatDateKey(effectiveMaxDate))} className="text-xs font-bold text-green-700 hover:text-green-900 disabled:cursor-not-allowed disabled:opacity-40 dark:text-green-300">Today</button>{value && <button type="button" onClick={() => select('')} className="text-xs font-semibold text-gray-500 hover:text-red-700 dark:text-gray-400">Clear</button>}</div></CalendarPopover>
    </div>;
}
