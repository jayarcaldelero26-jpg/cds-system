const tabClass = active => `inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 py-2 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 ${active
    ? 'cds-tab-active border-green-700 shadow-sm'
    : 'border-gray-200 bg-white text-gray-700 hover:border-green-300 hover:bg-green-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200'}`;

function countClass(active) {
    return `rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums ${active
        ? 'bg-white/20 text-white'
        : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-200'}`;
}

export default function FinalArchiveStatusTabs({
    value,
    archivesTotal = 0,
    awaitingArchiveTotal = 0,
    awaitingReceiptTotal = 0,
    onChange,
}) {
    const tabs = [{ value: 'archives', label: 'Archives', count: archivesTotal }];
    if (awaitingArchiveTotal > 0) tabs.push({ value: 'awaiting', label: 'Awaiting Archive', count: awaitingArchiveTotal });
    if (awaitingReceiptTotal > 0) tabs.push({ value: 'receipt', label: 'Awaiting Final Receipt', count: awaitingReceiptTotal });

    return <div role="tablist" aria-label="Archives status" className="flex min-w-0 flex-wrap items-center gap-2">
        {tabs.map(tab => {
            const active = value === tab.value;
            return <button
                key={tab.value}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => onChange?.(tab.value)}
                className={tabClass(active)}
            >
                <span>{tab.label}</span>
                <span className={countClass(active)}>{tab.count || 0}</span>
            </button>;
        })}
    </div>;
}
