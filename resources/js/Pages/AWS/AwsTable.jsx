import { useState } from 'react';
import { router } from '@inertiajs/react';
import StatusBadge from '@/Components/StatusBadge';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';

const displayValue = (value, fallback = '—') => value === null || value === undefined || value === '' ? fallback : String(value);
const protectedAreaTableLabel = protectedArea => {
    const fullName = protectedArea?.name?.trim() || '';
    const shortName = protectedArea?.short_name?.trim();

    if (shortName) return { label: shortName, fullName: fullName || shortName };

    const parentheticalAcronym = fullName.match(/\(([^()]+)\)\s*$/)?.[1]?.trim();
    return { label: parentheticalAcronym || fullName || '—', fullName: fullName || '—' };
};

const metric = (value, unit, fallback = '—') => (
    <span>
        {displayValue(value, fallback)}
        {value !== null && value !== undefined && value !== '' && unit && (
            <span className="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">{unit}</span>
        )}
    </span>
);

const Detail = ({ label, children }) => (
    <div className="min-w-0">
        <dt className="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt>
        <dd className="mt-1 break-words text-sm font-medium text-gray-900 dark:text-white">{children}</dd>
    </div>
);

const statusVariant = (remarks = '') => (
    (remarks || 'Normal Weather Conditions').includes('Advisory') || remarks.includes('Alert') ? 'pending' : 'active'
);

export default function AwsTable({ records = [], selectedIds = [], handleSelectAll, handleSelectOne, pagination = null, selectable = false }) {
    const list = Array.isArray(records) ? records : (records?.data || []);
    const [selectedMetric, setSelectedMetric] = useState(null);

    const columns = [
        {
            key: 'protected_area',
            label: 'Protected Area',
            headerClassName: 'w-[8%] whitespace-normal px-3 py-3 text-left text-[10px] leading-tight tracking-normal',
            cellClassName: 'w-[8%] overflow-hidden px-3 py-3 text-xs',
            render: row => {
                const protectedArea = protectedAreaTableLabel(row.protected_area);
                return <span title={protectedArea.fullName} className="block w-full truncate whitespace-nowrap font-semibold text-gray-900 dark:text-white">{protectedArea.label}</span>;
            },
        },
        { key: 'date', label: 'Date', headerClassName: 'w-[10%] whitespace-normal px-3 py-3 text-left text-[10px] leading-tight tracking-normal', cellClassName: 'w-[10%] whitespace-nowrap px-3 py-3 text-xs font-medium text-gray-900 dark:text-white', render: row => String(row.timestamps || row.start_date || '—') },
        { key: 'precipitation', label: <><span className="block">Precipitation</span><span className="block">(mm)</span></>, headerClassName: 'w-[11%] whitespace-normal px-2 py-3 text-center text-[10px] leading-tight tracking-normal', cellClassName: 'w-[11%] whitespace-nowrap px-2 py-3 text-center text-xs tabular-nums', render: row => String(row.precipitation ?? '0') },
        { key: 'wind_direction', label: <><span className="block">Wind</span><span className="block">Direction</span></>, headerClassName: 'w-[9%] whitespace-normal px-2 py-3 text-center text-[10px] leading-tight tracking-normal', cellClassName: 'w-[9%] whitespace-nowrap px-2 py-3 text-center text-xs', render: row => String(row.wind_direction ?? '—') },
        { key: 'wind_speed', label: <><span className="block">Wind Speed</span><span className="block">(m/s)</span></>, headerClassName: 'w-[10%] whitespace-normal px-2 py-3 text-center text-[10px] leading-tight tracking-normal', cellClassName: 'w-[10%] whitespace-nowrap px-2 py-3 text-center text-xs tabular-nums', render: row => String(row.wind_speed ?? '—') },
        { key: 'air_temperature', label: <><span className="block">Air Temperature</span><span className="block">(°C)</span></>, headerClassName: 'w-[11%] whitespace-normal px-2 py-3 text-center text-[10px] leading-tight tracking-normal', cellClassName: 'w-[11%] whitespace-nowrap px-2 py-3 text-center text-xs tabular-nums', render: row => String(row.air_temperature ?? '—') },
        { key: 'relative_humidity', label: <><span className="block">Relative Humidity</span><span className="block">(%)</span></>, headerClassName: 'w-[12%] whitespace-normal px-2 py-3 text-center text-[10px] leading-tight tracking-normal', cellClassName: 'w-[12%] whitespace-nowrap px-2 py-3 text-center text-xs tabular-nums', render: row => String(row.relative_humidity ?? '—') },
        { key: 'atmospheric_pressure', label: <><span className="block">Atmospheric Pressure</span><span className="block">(kPa)</span></>, headerClassName: 'w-[14%] whitespace-normal px-2 py-3 text-center text-[10px] leading-tight tracking-normal', cellClassName: 'w-[14%] whitespace-nowrap px-2 py-3 text-center text-xs tabular-nums', render: row => String(row.atmospheric_pressure ?? 'N/A') },
        {
            key: 'remarks',
            label: 'Remarks',
            headerClassName: 'w-[15%] whitespace-normal px-3 py-3 text-left text-[10px] leading-tight tracking-normal',
            cellClassName: 'w-[15%] overflow-hidden px-3 py-3 text-xs',
            render: row => {
                const remarks = String(row.remarks || 'Normal Weather Conditions');
                return <span title={remarks} className="block w-full min-w-0 overflow-hidden"><StatusBadge variant={statusVariant(row.remarks || '')}><span className="block w-full min-w-0 truncate whitespace-nowrap">{remarks}</span></StatusBadge></span>;
            },
        },
    ];

    const paginationControls = pagination?.links?.length > 3 ? (
        <nav className="flex flex-wrap items-center justify-end gap-1" aria-label="AWS raw data pagination">
            {pagination.links.map((link, index) => (
                <button
                    key={`${link.label}-${index}`}
                    type="button"
                    disabled={!link.url || link.active}
                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                    className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${link.active ? 'bg-green-700 text-white' : link.url ? 'bg-white text-gray-700 hover:bg-green-50 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800' : 'cursor-not-allowed text-gray-400'}`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    ) : null;

    return <>
        <CrudTable
            title="AWS Raw Data Table"
            subtitle={`${pagination?.total ?? list.length} weather record${(pagination?.total ?? list.length) === 1 ? '' : 's'}`}
            helperText="Click any row to view full weather metrics"
            caption="Automated Weather Station raw data records"
            tableClassName="w-full min-w-0 table-fixed"
            columns={columns}
            rows={list}
            rowKey="id"
            onRowClick={setSelectedMetric}
            selectable={selectable}
            selectedKeys={selectedIds}
            onSelectAll={checked => handleSelectAll?.({ target: { checked } })}
            onSelectRow={(id, checked) => handleSelectOne?.(id, { target: { checked }, stopPropagation() {} })}
            emptyTitle="No meteorological raw data recorded yet"
            emptyDescription="No weather-station data has been read or imported yet."
            pagination={paginationControls}
        />

        <CrudDetailsModal
            open={Boolean(selectedMetric)}
            icon="☁"
            title="AWS Raw Data Full Details"
            subtitle={selectedMetric ? `${selectedMetric.protected_area?.name || 'N/A'} · ${selectedMetric.timestamps || selectedMetric.start_date || 'No date'}` : ''}
            onClose={() => setSelectedMetric(null)}
            maxWidth="max-w-5xl"
            summary={selectedMetric && <CrudSummaryGrid columns={4} items={[
                { label: 'Date', value: displayValue(selectedMetric.timestamps || selectedMetric.start_date) },
                { label: 'Precipitation', render: () => metric(selectedMetric.precipitation, 'mm', '0') },
                { label: 'Air Temperature', render: () => metric(selectedMetric.air_temperature, '°C') },
                { label: 'Relative Humidity', render: () => metric(selectedMetric.relative_humidity, '%') },
                { label: 'Atmospheric Pressure', render: () => metric(selectedMetric.atmospheric_pressure, 'kPa', 'N/A') },
            ]} />}
        >
            {selectedMetric && <div className="grid gap-4 lg:grid-cols-2">
                <CrudSection title="Weather / Precipitation"><dl className="grid gap-4 sm:grid-cols-2">
                    <Detail label="Observation Date">{displayValue(selectedMetric.timestamps || selectedMetric.start_date)}</Detail>
                    <Detail label="Precipitation">{metric(selectedMetric.precipitation, 'mm', '0')}</Detail>
                </dl></CrudSection>
                <CrudSection title="Wind"><dl className="grid gap-4 sm:grid-cols-2">
                    <Detail label="Wind Direction">{displayValue(selectedMetric.wind_direction)}</Detail>
                    <Detail label="Wind Speed">{metric(selectedMetric.wind_speed, 'm/s')}</Detail>
                </dl></CrudSection>
                <CrudSection title="Atmospheric Conditions"><dl className="grid gap-4 sm:grid-cols-3">
                    <Detail label="Air Temperature">{metric(selectedMetric.air_temperature, '°C')}</Detail>
                    <Detail label="Relative Humidity">{metric(selectedMetric.relative_humidity, '%')}</Detail>
                    <Detail label="Atmospheric Pressure">{metric(selectedMetric.atmospheric_pressure, 'kPa', 'N/A')}</Detail>
                </dl></CrudSection>
                <CrudSection title="Location / Station"><dl className="grid gap-4 sm:grid-cols-2">
                    <Detail label="Protected Area">{displayValue(selectedMetric.protected_area?.name)}</Detail>
                    <Detail label="Station Name">{displayValue(selectedMetric.station_name)}</Detail>
                    <Detail label="Location">{displayValue(selectedMetric.location)}</Detail>
                    <Detail label="Coordinates">{selectedMetric.latitude !== null && selectedMetric.latitude !== undefined && selectedMetric.longitude !== null && selectedMetric.longitude !== undefined ? `${selectedMetric.latitude}, ${selectedMetric.longitude}` : '—'}</Detail>
                </dl></CrudSection>
                <CrudSection title="Remarks" className="lg:col-span-2"><div className="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
                    <p className="text-sm font-medium text-gray-800 dark:text-gray-200">{selectedMetric.remarks || 'Normal Weather Conditions'}</p>
                    <StatusBadge variant={statusVariant(selectedMetric.remarks || '')}>{selectedMetric.status || 'Approve'}</StatusBadge>
                </div></CrudSection>
            </div>}
        </CrudDetailsModal>
    </>;
}
