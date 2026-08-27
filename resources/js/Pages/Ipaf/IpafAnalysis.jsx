import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { router } from '@inertiajs/react';
import Card from '@/Components/Card';
import { formatMoney } from '@/Utils/moneyFormatters';
import FloatingSelect from '@/Components/Form/FloatingSelect';

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const numeric = value => Number(value || 0);
const axisMoney = value => new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(numeric(value));
const tooltipMoney = value => `₱${formatMoney(numeric(value))}`;

function EmptyChart({ message }) {
    return <div className="flex h-72 items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 px-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-400">{message}</div>;
}

function ChartCard({ title, subtitle, children }) {
    return <Card className="p-5"><div className="mb-4"><h3 className="text-sm font-extrabold text-gray-900 dark:text-white">{title}</h3><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p></div>{children}</Card>;
}

const commonAxis = { tick: { fill: '#6b7280', fontSize: 11 }, tickLine: false, axisLine: false };

export default function IpafAnalysis({ analysis = {}, protectedAreas = [], filters = {} }) {
    const monthly = (analysis.monthly_revenue || []).map(row => ({ month: months[Number(row.month) - 1], total: numeric(row.total_collected) }));
    const quarterly = (analysis.quarterly_performance || []).map(row => ({ quarter: row.quarter, target: row.target === null ? null : numeric(row.target), collected: numeric(row.total_collected) }));
    const balances = (analysis.bank_balances || []).map(row => ({ name: row.protected_area_name, balance: numeric(row.bank_balance) }));
    const apply = changes => router.get(route('ipaf.index'), { ...filters, ipaf_tab: 'analysis', ...changes }, { preserveState: true, preserveScroll: true, replace: true });

    return <section className="mt-5 space-y-4">
        <div><p className="text-xs font-bold uppercase tracking-widest text-green-700 dark:text-green-400">IPAF Analysis</p><h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">Revenue, Performance, and Accounting Trends</h2><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Read-only server-aggregated analysis for the selected reporting year and Protected Area.</p></div>
        <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><div className="grid gap-3 sm:grid-cols-2 lg:max-w-2xl"><FloatingSelect id="analysis-year" label="Reporting Year" size="sm" value={analysis.year || ''} onChange={event => apply({ analysis_year: event.target.value })}>{(analysis.years || []).map(year => <option key={year} value={year}>{year}</option>)}</FloatingSelect><FloatingSelect id="analysis-pa" label="Protected Area" size="sm" value={filters.analysis_protected_area_id || ''} onChange={event => apply({ analysis_protected_area_id: event.target.value })}><option value="">All Protected Areas</option>{protectedAreas.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect></div></div>
        <div className="grid gap-4 xl:grid-cols-2">
            <ChartCard title="Monthly Revenue Collection" subtitle="Total collected from January through December">
                {!analysis.has_monthly_revenue ? <EmptyChart message="No revenue data available for the selected period."/> : <div className="h-72"><ResponsiveContainer width="100%" height="100%"><LineChart data={monthly} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}><CartesianGrid strokeDasharray="3 3" stroke="#d1d5db" vertical={false}/><XAxis dataKey="month" {...commonAxis}/><YAxis tickFormatter={axisMoney} width={58} {...commonAxis}/><Tooltip formatter={value => [tooltipMoney(value), 'Total Collected']} contentStyle={{ borderRadius: 12, borderColor: '#d1d5db' }}/><Line type="monotone" dataKey="total" name="Total Collected" stroke="#15803d" strokeWidth={3} dot={{ r: 3 }} activeDot={{ r: 5 }}/></LineChart></ResponsiveContainer></div>}
            </ChartCard>
            <ChartCard title="Quarterly Target vs Total Collected" subtitle="Target and actual collection by quarter">
                {!analysis.has_quarterly_performance ? <EmptyChart message="No quarterly target or revenue data is available for the selected period."/> : <div className="h-72"><ResponsiveContainer width="100%" height="100%"><BarChart data={quarterly} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}><CartesianGrid strokeDasharray="3 3" stroke="#d1d5db" vertical={false}/><XAxis dataKey="quarter" {...commonAxis}/><YAxis tickFormatter={axisMoney} width={58} {...commonAxis}/><Tooltip formatter={value => tooltipMoney(value)} contentStyle={{ borderRadius: 12, borderColor: '#d1d5db' }}/><Legend/><Bar dataKey="target" name="Target" fill="#0f766e" radius={[5, 5, 0, 0]}/><Bar dataKey="collected" name="Total Collected" fill="#65a30d" radius={[5, 5, 0, 0]}/></BarChart></ResponsiveContainer></div>}
            </ChartCard>
        </div>
        <ChartCard title="Bank Balance by Protected Area" subtitle="Persisted Accounting Section bank balances">
            {balances.length === 0 ? <EmptyChart message="No Accounting Section bank-balance data is available for the selected period."/> : <div className="h-80"><ResponsiveContainer width="100%" height="100%"><BarChart data={balances} margin={{ top: 8, right: 12, left: 4, bottom: 50 }}><CartesianGrid strokeDasharray="3 3" stroke="#d1d5db" vertical={false}/><XAxis dataKey="name" angle={-25} textAnchor="end" interval={0} height={76} {...commonAxis}/><YAxis tickFormatter={axisMoney} width={64} {...commonAxis}/><Tooltip formatter={value => [tooltipMoney(value), 'Bank Balance']} contentStyle={{ borderRadius: 12, borderColor: '#d1d5db' }}/><Bar dataKey="balance" name="Bank Balance" fill="#047857" radius={[6, 6, 0, 0]}/></BarChart></ResponsiveContainer></div>}
        </ChartCard>
    </section>;
}
