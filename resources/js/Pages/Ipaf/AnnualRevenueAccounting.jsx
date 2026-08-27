import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import CrudTable from '@/Components/Crud/CrudTable';
import { formatMoney } from '@/Utils/moneyFormatters';

const peso = value => value === null || value === undefined ? '—' : `₱${formatMoney(value)}`;
const percent = value => value === null || value === undefined ? '—' : `${value}%`;
const areaName = row => <span className={row.is_total ? 'font-extrabold text-green-900 dark:text-green-300' : 'font-semibold'}>{row.protected_area_name}</span>;

export default function AnnualRevenueAccounting({ annual = {} }) {
    const rows = [...(annual.rows || []), { protected_area_id: 'annual-total', protected_area_name: 'PENRO DAVAO ORIENTAL TOTAL', annual_target: annual.totals?.annual_target, annual_total_collected: annual.totals?.annual_total_collected, percentage_accomplishment: annual.totals?.percentage_accomplishment, is_total: true }];
    const columns = [
        { key: 'protected_area_name', label: 'Name of PA', render: areaName },
        { key: 'annual_target', label: 'Annual Target', render: row => <span className={row.is_total ? 'font-extrabold' : ''}>{peso(row.annual_target)}</span> },
        { key: 'annual_total_collected', label: 'Annual Total Collected', render: row => <span className={row.is_total ? 'font-extrabold' : ''}>{peso(row.annual_total_collected)}</span> },
        { key: 'percentage_accomplishment', label: '% Accomplishment', render: row => <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${row.percentage_accomplishment === null || row.percentage_accomplishment === undefined ? 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300'}`}>{percent(row.percentage_accomplishment)}</span> },
    ];
    return <section className="space-y-4 border-t border-gray-200 pt-7 dark:border-gray-800">
        <div><p className="text-xs font-bold uppercase tracking-widest text-green-700 dark:text-green-400">Annual Performance</p><h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">Annual Target and Accomplishment</h2><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Derived from Q1–Q4 targets and all January–December collections for {annual.year}.</p></div>
        <CrudSummaryGrid items={[{ label: 'Annual Target', value: peso(annual.totals?.annual_target) }, { label: 'Annual Total Collected', value: peso(annual.totals?.annual_total_collected) }, { label: 'Overall Accomplishment', value: percent(annual.totals?.percentage_accomplishment) }]} columns={3}/>
        <CrudTable title="Annual Revenue Performance" subtitle={`Reporting Year ${annual.year}`} columns={columns} rows={rows} rowKey="protected_area_id" />
    </section>;
}
