import { FloatingSelect } from '@/Components/Form';
import DateRangePicker from '@/Components/DateRangePicker';
import { localDateInputValue } from '@/Utils/dateInput';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AwsSummaryExportModal from './AwsSummaryExportModal';
import AwsWeatherRemarkBadge from './AwsWeatherRemarkBadge';

const emptyMark = '\u2014';
const blank = (value) => value === null || value === undefined || value === '' ? emptyMark : value;
const number = (value, decimals = 2) => value === null || value === undefined ? emptyMark : Number(value).toFixed(decimals);
const normalizeMode = (value) => value === 'custom_range' ? 'custom_range' : 'one_month';

export default function AwsMonthlySummary({ rows = [], protectedAreas = [], filters = {}, yearOptions = [], monthOptions = [], canImport = false, onImport }) {
    const today = localDateInputValue();
    const initial = {
        mode: normalizeMode(filters.mode),
        year: filters.year || new Date().getFullYear(),
        month: filters.month || filters.to_month || filters.from_month || new Date().getMonth() + 1,
        date_from: filters.date_from || today,
        date_to: filters.date_to || today,
        protected_area_id: filters.protected_area_id || '',
    };
    const [form, setForm] = useState(initial);
    const [exportModalOpen, setExportModalOpen] = useState(false);
    const exportButtonRef = useRef(null);

    useEffect(() => {
        setForm({
            mode: normalizeMode(filters.mode),
            year: filters.year || new Date().getFullYear(),
            month: filters.month || filters.to_month || filters.from_month || new Date().getMonth() + 1,
            date_from: filters.date_from || today,
            date_to: filters.date_to || today,
            protected_area_id: filters.protected_area_id || '',
        });
    }, [filters.mode, filters.year, filters.month, filters.from_month, filters.to_month, filters.date_from, filters.date_to, filters.protected_area_id]);

    const query = {
        mode: form.mode,
        year: form.mode === 'one_month' ? form.year : undefined,
        month: form.mode === 'one_month' ? form.month : undefined,
        date_from: form.mode === 'custom_range' ? form.date_from : undefined,
        date_to: form.mode === 'custom_range' ? form.date_to : undefined,
        protected_area_id: form.protected_area_id || undefined,
        tab: 'monthly-summary',
    };
    const confirmedForm = {
        mode: normalizeMode(filters.mode),
        year: filters.year || new Date().getFullYear(),
        month: filters.month || filters.to_month || filters.from_month || new Date().getMonth() + 1,
        date_from: filters.date_from || today,
        date_to: filters.date_to || today,
        protected_area_id: filters.protected_area_id || '',
    };
    const confirmedQuery = {
        mode: confirmedForm.mode,
        year: confirmedForm.mode === 'one_month' ? confirmedForm.year : undefined,
        month: confirmedForm.mode === 'one_month' ? confirmedForm.month : undefined,
        date_from: confirmedForm.mode === 'custom_range' ? confirmedForm.date_from : undefined,
        date_to: confirmedForm.mode === 'custom_range' ? confirmedForm.date_to : undefined,
        protected_area_id: confirmedForm.protected_area_id || undefined,
        tab: 'monthly-summary',
    };

    const apply = () => router.get(route('aws.index'), query, { preserveState: true, preserveScroll: true, replace: true });
    const setField = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }));
    const reset = () => {
        const next = { mode: 'one_month', year: String(new Date().getFullYear()), month: String(new Date().getMonth() + 1), date_from: today, date_to: today, protected_area_id: '' };
        setForm(next);
        router.get(route('aws.index'), { mode: 'one_month', year: next.year, month: next.month, tab: 'monthly-summary' }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = (format) => route('aws.summary.export', { ...confirmedQuery, format });
    const exportFile = async (format) => {
        const response = await window.fetch(exportUrl(format), { credentials: 'same-origin', headers: { Accept: 'application/octet-stream' } });
        const contentType = response.headers.get('content-type') || '';
        if (!response.ok || contentType.includes('text/html')) throw new Error('Export request failed.');
        const blob = await response.blob();
        const disposition = response.headers.get('content-disposition') || '';
        const utfFilename = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
        const plainFilename = disposition.match(/filename="?([^";]+)"?/i)?.[1];
        const filename = utfFilename ? decodeURIComponent(utfFilename) : (plainFilename || 'aws-summary-export.' + format);
        const objectUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => window.URL.revokeObjectURL(objectUrl), 1000);
    };
    const monthName = (value) => monthOptions.find((month) => String(month.value) === String(value))?.label || new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2000, Number(value) - 1, 1));
    const dateLabel = (value) => value ? new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' }).format(new Date(value + 'T00:00:00')) : emptyMark;
    const reportingPeriod = confirmedForm.mode === 'one_month'
        ? monthName(confirmedForm.month) + ' ' + confirmedForm.year
        : dateLabel(confirmedForm.date_from) + String.fromCharCode(8211) + dateLabel(confirmedForm.date_to);
    const summaryType = confirmedForm.mode === 'one_month' ? '1 Month — Daily Breakdown' : 'Custom Range — Monthly Breakdown';
    const selectedProtectedArea = confirmedForm.protected_area_id
        ? protectedAreas.find((area) => String(area.id) === String(confirmedForm.protected_area_id))?.name || 'Selected Protected Area'
        : 'All Protected Areas';
    const grouped = rows.reduce((groups, row) => {
        const key = row.protected_area_id ?? 'unknown';
        groups[key] = groups[key] || { name: row.protected_area_name, rows: [] };
        groups[key].rows.push(row);
        return groups;
    }, {});
    const groupEntries = Object.values(grouped);
    const headers = ['Reporting Period', 'Average Atmospheric Pressure (kPa)', 'Average Air Temperature (\u00B0C)', 'Average Vapor Pressure Deficit (kPa)', 'Average Relative Humidity (%)', 'Mean Wind Direction (\u00B0)', 'Total Precipitation (mm)', 'Average Wind Speed (m/s)', 'Remarks'];
    const rowValues = (row) => [row.period, number(row.average_atmospheric_pressure), number(row.average_air_temperature), number(row.average_vapor_pressure_deficit, 3), number(row.average_relative_humidity), number(row.mean_wind_direction), number(row.total_precipitation), number(row.average_wind_speed), row.remarks];
    const observationCount = rows.reduce((total, row) => total + (Number(row.observation_count) || 0), 0);
    const expectedObservations = rows.reduce((total, row) => total + (Number(row.expected_observations) || 0), 0);
    const completeness = expectedObservations > 0 ? ((observationCount / expectedObservations) * 100).toFixed(1) + '%' : emptyMark;
    const totalPrecipitation = rows.reduce((total, row) => row.total_precipitation === null || row.total_precipitation === undefined ? total : total + (Number(row.total_precipitation) || 0), 0);
    const interpretableDays = rows.filter((row) => row.remarks && !['No Data', 'Weather Condition Unavailable'].includes(row.remarks)).length;

    return <div className="space-y-4">
        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Summary Range">
                {[['one_month', '1 Month'], ['custom_range', 'Custom Range']].map(([value, label]) => <button key={value} type="button" onClick={() => setForm((current) => ({ ...current, mode: value }))} aria-pressed={form.mode === value} className={form.mode === value ? 'rounded-xl bg-green-700 px-4 py-2.5 text-xs font-bold text-white' : 'rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200'}>{label}</button>)}
            </div>
            <div className="grid gap-3 md:grid-cols-[repeat(3,minmax(0,1fr))_auto] md:items-end">
                {form.mode === 'one_month' && <FloatingSelect id="aws-summary-year" label="Year" size="sm" value={form.year} onChange={setField('year')}>{yearOptions.map((year) => <option key={year} value={year}>{year}</option>)}</FloatingSelect>}
                {form.mode === 'one_month' && <FloatingSelect id="aws-summary-month" label="Month" size="sm" value={form.month} onChange={setField('month')}>{monthOptions.map((month) => <option key={month.value} value={month.value}>{month.label}</option>)}</FloatingSelect>}
                {form.mode === 'custom_range' && <DateRangePicker id="aws-summary-date-range" label="Date Range" value={{ from: form.date_from, to: form.date_to }} onChange={({ from, to }) => setForm((current) => ({ ...current, date_from: from, date_to: to }))} />}
                <FloatingSelect id="aws-summary-pa" label="Protected Area" size="sm" value={form.protected_area_id} onChange={setField('protected_area_id')}><option value="">All Protected Areas</option>{protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect>
                <div className="flex flex-wrap gap-2"><button type="button" onClick={apply} className="rounded-xl bg-green-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-green-800">Apply Filters</button><button type="button" onClick={reset} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200">Reset</button>{canImport && <button type="button" onClick={onImport} className="rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-blue-700">Import AWS Data</button>}</div>
            </div>
            <div className="mt-3 flex flex-wrap gap-2"><button ref={exportButtonRef} type="button" onClick={() => setExportModalOpen(true)} aria-haspopup="dialog" aria-expanded={exportModalOpen} className="inline-flex items-center gap-2 rounded-xl bg-green-700 px-4 py-2.5 text-xs font-bold text-white hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2">Export</button></div>
            </div>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {[['Observations', observationCount.toLocaleString()], ['Data Completeness', completeness], ['Total Precipitation', totalPrecipitation === 0 && rows.every((row) => row.total_precipitation === null || row.total_precipitation === undefined) ? emptyMark : totalPrecipitation.toFixed(2) + ' mm'], ['Interpretable Weather Days', interpretableDays.toLocaleString()]].map(([label, value]) => <div key={label} className="rounded-2xl border border-gray-100 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900"><span className="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</span><span className="mt-1 block text-lg font-bold text-green-900 dark:text-green-300">{value}</span></div>)}
            </div>

        {groupEntries.map((group) => <section key={String(group.name || 'unknown')} className="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-bold text-green-900 dark:border-gray-800 dark:text-green-300">{blank(group.name)}</h3>
            <table className="min-w-[1700px] w-full text-left text-xs"><thead className="bg-green-900 text-white"><tr>{headers.map((heading, index) => <th key={heading} className="px-3 py-3 font-bold">{index === 0 ? (form.mode === 'one_month' ? 'Reporting Date' : 'Reporting Period') : heading}</th>)}</tr></thead><tbody className="divide-y divide-gray-200 dark:divide-gray-800">{group.rows.map((row) => <tr key={String(row.protected_area_id) + '-' + row.period} className="align-top hover:bg-green-50/60 dark:hover:bg-green-950/30">{rowValues(row).map((value, index) => <td key={row.period + '-' + index} className={'px-3 py-3 ' + (index === rowValues(row).length - 1 ? 'min-w-[220px]' : '')}>{index === rowValues(row).length - 1 ? <AwsWeatherRemarkBadge remark={row.remarks} /> : value}</td>)}</tr>)}</tbody></table>
        </section>)}
        {groupEntries.length === 0 && <div className="rounded-2xl border border-gray-100 bg-white px-6 py-16 text-center text-sm text-gray-500 shadow-sm dark:border-gray-800 dark:bg-gray-900">No AWS observations found for the selected period.</div>}
        <AwsSummaryExportModal open={exportModalOpen} onClose={() => setExportModalOpen(false)} onExport={exportFile} returnFocusRef={exportButtonRef} summaryType={summaryType} reportingPeriod={reportingPeriod} protectedArea={selectedProtectedArea} />
    </div>;
}
