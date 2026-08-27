import { useState, useEffect, useRef } from 'react';
import { Link } from '@inertiajs/react';
import FloatingInput from './Form/FloatingInput';

export default function GlobalSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [isOpen, setIsOpen] = useState(false);
    const searchRef = useRef(null);

    // Close dropdown inig click sa gawas sa search bar
    useEffect(() => {
        function handleClickOutside(event) {
            if (searchRef.current && !searchRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Live search inig type sa user
    useEffect(() => {
        if (query.trim().length < 2) {
            setResults([]);
            return;
        }

        const delayDebounceFn = setTimeout(() => {
            fetch(`/api/global-search?q=${encodeURIComponent(query)}`)
                .then((res) => res.json())
                .then((data) => {
                    setResults(data);
                    setIsOpen(true);
                });
        }, 300); // 300ms delay para dili ma-spam ang database

        return () => clearTimeout(delayDebounceFn);
    }, [query]);

    return (
        <div ref={searchRef} className="relative w-full max-w-md">
            <div className="relative">
                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <span className="text-gray-400">🔍</span>
                </div>
                <FloatingInput id="global-search" label="Global search" size="sm" type="search" value={query} onChange={(e) => { setQuery(e.target.value); setIsOpen(true); }} onFocus={() => setIsOpen(true)} placeholder="Pujada Bay, Watershed..." className="pl-10" />
            </div>

            {/* DROPDOWN RESULT LIST */}
            {isOpen && results.length > 0 && (
                <div className="absolute z-50 mt-1 w-full max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-800 dark:bg-gray-950">
                    {results.map((result, idx) => (
                        <Link
                            key={idx}
                            href={result.url}
                            onClick={() => setIsOpen(false)}
                            className="flex flex-col rounded-md px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-900"
                        >
                            <span className="text-xs font-semibold uppercase tracking-wider text-green-700 dark:text-green-400">
                                {result.category}
                            </span>
                            <span className="text-sm font-medium text-gray-900 dark:text-white">
                                {result.title}
                            </span>
                        </Link>
                    ))}
                </div>
            )}

            {isOpen && query.trim().length >= 2 && results.length === 0 && (
                <div className="absolute z-50 mt-1 w-full rounded-lg border border-gray-200 bg-white p-4 text-center text-sm text-gray-500 shadow-lg dark:border-gray-800 dark:bg-gray-950">
                    No records found for "{query}"
                </div>
            )}
        </div>
    );
}
