import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import CalendarPanel from './Calendar/CalendarPanel';
import CalendarPopover from './Calendar/CalendarPopover';
import FloatingField from './Form/FloatingField';
import { formatDateKey, formatDisplayDate, monthStart, parseDateOnly, philippinesTodayDate, todayDate } from './Calendar/calendarUtils';

export default function DatePicker({ value = '', onChange, label, placeholder = 'Select date', minDate = '', maxDate = '', actualDate = false, disabled = false, error = '', helperText = '', id, required = false, className = '', compactSeptember = false }) {
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
    return <div className={`relative ${className}`}>
        <FloatingField id={id} label={label} hideLabel={!label} value={value} error={error} hint={error ? '' : effectiveHelperText} size="default" disabled={disabled} required={required} active={open}>
            {({ fieldId, nativeProps, showPlaceholder }) => <button ref={triggerRef} id={fieldId} type="button" disabled={disabled} onClick={openPicker} aria-label={!label ? placeholder : undefined} aria-labelledby={nativeProps['aria-labelledby']} aria-required={required || undefined} aria-invalid={nativeProps['aria-invalid']} aria-haspopup="dialog" aria-expanded={open} aria-describedby={nativeProps['aria-describedby']} className="flex h-[2.625rem] w-full items-center justify-between gap-3 rounded-lg border-0 bg-transparent px-3 text-left text-sm text-gray-800 outline-none focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-700 disabled:cursor-not-allowed disabled:text-gray-500 dark:text-gray-100 dark:disabled:text-gray-400">
                <span className={`truncate ${value ? 'text-gray-800 dark:text-gray-100' : showPlaceholder || open ? 'text-gray-500 dark:text-gray-400' : 'text-transparent'}`}>{formatDisplayDate(value, { compactSeptember }) || ((showPlaceholder || open) ? placeholder : '')}</span><Icon icon="solar:calendar-date-linear" width="18" height="18" className="shrink-0 text-gray-400" aria-hidden="true" />
            </button>}
        </FloatingField>
        <CalendarPopover open={open} anchorRef={triggerRef} popoverRef={popoverRef}><CalendarPanel month={viewMonth} onMonthChange={setViewMonth} selectedDate={value} onSelectDate={select} minDate={minDate} maxDate={effectiveMaxDate} todayDateKey={todayKey} /><div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-800"><button type="button" onClick={() => select(todayKey)} disabled={Boolean(minDate && todayKey < formatDateKey(minDate)) || Boolean(effectiveMaxDate && todayKey > formatDateKey(effectiveMaxDate))} className="text-xs font-bold text-green-700 hover:text-green-900 disabled:cursor-not-allowed disabled:opacity-40 dark:text-green-300">Today</button>{value && <button type="button" onClick={() => select('')} className="text-xs font-semibold text-gray-500 hover:text-red-700 dark:text-gray-400">Clear</button>}</div></CalendarPopover>
    </div>;
}
