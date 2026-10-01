import EmptyState from './EmptyState';

export default function DataTable({ columns = [], rows = [], rowKey = 'id', emptyTitle, emptyDescription, caption }) {
    // 🚀 SAFE CHECK: Siguroha nga Array gyud ang rows ug columns
    const safeRows = Array.isArray(rows) ? rows : [];
    const safeColumns = Array.isArray(columns) ? columns : [];

    if (!safeRows.length) {
        return <EmptyState title={emptyTitle} description={emptyDescription} />;
    }

    return (
        <div className="cds-card-surface overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <div className="overflow-x-auto custom-table-scrollbar">
            <table className="cds-data-table min-w-full divide-y divide-gray-200 text-left dark:divide-gray-700">
                {caption && <caption className="sr-only">{caption}</caption>}

                {/* 🚀 GINA-UPDATE NAKO DIRI: Dark Green background ug white uppercase text */}
                <thead className="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-200">
                    <tr>
                        {safeColumns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                aria-sort={column.ariaSort}
                                className={`whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wider ${column.className || ''}`}
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>

                    <tbody className="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    {safeRows.map((row) => (
                        <tr key={row[rowKey]} className="transition hover:bg-green-50/40 dark:hover:bg-green-950/20">
                            {safeColumns.map((column) => (
                                <td
                                    key={column.key}
                                    className={`whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-200 ${column.cellClassName || ''}`}
                                >
                                    {column.render ? column.render(row) : row[column.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div></div>
    );
}
