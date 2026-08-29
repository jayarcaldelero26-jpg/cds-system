import EmptyState from '@/Components/EmptyState';
import { TableSkeleton } from '@/Components/Skeleton';
import Tooltip from '@/Components/Tooltip';

export default function CrudTable({ title, subtitle, helperText, columns = [], rows = [], rowKey = 'id', onRowClick, isRowDisabled, loading = false, emptyTitle = 'No records found', emptyDescription, selectable = false, selectedKeys = [], onSelectRow, onSelectAll, headerActions, filters, pagination, caption, className = '', tableClassName = '', tableContainerClassName = '', tableHeaderClassName = '', compact = false, compactEmpty = false }) {
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
    const cellPadding = compact ? 'px-3 py-3' : 'px-6 py-4';
    const headerPadding = compact ? 'px-3 py-3' : 'px-6 py-3.5';

    const empty = !loading && safeRows.length === 0;
    const showHeader = !compactEmpty || !empty;
    const showPagination = pagination && (!compactEmpty || !empty);

    return <>
        {(filters || headerActions) && <div className="mb-4 flex flex-col gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-end sm:justify-between"><div className="min-w-0 flex-1">{filters}</div>{headerActions && <div className="flex shrink-0 flex-wrap items-center gap-2">{headerActions}</div>}</div>}
        <section className={`overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 ${className}`}>
        {showHeader && (title || subtitle || helperText) && <div className="flex flex-col gap-3 border-b border-gray-100 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="text-sm font-bold text-gray-900 dark:text-white">{title}</h2>{subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}{helperText && <p className="mt-1 text-xs font-semibold text-green-700 dark:text-green-400">{helperText}</p>}</div></div>}
        {loading ? <TableSkeleton rows={5} columns={safeColumns.length + (selectable ? 1 : 0)} /> : empty ? (compactEmpty ? <CompactReportEmptyState title={emptyTitle} description={emptyDescription} /> : <EmptyState title={emptyTitle} description={emptyDescription} />) : <div className={`overflow-x-auto custom-table-scrollbar ${tableContainerClassName}`}><table className={`min-w-full border-collapse text-left ${tableClassName}`}>{caption && <caption className="sr-only">{caption}</caption>}<thead><tr className="border-b border-gray-200 bg-green-900 text-xs text-white dark:border-gray-700">{selectable && <th scope="col" className={`${tableHeaderClassName} w-12 ${headerPadding} text-center`}><input type="checkbox" checked={allSelected} onChange={event => onSelectAll?.(event.target.checked, safeRows)} onClick={event => event.stopPropagation()} className="rounded border-gray-300 text-green-600 focus:ring-green-500" aria-label="Select all rows" /></th>}{safeColumns.map(column => <th key={column.key} scope="col" className={`${tableHeaderClassName} ${headerPadding} whitespace-normal font-semibold leading-tight ${column.headerClassName || column.className || ''}`}>{column.headerTooltip ? <Tooltip content={column.headerTooltip}><span tabIndex={0} className="outline-none">{column.label}</span></Tooltip> : column.label}</th>)}</tr></thead><tbody className="divide-y divide-gray-200 text-sm dark:divide-gray-800">{safeRows.map(row => { const key = keyFor(row); const disabled = isRowDisabled?.(row); const clickable = Boolean(onRowClick) && !disabled; return <tr key={key} tabIndex={clickable ? 0 : undefined} onClick={event => activate(event, row)} onKeyDown={event => activate(event, row)} className={`${clickable ? 'cursor-pointer hover:bg-green-50/60 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-green-600 dark:hover:bg-green-950/30' : ''} ${selected.has(key) ? 'bg-green-100/60 dark:bg-green-900/30' : ''} transition`}>{selectable && <td className={`${cellPadding} text-center`} onClick={event => event.stopPropagation()}><input type="checkbox" checked={selected.has(key)} onChange={event => onSelectRow?.(key, event.target.checked, row)} className="rounded border-gray-300 text-green-600 focus:ring-green-500" aria-label={`Select row ${key}`} /></td>}{safeColumns.map(column => <td key={column.key} className={`${cellPadding} align-top text-gray-700 dark:text-gray-300 ${column.cellClassName || ''}`}>{renderCellContent(column, row)}</td>)}</tr>; })}</tbody></table></div>}
        {showPagination && <div className="border-t border-gray-100 px-5 py-3 dark:border-gray-800">{pagination}</div>}
    </section></>;
}

function CompactReportEmptyState({ title, description }) {
    return <div className="flex min-h-[112px] items-center justify-center px-5 py-4 text-center">
        <div className="flex max-w-xl items-center gap-3 sm:gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-300" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-5 w-5"><path d="M6 3.75h8.25L18.5 8v12.25H6V3.75Z" strokeLinecap="round" strokeLinejoin="round" /><path d="M14 3.75V8h4.5M9 12h6M9 15.5h4" strokeLinecap="round" strokeLinejoin="round" /></svg>
            </div>
            <div className="text-left">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-white">{title}</h3>
                <p className="mt-0.5 text-xs leading-5 text-gray-500 dark:text-gray-400">{description}</p>
            </div>
        </div>
    </div>;
}
