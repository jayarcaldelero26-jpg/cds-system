import { useEffect, useMemo, useRef, useState } from 'react';
import { filterSelectionSummary, normalizeFilterValues, toggleFilterValue } from './filterSelection.mjs';

export function MultiSelectOption({ option, checked, onToggle }) {
    return <div role="option" aria-selected={checked} tabIndex={0} onClick={onToggle} onKeyDown={event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); onToggle?.(); }
    }} className="cds-filter-option flex w-full items-center gap-2 px-2.5 py-2 text-left text-sm">
        <input type="checkbox" checked={checked} readOnly tabIndex={-1} aria-hidden="true" className="pointer-events-none h-4 w-4 rounded border-gray-300 text-green-700 focus:ring-green-600 dark:border-gray-600 dark:bg-gray-800" />
        <span className="min-w-0 truncate">{option.label}</span>
    </div>;
}

/** Shared searchable multi-select for filters whose existing value is an array. */
export default function MultiSelectFilter({ id, label, options = [], value = [], onChange, placeholder = 'Select', disabled = false }) {
    const root = useRef(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const selected = normalizeFilterValues(value);
    const filtered = useMemo(() => options.filter(option => String(option.label).toLowerCase().includes(query.toLowerCase())), [options, query]);
    const summary = filterSelectionSummary(options, selected, placeholder);

    useEffect(() => {
        if (!open) return undefined;
        const closeOutside = event => { if (!root.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('pointerdown', closeOutside);
        return () => document.removeEventListener('pointerdown', closeOutside);
    }, [open]);

    const toggle = option => {
        onChange?.(toggleFilterValue(selected, option.value));
    };

    return <div ref={root} className="relative min-w-0" data-cds-multi-filter>
        <label htmlFor={`${id}-trigger`} className="mb-1.5 block text-xs font-semibold leading-4 text-gray-700 dark:text-gray-200">{label}</label>
        <button id={`${id}-trigger`} type="button" aria-haspopup="listbox" aria-expanded={open} aria-controls={`${id}-options`} disabled={disabled} onClick={() => setOpen(current => !current)} onKeyDown={event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); setOpen(true); }
            if (event.key === 'Escape') setOpen(false);
        }} className="cds-filter-trigger flex h-11 w-full items-center justify-between gap-3 rounded-lg border border-gray-300 bg-white px-3 text-left text-sm text-gray-900 outline-none transition hover:border-green-600 focus-visible:border-green-700 focus-visible:ring-2 focus-visible:ring-green-700/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <span className="min-w-0 truncate">{summary}</span><span aria-hidden="true" className="text-gray-500">{open ? '⌃' : '⌄'}</span>
        </button>
        {open && <div id={`${id}-options`} role="listbox" aria-label={`${label} options`} aria-multiselectable="true" className="cds-filter-dropdown absolute z-50 mt-1 w-full p-1">
            <input type="search" aria-label={`Search ${label}`} value={query} onChange={event => setQuery(event.target.value)} placeholder={`Search ${label}`} className="mb-1 h-9 w-full rounded-md border border-gray-200 bg-white px-2.5 text-sm text-gray-900 outline-none focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
            <div className="max-h-56 overflow-y-auto">
                {filtered.map(option => {
                    const checked = selected.includes(String(option.value));
                    return <MultiSelectOption key={option.value} option={option} checked={checked} onToggle={() => toggle(option)} />;
                })}
                {filtered.length === 0 && <p className="px-3 py-2 text-sm text-gray-600 dark:text-gray-300">No matching options</p>}
            </div>
            <div className="mt-1 flex justify-between border-t border-gray-100 px-2 pt-1.5 text-xs dark:border-gray-700"><span className="text-gray-500 dark:text-gray-400">{selected.length} selected</span><button type="button" disabled={selected.length === 0} onClick={() => onChange?.([])} className="font-semibold text-green-800 hover:underline disabled:opacity-50 dark:text-green-300">Clear</button></div>
        </div>}
    </div>;
}
