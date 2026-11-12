import { Link, usePage } from '@inertiajs/react';

/** Ongoing includes every record that has not passed verified final archiving. */
export default function ReportArchiveTabs({ counts = {}, queryKey = 'archive_tab' }) {
    const { url = '/' } = usePage();
    const current = new URL(url, 'http://cds-smart.local');
    const active = current.searchParams.get(queryKey) === 'archived' ? 'archived' : 'ongoing';

    const hrefFor = (tab) => {
        const next = new URL(current);
        next.searchParams.set(queryKey, tab);
        for (const key of [...next.searchParams.keys()]) {
            if (key === 'page' || key.endsWith('_page')) next.searchParams.delete(key);
        }
        return `${next.pathname}${next.search}`;
    };

    return (
        <nav className="mb-4 flex w-fit gap-1 rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900" aria-label="Report archive status">
            {[
                ['ongoing', 'Ongoing'],
                ['archived', 'Archived'],
            ].map(([key, label]) => (
                <Link
                    key={key}
                    href={hrefFor(key)}
                    preserveScroll
                    aria-current={active === key ? 'page' : undefined}
                    className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${active === key ? 'bg-white text-green-800 shadow-sm dark:bg-gray-800 dark:text-green-300' : 'text-gray-600 hover:bg-white hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white'}`}
                >
                    <span>{label}</span>
                    <span className="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] leading-none text-gray-600 dark:bg-gray-700 dark:text-gray-200" aria-label={`${counts[key] ?? 0} reports`}>
                        {counts[key] ?? 0}
                    </span>
                </Link>
            ))}
        </nav>
    );
}
