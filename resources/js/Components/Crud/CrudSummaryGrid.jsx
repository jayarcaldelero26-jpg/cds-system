export default function CrudSummaryGrid({ items = [], children, columns = 4 }) {
    const layouts = { 1: 'grid-cols-1', 2: 'grid-cols-1 sm:grid-cols-2', 3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3', 4: 'grid-cols-2 lg:grid-cols-4' };
    return <div className={`grid gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40 ${layouts[Math.min(Math.max(columns, 1), 4)]}`}>{items.map((item, index) => <div key={item.key ?? item.label ?? index} className="min-w-0"><p className="truncate text-xs text-gray-500 dark:text-gray-400">{item.label}</p><div className="mt-1 truncate text-sm font-bold text-gray-900 dark:text-white">{item.render ? item.render(item) : (item.value ?? '—')}</div></div>)}{children}</div>;
}
