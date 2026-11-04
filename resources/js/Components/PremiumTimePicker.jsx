import { useEffect, useRef, useState } from 'react';
import { HOURS, MINUTES, PERIODS, currentDraft, formatTimeDisplay, initialDraft, serializeTimeValue } from '@/Utils/timePicker';
import FloatingField from './Form/FloatingField';

const ROW_HEIGHT = 42;

function ClockIcon() {
    return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-4 w-4"><circle cx="12" cy="12" r="8.5" /><path strokeLinecap="round" d="M12 7.5v4.9l3.2 1.9" /></svg>;
}

export default function PremiumTimePicker({ id, label = 'Time', value = '', dateContext = '', onChange, error, name, disabled = false }) {
    const rootRef = useRef(null);
    const triggerRef = useRef(null);
    const hourRef = useRef(null);
    const minuteRef = useRef(null);
    const periodRef = useRef(null);
    const snapTimers = useRef({});
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState('time');
    const [draft, setDraft] = useState(() => initialDraft(value, new Date(), dateContext));

    const scrollToIndex = (ref, index, behavior = 'smooth') => ref.current?.scrollTo({ top: index * ROW_HEIGHT, behavior });

    useEffect(() => {
        if (!open) return;
        const next = initialDraft(value, new Date(), dateContext);
        setDraft(next);
        requestAnimationFrame(() => {
            scrollToIndex(hourRef, next.hour - 1, 'auto');
            scrollToIndex(minuteRef, next.minute, 'auto');
            scrollToIndex(periodRef, next.period === 'PM' ? 1 : 0, 'auto');
        });
    }, [open, dateContext, value]);

    const close = () => {
        setOpen(false);
        window.requestAnimationFrame(() => triggerRef.current?.focus());
    };

    useEffect(() => {
        if (!open) return undefined;
        const onPointerDown = event => { if (!rootRef.current?.contains(event.target)) close(); };
        const onKeyDown = event => { if (event.key === 'Escape') { event.preventDefault(); close(); } };
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        return () => { document.removeEventListener('pointerdown', onPointerDown); document.removeEventListener('keydown', onKeyDown); };
    }, [open]);

    useEffect(() => () => Object.values(snapTimers.current).forEach(timer => window.clearTimeout(timer)), []);

    const updateWheel = (kind, index, ref, max) => {
        const bounded = Math.max(0, Math.min(max, index));
        setDraft(previous => ({ ...previous, ...(kind === 'hour' ? { hour: bounded + 1 } : {}), ...(kind === 'minute' ? { minute: bounded } : {}), ...(kind === 'period' ? { period: PERIODS[bounded] } : {}) }));
        window.clearTimeout(snapTimers.current[kind]);
        snapTimers.current[kind] = window.setTimeout(() => scrollToIndex(ref, bounded), 120);
    };

    const handleScroll = (kind, event, max) => {
        const ref = kind === 'hour' ? hourRef : kind === 'minute' ? minuteRef : periodRef;
        updateWheel(kind, Math.round(event.currentTarget.scrollTop / ROW_HEIGHT), ref, max);
    };

    const handleWheelKey = (event, kind, current, max, ref) => {
        if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? max : current + (event.key === 'ArrowDown' ? 1 : -1);
        updateWheel(kind, next, ref, max);
        scrollToIndex(ref, Math.max(0, Math.min(max, next)));
    };

    const commit = () => { onChange?.(serializeTimeValue(draft)); close(); };
    const chooseNow = () => {
        setMode('now');
        const next = currentDraft(value, new Date(), dateContext);
        setDraft(next);
        requestAnimationFrame(() => { scrollToIndex(hourRef, next.hour - 1); scrollToIndex(minuteRef, next.minute); scrollToIndex(periodRef, next.period === 'PM' ? 1 : 0); });
    };

    const renderWheel = (kind, items, selected, ref, ariaLabel, formatter = item => item) => <div className="premium-time-wheel-wrap">
        <div ref={ref} role="listbox" tabIndex={0} aria-label={ariaLabel} aria-activedescendant={`${id}-${kind}-${items[selected]}`} className="premium-time-wheel" onScroll={event => handleScroll(kind, event, items.length - 1)} onKeyDown={event => handleWheelKey(event, kind, selected, items.length - 1, ref)}>
            {items.map((item, index) => {
                const active = index === selected;
                const distance = Math.abs(index - selected);
                return <button type="button" role="option" aria-selected={active} id={`${id}-${kind}-${item}`} key={item} onClick={() => { updateWheel(kind, index, ref, items.length - 1); scrollToIndex(ref, index); }} className={`premium-time-wheel-item ${active ? 'is-active' : distance === 1 ? 'is-near' : 'is-faded'}`}>{formatter(item)}</button>;
            })}
        </div>
    </div>;

    return <div ref={rootRef} className="relative w-full">
        <FloatingField id={`${id}-trigger`} label={label} value={value || ''} error={error} disabled={disabled} active={open}>
            {({ fieldId, nativeProps, showPlaceholder }) => <button ref={triggerRef} id={fieldId} type="button" disabled={disabled} aria-haspopup="dialog" aria-expanded={open} aria-labelledby={nativeProps['aria-labelledby']} aria-invalid={nativeProps['aria-invalid']} aria-describedby={nativeProps['aria-describedby']} onClick={() => setOpen(true)} onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); setOpen(true); } }} className="premium-time-trigger">
                <span className="text-emerald-700 dark:text-emerald-400"><ClockIcon /></span>
                <span className={value ? 'premium-time-value' : showPlaceholder || open ? 'premium-time-placeholder' : 'text-transparent'}>{value ? formatTimeDisplay(value) : (showPlaceholder || open ? 'Select time' : '')}</span>
            </button>}
        </FloatingField>
        <input type="hidden" id={id} name={name} value={value || ''} readOnly data-premium-time-picker-input="true" aria-hidden="true" />
        {open && <div role="dialog" aria-label={`${label} picker`} className="premium-time-popover">
            <div className="premium-time-tabs" role="tablist" aria-label="Time selection mode"><button type="button" role="tab" aria-selected={mode === 'time'} onClick={() => setMode('time')} className={mode === 'time' ? 'is-selected' : ''}>Time</button><button type="button" role="tab" aria-selected={mode === 'now'} onClick={chooseNow} className={mode === 'now' ? 'is-selected' : ''}>Now</button></div>
            <div className="premium-time-wheel-area"><div className="premium-time-wheel-labels"><span>HOUR</span><span>MINUTE</span><span>PERIOD</span></div><div className="premium-time-wheels">
                {renderWheel('hour', HOURS, draft.hour - 1, hourRef, 'Hour wheel', item => String(item).padStart(2, '0'))}
                {renderWheel('minute', MINUTES, draft.minute, minuteRef, 'Minute wheel', item => String(item).padStart(2, '0'))}
                {renderWheel('period', PERIODS, draft.period === 'PM' ? 1 : 0, periodRef, 'AM or PM wheel')}
                <div className="premium-time-selection-band" aria-hidden="true" />
            </div></div>
            <p className="premium-time-current" aria-live="polite">{formatTimeDisplay(draft)}</p>
            <div className="premium-time-actions"><button type="button" onClick={close} className="premium-time-cancel" data-cds-action="true" data-cds-action-variant="cancel">Cancel</button><button type="button" onClick={commit} className="premium-time-done" data-cds-action="true" data-cds-action-variant="primary">Done</button></div>
        </div>}
    </div>;
}
