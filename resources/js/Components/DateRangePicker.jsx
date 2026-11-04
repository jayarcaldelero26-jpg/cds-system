import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import CalendarPanel from './Calendar/CalendarPanel';
import CalendarPopover from './Calendar/CalendarPopover';
import FloatingField from './Form/FloatingField';
import { formatDateKey, formatDisplayDate, monthStart, parseDateOnly, philippinesTodayDate, todayDate } from './Calendar/calendarUtils';

export default function DateRangePicker({ value = {}, onChange, label, placeholder = 'Select date range', minDate = '', maxDate = '', actualDate = false, disabled = false, error = '', helperText = '', id, required = false, className = '', compactSeptember = false }) {
    const triggerRef = useRef(null);
    const popoverRef = useRef(null);
    const applied = { from: formatDateKey(value?.from), to: formatDateKey(value?.to) };
    const [open, setOpen] = useState(false);
    const today = actualDate ? philippinesTodayDate() : todayDate();
    const todayKey = formatDateKey(today);
    const effectiveMaxDate = actualDate ? todayKey : maxDate;
    const effectiveHelperText = [helperText, actualDate ? 'Choose today or an earlier date (Philippines time).' : ''].filter(Boolean).join(' ');
    const [viewMonth, setViewMonth] = useState(monthStart(parseDateOnly(applied.from) || today));
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

    const openPicker = () => { if (disabled) return; setDraft(applied); setViewMonth(monthStart(parseDateOnly(applied.from) || today)); setOpen(true); };
    const selectDate = (nextValue) => {
        const next = formatDateKey(nextValue);
        if (!next) return;
        if (!draft.from || draft.to) return setDraft({ from: next, to: '' });
        setDraft(next < draft.from ? { from: next, to: draft.from } : { from: draft.from, to: next });
    };
    const apply = () => { if (!draft.from || !draft.to) return; onChange?.({ ...draft }); setOpen(false); window.requestAnimationFrame(() => triggerRef.current?.focus()); };
    const clear = () => { onChange?.({ from: '', to: '' }); setDraft({ from: '', to: '' }); setOpen(false); window.requestAnimationFrame(() => triggerRef.current?.focus()); };
    const arrow = String.fromCharCode(0x2192);
    const display = applied.from && applied.to ? [formatDisplayDate(applied.from, { compactSeptember }), formatDisplayDate(applied.to, { compactSeptember })].join(' ' + arrow + ' ') : '';
    const draftDisplay = draft.from ? formatDisplayDate(draft.from, { compactSeptember }) + (draft.to ? ' ' + arrow + ' ' + formatDisplayDate(draft.to, { compactSeptember }) : ' ' + arrow + ' Select end date') : 'Select a start date';
    return <div className={`relative ${className}`}>
        <FloatingField id={id} label={label} hideLabel={!label} value={display} error={error} hint={error ? '' : effectiveHelperText} size="default" disabled={disabled} required={required} active={open}>
            {({ fieldId, nativeProps, showPlaceholder }) => <button ref={triggerRef} id={fieldId} type="button" disabled={disabled} onClick={openPicker} aria-label={!label ? placeholder : undefined} aria-labelledby={nativeProps['aria-labelledby']} aria-required={required || undefined} aria-invalid={nativeProps['aria-invalid']} aria-haspopup="dialog" aria-expanded={open} aria-describedby={nativeProps['aria-describedby']} className="flex h-[2.625rem] w-full items-center justify-between gap-3 rounded-lg border-0 bg-transparent px-3 text-left text-sm text-gray-800 outline-none focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-700 disabled:cursor-not-allowed disabled:text-gray-500 dark:text-gray-100 dark:disabled:text-gray-400"><span className={`truncate ${display ? 'text-gray-800 dark:text-gray-100' : showPlaceholder || open ? 'text-gray-500 dark:text-gray-400' : 'text-transparent'}`}>{display || ((showPlaceholder || open) ? placeholder : '')}</span><Icon icon="solar:calendar-date-linear" width="18" height="18" className="shrink-0 text-gray-400" aria-hidden="true" /></button>}
        </FloatingField>
        <CalendarPopover open={open} anchorRef={triggerRef} popoverRef={popoverRef} wide><CalendarPanel month={viewMonth} onMonthChange={setViewMonth} months={2} rangeStart={draft.from} rangeEnd={draft.to} onSelectDate={selectDate} minDate={minDate} maxDate={effectiveMaxDate} todayDateKey={todayKey} /><div className="border-t border-gray-100 px-4 py-3 dark:border-gray-800"><p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Selected Range</p><p className="mt-1 text-xs font-bold text-gray-900 dark:text-white">{draftDisplay}</p><div className="mt-3 flex items-center justify-between gap-2"><div className="flex gap-3">{(applied.from || applied.to) && <button type="button" onClick={clear} className="text-xs font-semibold text-gray-500 hover:text-red-700 dark:text-gray-400" data-cds-action="true" data-cds-action-variant="primary">Clear</button>}</div><div className="flex gap-2"><button type="button" onClick={close} className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" data-cds-action="true" data-cds-action-variant="cancel">Cancel</button><button type="button" onClick={apply} disabled={!draft.from || !draft.to} className="rounded-lg bg-green-700 px-4 py-2 text-xs font-bold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50" data-cds-action="true" data-cds-action-variant="primary">Apply</button></div></div></div></CalendarPopover>
    </div>;
}
