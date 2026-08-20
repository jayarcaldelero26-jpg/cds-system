import { useState } from 'react';
import { router } from '@inertiajs/react';
import StatusBadge from '@/Components/StatusBadge';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';

const displayValue = (value, fallback = '—') => value === null || value === undefined || value === '' ? fallback : String(value);

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
        { key: 'protected_area', label: 'Protected Area', cellClassName: 'font-semibold text-gray-900 dark:text-white', render: row => row.protected_area?.name || '—' },
        { key: 'date', label: 'Date', cellClassName: 'whitespace-nowrap font-medium text-gray-900 dark:text-white', render: row => String(row.timestamps || row.start_date || '—') },
        { key: 'precipitation', label: 'Precipitation (mm)', render: row => String(row.precipitation ?? '0') },
        { key: 'wind_direction', label: 'Wind Direction', render: row => String(row.wind_direction ?? '—') },
        { key: 'wind_speed', label: 'Wind Speed (m/s)', render: row => String(row.wind_speed ?? '—') },
        { key: 'air_temperature', label: 'Air Temperature (°C)', render: row => String(row.air_temperature ?? '—') },
        { key: 'relative_humidity', label: 'Relative Humidity (%)', render: row => String(row.relative_humidity ?? '—') },
        { key: 'atmospheric_pressure', label: 'Atmospheric Pressure (kPa)', render: row => String(row.atmospheric_pressure ?? 'N/A') },
        { key: 'remarks', label: 'Remarks', render: row => <StatusBadge variant={statusVariant(row.remarks || '')}>{String(row.remarks || 'Normal Weather Conditions')}</StatusBadge> },
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
