import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import CrudTable from '@/Components/Crud/CrudTable';
import { formatMoney } from '@/Utils/moneyFormatters';
import AnnualRevenueAccounting from './AnnualRevenueAccounting';
import FloatingInput from '@/Components/Form/FloatingInput';
import FloatingSelect from '@/Components/Form/FloatingSelect';

const quarterMonths = { 1: 'January – March', 2: 'April – June', 3: 'July – September', 4: 'October – December' };
const money = value => value === null || value === undefined ? '—' : `₱${formatMoney(value)}`;
const targetMoney = money;
const percent = value => value === null || value === undefined ? '—' : `${value}%`;

export default function RevenueQuarterlySummary({ summary = {}, targets = {}, annual = {}, protectedAreas = [], filters = {} }) {
    const { auth = {} } = usePage().props;
    const canUpdate = Boolean(auth.canUpdateTechnicalReports);
    const [targetModalOpen, setTargetModalOpen] = useState(false);
    const form = useForm({
        protected_area_id: '',
        reporting_year: summary.year || new Date().getFullYear(),
        targets: { 1: '', 2: '', 3: '', 4: '' },
    });

    useEffect(() => {
        if (!targetModalOpen) form.setData('reporting_year', summary.year || new Date().getFullYear());
    }, [summary.year]);

    const apply = changes => router.get(route('ipaf.index'), { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const valuesFor = protectedAreaId => {
        const saved = targets?.[String(protectedAreaId)] || targets?.[protectedAreaId] || {};
        return { 1: saved['1'] ?? '', 2: saved['2'] ?? '', 3: saved['3'] ?? '', 4: saved['4'] ?? '' };
    };
    const openTargets = protectedAreaId => {
        form.clearErrors();
        form.setData({ protected_area_id: protectedAreaId || '', reporting_year: summary.year, targets: valuesFor(protectedAreaId) });
        setTargetModalOpen(true);
    };
    const closeTargets = () => {
        setTargetModalOpen(false);
        form.reset();
        form.clearErrors();
    };
    const selectArea = event => {
        const protectedAreaId = event.target.value;
        form.setData(data => ({ ...data, protected_area_id: protectedAreaId, targets: valuesFor(protectedAreaId) }));
    };
    const submitTargets = event => {
        event.preventDefault();
        const savedProtectedAreaId = form.data.protected_area_id;
        form.put(route('ipaf.revenue-targets.update'), {
            preserveScroll: true,
            onSuccess: () => {
                closeTargets();
                if (String(filters.summary_protected_area_id || '') !== String(savedProtectedAreaId)) {
                    router.get(route('ipaf.index'), {
                        ...filters,
                        summary_year: summary.year,
                        summary_quarter: summary.quarter,
                        summary_protected_area_id: savedProtectedAreaId,
                    }, { preserveState: true, preserveScroll: true, replace: true });
                }
            },
        });
    };

    const rows = [...(summary.rows || []), {
        protected_area_id: 'province-total',
        protected_area_name: 'PENRO DAVAO ORIENTAL TOTAL',
        target_amount: summary.totals?.target_amount,
        total_collected: summary.totals?.total_collected,
        percentage_accomplishment: summary.totals?.percentage_accomplishment,
        is_total: true,
    }];
    const columns = [
        { key: 'protected_area_name', label: 'Name of PA', render: row => <span className={row.is_total ? 'font-extrabold text-green-900 dark:text-green-300' : 'font-semibold'}>{row.protected_area_name}</span> },
        { key: 'target_amount', label: `Q${summary.quarter} Target`, render: row => <span className={row.is_total ? 'font-extrabold' : ''}>{targetMoney(row.target_amount)}</span> },
        { key: 'total_collected', label: 'Total Collected', render: row => <span className={row.is_total ? 'font-extrabold' : ''}>{money(row.total_collected)}</span> },
        { key: 'percentage_accomplishment', label: '% Accomplishment', render: row => <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${row.percentage_accomplishment === null || row.percentage_accomplishment === undefined ? 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300'}`}>{percent(row.percentage_accomplishment)}</span> },
    ];
    const filtersUi = <div className="flex flex-col gap-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <FloatingSelect id="summary-year" label="Reporting Year" size="sm" value={summary.year || ''} onChange={event => apply({ summary_year: event.target.value })}>{(summary.years || []).map(year => <option key={year} value={year}>{year}</option>)}</FloatingSelect>
            <FloatingSelect id="summary-pa" label="Protected Area" size="sm" value={filters.summary_protected_area_id || ''} onChange={event => apply({ summary_protected_area_id: event.target.value })}><option value="">All Protected Areas</option>{protectedAreas.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect>
        </div>
        <div className="flex flex-wrap gap-2" aria-label="Quarter selector">{[1, 2, 3, 4].map(quarter => <button key={quarter} type="button" onClick={() => apply({ summary_quarter: quarter })} className={`rounded-xl border px-4 py-2 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${Number(summary.quarter) === quarter ? 'border-green-700 bg-green-700 text-white shadow-sm' : 'border-gray-200 bg-white text-gray-700 hover:border-green-300 hover:bg-green-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'}`}>Q{quarter}<span className="ml-2 hidden font-medium opacity-80 sm:inline">{quarterMonths[quarter]}</span></button>)}</div>
    </div>;

    return <section className="mt-5 space-y-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-xs font-bold uppercase tracking-widest text-green-700 dark:text-green-400">Quarterly Performance</p><h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">Revenue Collection Target and Accomplishment</h2><p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Q{summary.quarter} {summary.year} · Aggregated from all monthly collections for {quarterMonths[summary.quarter]}.</p></div>{canUpdate && <button type="button" onClick={() => openTargets(filters.summary_protected_area_id || '')} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:opacity-50">Set Quarterly Targets</button>}</div>
        <CrudSummaryGrid items={[{ label: 'Total Target', value: money(summary.totals?.target_amount) }, { label: 'Total Collected', value: money(summary.totals?.total_collected) }, { label: 'Overall Accomplishment', value: percent(summary.totals?.percentage_accomplishment) }]} columns={3}/>
        <CrudTable title={`Q${summary.quarter} Subtotal`} subtitle={`${summary.year} · ${quarterMonths[summary.quarter]}`} columns={columns} rows={rows} rowKey="protected_area_id" filters={filtersUi} emptyTitle="No protected areas found" />
        <AnnualRevenueAccounting annual={annual}/>
        <CrudFormModal open={targetModalOpen} mode="edit" title="Set Quarterly Targets" subtitle={`Targets for reporting year ${summary.year}`} onClose={closeTargets} onSubmit={submitTargets} processing={form.processing} errors={form.errors} saveLabel="Save Targets" maxWidth="max-w-3xl">
            <CrudSection title="Protected Area & Reporting Year"><div className="grid gap-4 sm:grid-cols-2"><FloatingSelect id="target-pa" label="Protected Area" required value={form.data.protected_area_id} onChange={selectArea} error={form.errors.protected_area_id}><option value="">Select Protected Area</option>{protectedAreas.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</FloatingSelect><FloatingInput id="target-year" label="Reporting Year" value={form.data.reporting_year} readOnly /></div></CrudSection>
            <CrudSection title="Quarterly Targets"><div className="grid gap-4 sm:grid-cols-2">{[1, 2, 3, 4].map(quarter => <FloatingInput key={quarter} id={`quarter-target-${quarter}`} label={`Q${quarter} Target (${quarterMonths[quarter]})`} type="number" min="0" step="0.01" value={form.data.targets?.[quarter] ?? ''} onChange={event => form.setData('targets', { ...form.data.targets, [quarter]: event.target.value })} error={form.errors[`targets.${quarter}`]} />)}</div><p className="mt-3 text-xs text-gray-500 dark:text-gray-400">Leave a quarter blank to remove its saved target. A zero target is retained but produces no accomplishment percentage.</p></CrudSection>
        </CrudFormModal>
    </section>;
}
