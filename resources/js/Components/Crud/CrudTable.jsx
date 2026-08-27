import EmptyState from '@/Components/EmptyState';
import { TableSkeleton } from '@/Components/Skeleton';
import Tooltip from '@/Components/Tooltip';

export default function CrudTable({ title, subtitle, helperText, columns = [], rows = [], rowKey = 'id', onRowClick, isRowDisabled, loading = false, emptyTitle = 'No records found', emptyDescription, selectable = false, selectedKeys = [], onSelectRow, onSelectAll, headerActions, filters, pagination, caption, className = '', tableClassName = '' }) {
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

    const cellContent = (column, row) => column.render ? column.render(row) : row[column.key];
    const cellTooltip = (column, row) => typeof column.tooltip === 'function' ? column.tooltip(row) : column.tooltip;
    const renderCellContent = (column, row) => {
        const content = cellContent(column, row);
        const tooltip = cellTooltip(column, row);
        if (!tooltip || tooltip === '—') return content;
        return <Tooltip content={tooltip}><span tabIndex={0} className="outline-none">{content}</span></Tooltip>;
    };

    return <section className={`overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 ${className}`}>
        {(title || subtitle || helperText || headerActions) && <div className="flex flex-col gap-3 border-b border-gray-100 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="text-sm font-bold text-gray-900 dark:text-white">{title}</h2>{subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}{helperText && <p className="mt-1 text-xs font-semibold text-green-700 dark:text-green-400">{helperText}</p>}</div>{headerActions && <div className="flex flex-wrap items-center gap-2">{headerActions}</div>}</div>}
        {filters && <div className="border-b border-gray-100 p-4 dark:border-gray-800">{filters}</div>}
        {loading ? <TableSkeleton rows={5} columns={safeColumns.length + (selectable ? 1 : 0)} /> : safeRows.length === 0 ? <EmptyState title={emptyTitle} description={emptyDescription} /> : <div className="overflow-x-auto custom-table-scrollbar"><table className={`min-w-full border-collapse text-left ${tableClassName}`}>{caption && <caption className="sr-only">{caption}</caption>}<thead><tr className="border-b border-gray-200 bg-green-900 text-xs uppercase tracking-wider text-white dark:border-gray-700">{selectable && <th scope="col" className="w-12 px-4 py-3.5 text-center"><input type="checkbox" checked={allSelected} onChange={event => onSelectAll?.(event.target.checked, safeRows)} onClick={event => event.stopPropagation()} className="rounded border-gray-300 text-green-600 focus:ring-green-500" aria-label="Select all rows" /></th>}{safeColumns.map(column => <th key={column.key} scope="col" className={`whitespace-nowrap px-6 py-3.5 font-semibold ${column.headerClassName || column.className || ''}`}>{column.headerTooltip ? <Tooltip content={column.headerTooltip}><span tabIndex={0} className="outline-none">{column.label}</span></Tooltip> : column.label}</th>)}</tr></thead><tbody className="divide-y divide-gray-200 text-sm dark:divide-gray-800">{safeRows.map(row => { const key = keyFor(row); const disabled = isRowDisabled?.(row); const clickable = Boolean(onRowClick) && !disabled; return <tr key={key} tabIndex={clickable ? 0 : undefined} onClick={event => activate(event, row)} onKeyDown={event => activate(event, row)} className={`${clickable ? 'cursor-pointer hover:bg-green-50/60 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-600 dark:hover:bg-green-950/30' : ''} ${selected.has(key) ? 'bg-green-100/60 dark:bg-green-900/30' : ''} transition`}>{selectable && <td className="px-4 py-4 text-center" onClick={event => event.stopPropagation()}><input type="checkbox" checked={selected.has(key)} onChange={event => onSelectRow?.(key, event.target.checked, row)} className="rounded border-gray-300 text-green-600 focus:ring-green-500" aria-label={`Select row ${key}`} /></td>}{safeColumns.map(column => <td key={column.key} className={`px-6 py-4 align-top text-gray-700 dark:text-gray-300 ${column.cellClassName || ''}`}>{renderCellContent(column, row)}</td>)}</tr>; })}</tbody></table></div>}
        {pagination && <div className="border-t border-gray-100 px-6 py-4 dark:border-gray-800">{pagination}</div>}
    </section>;
}
