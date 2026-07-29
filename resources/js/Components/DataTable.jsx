import EmptyState from './EmptyState';

export default function DataTable({ columns = [], rows = [], rowKey = 'id', emptyTitle, emptyDescription, caption }) {
    // 🚀 SAFE CHECK: Siguroha nga Array gyud ang rows ug columns
    const safeRows = Array.isArray(rows) ? rows : [];
    const safeColumns = Array.isArray(columns) ? columns : [];

    if (!safeRows.length) {
        return <EmptyState title={emptyTitle} description={emptyDescription} />;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                {caption && <caption className="sr-only">{caption}</caption>}

                {/* 🚀 GINA-UPDATE NAKO DIRI: Dark Green background ug white uppercase text */}
                <thead className="bg-green-900 dark:bg-green-950 text-white">
                    <tr>
                        {safeColumns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={`whitespace-nowrap px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-white dark:text-green-100 ${column.className || ''}`}
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
                                    className={`whitespace-nowrap px-5 py-4 text-sm text-gray-700 dark:text-gray-200 ${column.cellClassName || ''}`}
                                >
                                    {column.render ? column.render(row) : row[column.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
