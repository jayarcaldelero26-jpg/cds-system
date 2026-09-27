import { useId, useMemo, useRef, useState } from 'react';

/** Searchable, keyboard-operable selector whose value is always a server option key. */
export default function ScopedOptionSelect({ id, label, value = '', options = [], onChange, required = false, error, placeholder = 'Select an option', disabled = false, readOnly = false }) {
    const generated = useId();
    const fieldId = id || `scoped-select-${generated.replace(/:/g, '')}`;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const inputRef = useRef(null);
    const selected = options.find(option => String(option.id) === String(value));
    const filtered = useMemo(() => options.filter(option => String(option.label || option.name).toLowerCase().includes(query.toLowerCase())), [options, query]);
    const choose = option => { onChange?.(String(option.id)); setQuery(''); setOpen(false); };
    const keyDown = event => {
        if (disabled || readOnly) return;
        if (event.key === 'Escape') { setOpen(false); setQuery(''); return; }
        if (event.key === 'ArrowDown') { event.preventDefault(); if (!open) setOpen(true); else setActive(index => Math.min(index + 1, filtered.length - 1)); return; }
        if (event.key === 'ArrowUp') { event.preventDefault(); setActive(index => Math.max(index - 1, 0)); return; }
        if (event.key === 'Enter' && open && filtered[active]) { event.preventDefault(); choose(filtered[active]); }
    };
    return <div className="relative min-w-0">
        <label htmlFor={fieldId} className="mb-1.5 block text-xs font-semibold leading-4 text-gray-700 dark:text-gray-200">{label}{required && <span className="ml-0.5 text-red-600 dark:text-red-400" aria-hidden="true">*</span>}</label>
        <input id={fieldId} ref={inputRef} role="combobox" aria-autocomplete="list" aria-expanded={open} aria-controls={`${fieldId}-options`} aria-required={required || undefined} aria-invalid={Boolean(error) || undefined} aria-describedby={error ? `${fieldId}-error` : undefined}
            className="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-500 focus:border-green-700 focus:outline-none focus:ring-1 focus:ring-green-700/20 disabled:bg-gray-100 disabled:text-gray-600 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-400 dark:disabled:bg-gray-800 dark:disabled:text-gray-300"
            value={open ? query : (selected?.label || selected?.name || '')} placeholder={selected ? '' : placeholder} disabled={disabled} readOnly={readOnly} onKeyDown={keyDown}
            onFocus={() => { if (!disabled && !readOnly) { setOpen(true); setQuery(''); } }} onChange={event => { setQuery(event.target.value); setOpen(true); setActive(0); }} onBlur={event => { if (!event.currentTarget.parentElement?.contains(event.relatedTarget)) { setOpen(false); setQuery(''); } }} />
        {!disabled && !readOnly && open && <div id={`${fieldId}-options`} role="listbox" className="absolute z-50 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-gray-300 bg-white p-1 shadow-lg dark:border-gray-600 dark:bg-gray-900">
            {filtered.length ? filtered.map((option, index) => <button key={option.id} type="button" role="option" aria-selected={String(option.id) === String(value)} className={`block w-full rounded px-3 py-2 text-left text-sm ${index === active ? 'bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-100' : 'text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-800'}`} onMouseEnter={() => setActive(index)} onMouseDown={event => event.preventDefault()} onClick={() => choose(option)}>{option.label || option.name}</button>) : <p className="px-3 py-2 text-sm text-gray-600 dark:text-gray-300">No matching options</p>}
        </div>}
        {error && <p id={`${fieldId}-error`} role="alert" className="mt-1 text-sm text-red-700 dark:text-red-300">{error}</p>}
    </div>;
}
