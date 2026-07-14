import EmptyState from './EmptyState';

export default function DataTable({ columns, rows, rowKey = 'id', emptyTitle, emptyDescription, caption }) {
    if (!rows.length) return <EmptyState title={emptyTitle} description={emptyDescription} />;
    return <div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">{caption && <caption className="sr-only">{caption}</caption>}<thead className="bg-gray-50 dark:bg-gray-800"><tr>{columns.map((column) => <th key={column.key} scope="col" className={`whitespace-nowrap px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 ${column.className || ''}`}>{column.label}</th>)}</tr></thead><tbody className="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">{rows.map((row) => <tr key={row[rowKey]} className="transition hover:bg-green-50/40 dark:hover:bg-green-950/20">{columns.map((column) => <td key={column.key} className={`whitespace-nowrap px-5 py-4 text-sm text-gray-700 dark:text-gray-200 ${column.cellClassName || ''}`}>{column.render ? column.render(row) : row[column.key]}</td>)}</tr>)}</tbody></table></div>;
}
