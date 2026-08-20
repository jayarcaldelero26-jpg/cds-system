import EmptyState from '@/Components/EmptyState';
import { TableSkeleton } from '@/Components/Skeleton';

export default function CrudTable({ title, subtitle, helperText, columns = [], rows = [], rowKey = 'id', onRowClick, isRowDisabled, loading = false, emptyTitle = 'No records found', emptyDescription, selectable = false, selectedKeys = [], onSelectRow, onSelectAll, headerActions, filters, pagination, caption, className = '' }) {
    const safeRows = Array.isArray(rows) ? rows : [];
    const safeColumns = Array.isArray(columns) ? columns : [];
    const keyFor = row => typeof rowKey === 'function' ? rowKey(row) : row[rowKey];
    const selected = new Set(selectedKeys);
    const allSelected = safeRows.length > 0 && safeRows.every(row => selected.has(keyFor(row)));
    const activate = (event, row) => {
        if (!onRowClick || isRowDisabled?.(row)) return;
        if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
        if (event.type === 'keydown') event.preventDefault();
        onRowClick(row);
    };

    return <section className={`overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 ${className}`}>
        {(title || subtitle || helperText || headerActions) && <div className="flex flex-col gap-3 border-b border-gray-200 bg-gray-50/80 px-5 py-4 dark:border-gray-700 dark:bg-gray-800/60 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="text-sm font-bold uppercase tracking-wider text-gray-900 dark:text-white">{title}</h2>{subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}{helperText && <p className="mt-1 text-xs font-semibold text-green-700 dark:text-green-400">{helperText}</p>}</div>{headerActions && <div className="flex flex-wrap items-center gap-2">{headerActions}</div>}</div>}
        {filters && <div className="border-b border-gray-200 p-4 dark:border-gray-700">{filters}</div>}
        {loading ? <TableSkeleton rows={5} columns={safeColumns.length + (selectable ? 1 : 0)} /> : safeRows.length === 0 ? <EmptyState title={emptyTitle} description={emptyDescription} /> : <div className="overflow-x-auto custom-table-scrollbar"><table className="min-w-full border-collapse text-left text-xs">{caption && <caption className="sr-only">{caption}</caption>}<thead className="bg-green-900 text-white dark:bg-green-950"><tr>{selectable && <th scope="col" className="w-12 px-4 py-3 text-center"><input type="checkbox" checked={allSelected} onChange={event => onSelectAll?.(event.target.checked, safeRows)} onClick={event => event.stopPropagation()} className="rounded border-green-200 text-green-700 focus:ring-green-400" aria-label="Select all rows" /></th>}{safeColumns.map(column => <th key={column.key} scope="col" className={`whitespace-nowrap px-4 py-3 font-bold uppercase tracking-wider text-white ${column.headerClassName || column.className || ''}`}>{column.label}</th>)}</tr></thead><tbody className="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">{safeRows.map(row => { const key = keyFor(row); const disabled = isRowDisabled?.(row); const clickable = Boolean(onRowClick) && !disabled; return <tr key={key} tabIndex={clickable ? 0 : undefined} onClick={event => activate(event, row)} onKeyDown={event => activate(event, row)} className={`${clickable ? 'cursor-pointer hover:bg-green-50/70 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-600 dark:hover:bg-green-950/30' : ''} ${selected.has(key) ? 'bg-green-100/60 dark:bg-green-900/30' : ''} transition-colors`}>{selectable && <td className="px-4 py-3 text-center" onClick={event => event.stopPropagation()}><input type="checkbox" checked={selected.has(key)} onChange={event => onSelectRow?.(key, event.target.checked, row)} className="rounded border-gray-300 text-green-700 focus:ring-green-600" aria-label={`Select row ${key}`} /></td>}{safeColumns.map(column => <td key={column.key} className={`px-4 py-3 align-top text-gray-700 dark:text-gray-200 ${column.cellClassName || ''}`}>{column.render ? column.render(row) : row[column.key]}</td>)}</tr>; })}</tbody></table></div>}
        {pagination && <div className="border-t border-gray-200 px-4 py-3 dark:border-gray-700">{pagination}</div>}
    </section>;
}
