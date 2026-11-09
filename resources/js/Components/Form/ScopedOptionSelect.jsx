import { useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import FloatingField from './FloatingField';

export function ScopedSelectOption({ option, selected, active, onChoose, onActivate }) {
    return <div role="option" aria-selected={selected} aria-disabled={option.disabled || undefined} className={`cds-filter-option block w-full px-3 py-2 text-left text-sm ${active ? 'bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-100' : ''}`} onMouseEnter={onActivate} onMouseDown={event => event.preventDefault()} onClick={onChoose}>{option.label || option.name}</div>;
}

/** Searchable, keyboard-operable selector whose value is always a server option key. */
export default function ScopedOptionSelect({ id, label, value = '', options = [], onChange, required = false, error, placeholder = 'Select an option', disabled = false, readOnly = false }) {
    const generated = useId();
    const fieldId = id || `scoped-select-${generated.replace(/:/g, '')}`;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [menuStyle, setMenuStyle] = useState(null);
    const inputRef = useRef(null);
    const menuRef = useRef(null);
    const selected = options.find(option => String(option.id) === String(value));
    const filtered = useMemo(() => options.filter(option => String(option.label || option.name).toLowerCase().includes(query.toLowerCase())), [options, query]);
    const choose = option => { onChange?.(String(option.id)); setQuery(''); setOpen(false); };
    useLayoutEffect(() => {
        if (!open) return undefined;
        const position = () => {
            const rect = inputRef.current?.getBoundingClientRect();
            if (!rect) return;
            const below = window.innerHeight - rect.bottom - 8;
            const above = rect.top - 8;
            const up = below < 176 && above > below;
            setMenuStyle({ position: 'fixed', zIndex: 1200, left: Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8)), width: Math.min(rect.width, window.innerWidth - 16), maxHeight: Math.max(112, Math.min(224, up ? above : below)), ...(up ? { bottom: window.innerHeight - rect.top + 4 } : { top: rect.bottom + 4 }) });
        };
        const outside = event => { if (!inputRef.current?.contains(event.target) && !menuRef.current?.contains(event.target)) { setOpen(false); setQuery(''); } };
        position();
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);
        document.addEventListener('pointerdown', outside, true);
        return () => { window.removeEventListener('resize', position); window.removeEventListener('scroll', position, true); document.removeEventListener('pointerdown', outside, true); };
    }, [open]);
    const keyDown = event => {
        if (disabled || readOnly) return;
        if (event.key === 'Escape') {
            if (!open) return;
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
            setQuery('');
            return;
        }
        if (event.key === 'ArrowDown') { event.preventDefault(); if (!open) setOpen(true); else setActive(index => Math.min(index + 1, filtered.length - 1)); return; }
        if (event.key === 'ArrowUp') { event.preventDefault(); setActive(index => Math.max(index - 1, 0)); return; }
        if (event.key === 'Enter' && open && filtered[active]) { event.preventDefault(); choose(filtered[active]); }
    };
    const selectedLabel = selected?.label || selected?.name || '';
    return <FloatingField id={fieldId} label={label} value={selectedLabel} error={error} required={required} disabled={disabled} readOnly={readOnly} active={open} inputRef={inputRef}>
        {({ nativeProps, ref, sizeClasses }) => <>
        <input {...nativeProps} id={fieldId} ref={ref} type="text" role="combobox" aria-autocomplete="list" aria-expanded={open} aria-controls={`${fieldId}-options`} aria-describedby={nativeProps['aria-describedby']}
            className={`block w-full rounded-lg border-0 bg-transparent px-3 pt-[1.05rem] pb-1 text-sm text-gray-900 outline-none focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-700 dark:text-gray-100 ${sizeClasses}`}
            value={open ? query : selectedLabel} placeholder={!selected && open ? placeholder : ''} onKeyDown={keyDown}
            onFocus={() => { if (!disabled && !readOnly) { setOpen(true); setQuery(''); } }} onChange={event => { setQuery(event.target.value); setOpen(true); setActive(0); }} onBlur={event => { if (!event.currentTarget.parentElement?.contains(event.relatedTarget)) { setOpen(false); setQuery(''); } }} />
        {!disabled && !readOnly && open && menuStyle && typeof document !== 'undefined' && createPortal(<div ref={menuRef} id={`${fieldId}-options`} role="listbox" className="cds-filter-dropdown overflow-auto p-1" style={menuStyle}>
            {filtered.length ? filtered.map((option, index) => <ScopedSelectOption key={option.id} option={option} selected={String(option.id) === String(value)} active={index === active} onActivate={() => setActive(index)} onChoose={() => choose(option)} />) : <p className="px-3 py-2 text-sm text-gray-600 dark:text-gray-300">No matching options</p>}
        </div>, document.body)}
        </>}
    </FloatingField>;
}
