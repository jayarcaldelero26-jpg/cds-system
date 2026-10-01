import { useContext } from 'react';
import CrudModalCardContext from './CrudModalCardContext';

export default function CrudSummaryGrid({ items = [], children, columns = 4 }) {
    const modalCardSurface = useContext(CrudModalCardContext);
    const layouts = { 1: 'grid-cols-1', 2: 'grid-cols-1 sm:grid-cols-2', 3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3', 4: 'grid-cols-2 sm:grid-cols-4' };
    return <div className={`${modalCardSurface ? 'cds-card-surface bg-white dark:bg-gray-800 ' : 'bg-gray-50 dark:bg-gray-800/50 '}grid gap-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700 ${layouts[Math.min(Math.max(columns, 1), 4)]}`}>{items.filter(item => item?.render || (item?.value !== null && item?.value !== undefined && String(item.value).trim() !== '')).map((item, index) => <div key={item.key ?? item.label ?? index} className="min-w-0"><p className="whitespace-normal break-words text-[11px] font-medium uppercase text-gray-500 dark:text-gray-400">{item.label}</p><div className="mt-0.5 whitespace-normal break-words text-base font-semibold text-gray-900 dark:text-white">{item.render ? item.render(item) : (item.value ?? '')}</div></div>)}{children}</div>;
}
