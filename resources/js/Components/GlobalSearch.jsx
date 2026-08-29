import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '@iconify/react';

const resultIcons = {
    navigation: 'solar:compass-bold', report: 'solar:document-text-bold', protected_area: 'solar:map-point-bold', user: 'solar:user-rounded-bold',
};

export default function GlobalSearch({ onNavigate }) {
    const [query, setQuery] = useState('');
    const [data, setData] = useState({ groups: [], total: 0 });
    const [isOpen, setIsOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(-1);
    const searchRef = useRef(null);
    const inputRef = useRef(null);
    const results = useMemo(() => data.groups.flatMap(group => group.results), [data]);
    const canSearch = query.trim().length >= 2;

    useEffect(() => {
        const close = event => {
            if (searchRef.current && !searchRef.current.contains(event.target)) setIsOpen(false);
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    useEffect(() => {
        if (!canSearch) {
            setData({ groups: [], total: 0 });
            setLoading(false);
            setSelectedIndex(-1);
            return undefined;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(async () => {
            setLoading(true);
            try {
                const response = await fetch(`/api/global-search?q=${encodeURIComponent(query.trim())}`, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal,
                });
                if (!response.ok) throw new Error('Search request failed');
                const next = await response.json();
                setData({ groups: Array.isArray(next.groups) ? next.groups : [], total: Number(next.total) || 0 });
                setSelectedIndex(-1);
                setIsOpen(true);
            } catch (error) {
                if (error.name !== 'AbortError') setData({ groups: [], total: 0 });
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 300);

        return () => { window.clearTimeout(timeout); controller.abort(); };
    }, [query, canSearch]);

    const clear = () => {
        setQuery(''); setData({ groups: [], total: 0 }); setSelectedIndex(-1); setIsOpen(false); inputRef.current?.focus();
    };
    const choose = result => {
        setIsOpen(false);
        router.visit(result.url);
        onNavigate?.();
    };
    const onKeyDown = event => {
        if (event.key === 'Escape') { setIsOpen(false); inputRef.current?.blur(); return; }
        if (!isOpen || results.length === 0) return;
        if (event.key === 'ArrowDown') { event.preventDefault(); setSelectedIndex(index => (index + 1) % results.length); }
        if (event.key === 'ArrowUp') { event.preventDefault(); setSelectedIndex(index => (index <= 0 ? results.length - 1 : index - 1)); }
        if (event.key === 'Enter' && selectedIndex >= 0) { event.preventDefault(); choose(results[selectedIndex]); }
    };

    return <div ref={searchRef} className="relative w-full max-w-[26rem]">
        <label htmlFor="global-search" className="sr-only">Search eDATS</label>
        <div className="relative">
            <Icon icon="solar:magnifer-linear" width="18" height="18" className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" aria-hidden="true" />
            <input ref={inputRef} id="global-search" type="search" value={query} onChange={event => { setQuery(event.target.value); setIsOpen(true); }} onFocus={() => canSearch && setIsOpen(true)} onKeyDown={onKeyDown} placeholder="Search eDATS..." autoComplete="off" className="h-11 w-full rounded-lg border border-gray-300 bg-white py-2 pl-10 pr-16 text-sm leading-5 text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-green-700 focus:ring-2 focus:ring-green-700/15 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-green-500 dark:focus:ring-green-500/20" />
            <span className="absolute inset-y-0 right-2 flex items-center gap-1">
                {loading && <Icon icon="svg-spinners:90-ring-with-bg" width="17" height="17" className="text-green-700 dark:text-green-400" aria-label="Searching" />}
                {query && <button type="button" onClick={clear} className="rounded-md p-1 text-gray-400 transition hover:bg-gray-200 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-600/40 dark:hover:bg-gray-700 dark:hover:text-gray-100" aria-label="Clear search"><Icon icon="solar:close-circle-linear" width="18" height="18" /></button>}
            </span>
        </div>
        {isOpen && canSearch && <div className="absolute z-50 mt-2 max-h-[min(32rem,calc(100vh-7rem))] w-full overflow-y-auto rounded-xl border border-gray-200 bg-white p-2 shadow-xl dark:border-gray-700 dark:bg-gray-900" role="listbox" aria-label="Global search results">
            {results.length ? data.groups.map(group => <div key={group.key} className="py-1 first:pt-0 last:pb-0"><p className="px-2 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-gray-400 dark:text-gray-500">{group.label}</p>{group.results.map(result => {
                const index = results.indexOf(result);
                const active = index === selectedIndex;
                return <button key={`${group.key}-${result.title}-${result.url}`} type="button" role="option" aria-selected={active} onMouseEnter={() => setSelectedIndex(index)} onClick={() => choose(result)} className={`flex w-full items-start gap-3 rounded-lg px-2.5 py-2.5 text-left transition focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-600 ${active ? 'bg-green-50 dark:bg-green-950/50' : 'hover:bg-gray-50 dark:hover:bg-gray-800'}`}><span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700 dark:bg-green-950/60 dark:text-green-300"><Icon icon={resultIcons[result.type] || 'solar:magnifer-linear'} width="17" height="17" /></span><span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{result.title}</span><span className="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">{result.subtitle}</span></span><span className="mt-1 shrink-0 rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">{result.badge}</span></button>;
            })}</div>) : <div className="px-3 py-7 text-center"><Icon icon="solar:magnifer-linear" width="24" height="24" className="mx-auto text-gray-300 dark:text-gray-600" /><p className="mt-2 text-sm font-medium text-gray-600 dark:text-gray-300">No matching eDATS records</p><p className="mt-1 text-xs text-gray-400 dark:text-gray-500">Try a module, report, protected area, or authorized user.</p></div>}
        </div>}
    </div>;
}
