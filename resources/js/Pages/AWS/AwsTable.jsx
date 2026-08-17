import { useState } from 'react';
import Card from '@/Components/Card';
import StatusBadge from '@/Components/StatusBadge';
import { router } from '@inertiajs/react';

export default function AwsTable({
    records = [],
    selectedIds = [],
    handleSelectAll,
    handleSelectOne,
    pagination = null,
}) {
    const list = Array.isArray(records) ? records : (records?.data || []);

    // State para sa Modal sa pagtan-aw sa Weather Metrics
    const [selectedMetric, setSelectedMetric] = useState(null);
    const [isMetricModalOpen, setIsMetricModalOpen] = useState(false);

    const openMetricModal = (row) => {
        setSelectedMetric(row);
        setIsMetricModalOpen(true);
    };

    // KUNG WALAY RECORDS: Ipakita ang Empty State
    if (!list || list.length === 0) {
        return (
            <Card className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl flex flex-col items-center justify-center text-center py-24 px-6 bg-white dark:bg-gray-900">
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/50 text-green-600 text-3xl mb-4 shadow-sm border border-green-100 dark:border-green-900">
                    🏗️
                </div>
                <h3 className="text-base font-bold text-gray-900 dark:text-white mb-1">
                    No meteorological raw data recorded yet
                </h3>
                <p className="text-xs text-gray-500 dark:text-gray-400 max-w-sm mx-auto">
                    Wala pay nabasa o na-import nga data sa weather station.
                </p>
            </Card>
        );
    }

    return (
        <div className="space-y-4">
            <Card className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl overflow-hidden" padding="p-0">
                <div className="p-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                    <div>
                        <h3 className="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wider">AWS Raw Data Table</h3>
                        <p className="text-xs text-green-700 dark:text-green-400 font-semibold mt-0.5">📅 Meteorological and Weather Station Raw Data Records</p>
                    </div>
                    <span className="text-xs text-gray-500 italic">💡 Click any row to view full weather metrics</span>
                </div>

                <div className="overflow-x-auto custom-table-scrollbar">
                    <table className="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr className="border-b border-gray-200 bg-green-900 text-white uppercase tracking-wider dark:border-gray-700">
                                <th className="px-3 py-3.5 w-10 text-center">
                                    <input
                                        type="checkbox"
                                        onChange={handleSelectAll}
                                        checked={list.length > 0 && selectedIds.length === list.length}
                                        className="rounded border-gray-300 text-green-600 focus:ring-green-500"
                                    />
                                </th>
                                <th className="px-4 py-3.5 font-semibold">Protected Area</th>
                                <th className="px-4 py-3.5 font-semibold">Date</th>
                                <th className="px-4 py-3.5 font-semibold">Precipitation (mm)</th>
                                <th className="px-4 py-3.5 font-semibold">Wind Direction</th>
                                <th className="px-4 py-3.5 font-semibold">Wind Speed (m/s)</th>
                                <th className="px-4 py-3.5 font-semibold">Air Temperature (°C)</th>
                                <th className="px-4 py-3.5 font-semibold">Relative Humidity (%)</th>
                                <th className="px-4 py-3.5 font-semibold">Atmospheric Pressure (kPa)</th>
                                <th className="px-4 py-3.5 font-semibold">Remarks</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                            {list.map((row) => (
                                <tr
                                    key={row.id}
                                    onClick={() => openMetricModal(row)}
                                    className="cursor-pointer transition hover:bg-green-50/60 dark:hover:bg-green-950/30"
                                >
                                    <td className="px-3 py-3 text-center" onClick={(e) => e.stopPropagation()}>
                                        <input
                                            type="checkbox"
                                            checked={selectedIds.includes(row.id)}
                                            onChange={(e) => handleSelectOne(row.id, e)}
                                            className="rounded border-gray-300 text-green-600 focus:ring-green-500"
                                        />
                                    </td>
                                    <td className="px-4 py-3 font-semibold text-gray-900 dark:text-white">
                                        {row.protected_area?.name || '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                        {String(row.timestamps || row.start_date || '—')}
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{String(row.precipitation ?? '0')}</td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{String(row.wind_direction ?? '—')}</td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{String(row.wind_speed ?? '—')}</td>
                                    <td className="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{String(row.air_temperature ?? '—')}</td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{String(row.relative_humidity ?? '—')}</td>
                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{String(row.atmospheric_pressure ?? 'N/A')}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge variant={(row.remarks || 'Normal Weather Conditions').includes('Advisory') || (row.remarks || '').includes('Alert') ? 'pending' : 'active'}>
                                            {String(row.remarks || 'Normal Weather Conditions')}
                                        </StatusBadge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            {/* METRIC DETAILS MODAL */}
            {isMetricModalOpen && selectedMetric && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs overflow-y-auto">
                    <div className="relative w-full max-w-2xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[90vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <div>
                                <h3 className="font-bold text-gray-900 dark:text-white text-base">Meteorological Raw Data Details</h3>
                                <p className="text-xs text-gray-500">{selectedMetric.protected_area?.name || 'N/A'} — Date: {selectedMetric.timestamps || selectedMetric.start_date}</p>
                            </div>
                            <button type="button" onClick={() => setIsMetricModalOpen(false)} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-6 flex-1 text-sm">
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-xs">
                                    <span className="block text-[10px] font-bold text-gray-400 uppercase">Precipitation</span>
                                    <span className="text-base font-bold text-green-700 dark:text-green-400">{selectedMetric.precipitation ?? '0'} <span className="text-xs font-normal">mm</span></span>
                                </div>
                                <div className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-xs">
                                    <span className="block text-[10px] font-bold text-gray-400 uppercase">Wind Direction</span>
                                    <span className="text-base font-bold text-blue-700 dark:text-blue-400">{selectedMetric.wind_direction ?? '—'}</span>
                                </div>
                                <div className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-xs">
                                    <span className="block text-[10px] font-bold text-gray-400 uppercase">Wind Speed</span>
                                    <span className="text-base font-bold text-gray-800 dark:text-gray-200">{selectedMetric.wind_speed ?? '—'} <span className="text-xs font-normal">m/s</span></span>
                                </div>
                                <div className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-xs">
                                    <span className="block text-[10px] font-bold text-gray-400 uppercase">Air Temperature</span>
                                    <span className="text-base font-bold text-orange-600 dark:text-orange-400">{selectedMetric.air_temperature ?? '—'} <span className="text-xs font-normal">°C</span></span>
                                </div>
                                <div className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-xs">
                                    <span className="block text-[10px] font-bold text-gray-400 uppercase">Relative Humidity</span>
                                    <span className="text-base font-bold text-teal-600 dark:text-teal-400">{selectedMetric.relative_humidity ?? '—'} <span className="text-xs font-normal">%</span></span>
                                </div>
                                <div className="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-xs">
                                    <span className="block text-[10px] font-bold text-gray-400 uppercase">Atmospheric Pressure</span>
                                    <span className="text-base font-bold text-purple-600 dark:text-purple-400">{selectedMetric.atmospheric_pressure ?? 'N/A'} <span className="text-xs font-normal">kPa</span></span>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📝 Smart Weather Interpretation & Remarks</h4>
                                <div className="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 text-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                                    <span className="text-gray-800 dark:text-gray-200 font-medium text-sm">{selectedMetric.remarks || 'Normal Weather Conditions'}</span>
                                    <StatusBadge variant={(selectedMetric.remarks || 'Normal Weather Conditions').includes('Advisory') || (selectedMetric.remarks || '').includes('Alert') ? 'pending' : 'active'}>
                                        {selectedMetric.status || 'Approve'}
                                    </StatusBadge>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-end px-6 py-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800">
                            <button
                                type="button"
                                onClick={() => setIsMetricModalOpen(false)}
                                className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2 text-xs font-bold text-white shadow-md transition"
                            >
                                Close Details
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {pagination?.links?.length > 3 && (
                <nav className="flex flex-wrap items-center justify-end gap-1" aria-label="AWS pagination">
                    {pagination.links.map((link, index) => (
                        <button
                            key={`${link.label}-${index}`}
                            type="button"
                            disabled={!link.url || link.active}
                            onClick={() =>
                                link.url &&
                                router.get(link.url, {}, { preserveState: true, preserveScroll: true })
                            }
                            className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${
                                link.active
                                    ? 'bg-green-700 text-white'
                                    : link.url
                                    ? 'bg-white text-gray-700 hover:bg-green-50 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800'
                                    : 'cursor-not-allowed text-gray-400'
                            }`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </div>
    );
}
