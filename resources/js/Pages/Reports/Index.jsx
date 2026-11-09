import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Button from '@/Components/Button';
import Card from '@/Components/Card';
import DatePicker from '@/Components/DatePicker';
import { FloatingSelect } from '@/Components/Form';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const EMPTY = {};
const groups = [
    { value: 'office', label: 'Office' },
    { value: 'pa', label: 'Protected Area' },
    { value: 'family', label: 'Report Family' },
];

function reportYearNow() {
    return Number(new Intl.DateTimeFormat('en', { year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date()));
}

export function makeFilterChips(filters, options) {
    const chips = [{ key: 'year', label: 'Reporting Year', value: String(filters.year ?? reportYearNow()) }];
    if (filters.period) chips.push({ key: 'period', label: 'Period', value: options.periods?.find(option => String(option.value) === String(filters.period))?.label || String(filters.period) });
    if (filters.domain && filters.domain !== 'all') chips.push({ key: 'domain', label: 'Domain', value: { pa: 'PA Monitoring', engp: 'ENGP Monitoring' }[filters.domain] || String(filters.domain) });
    if (filters.office) chips.push({ key: 'office', label: 'Office', value: String(filters.office) });
    if (filters.protected_area_id) chips.push({ key: 'protected_area_id', label: 'Protected Area', value: options.protected_areas?.find(area => String(area.id) === String(filters.protected_area_id))?.name || String(filters.protected_area_id) });
    if (filters.workflow) chips.push({ key: 'workflow', label: 'Report Family', value: options.families?.find(option => String(option.value) === String(filters.workflow))?.label || String(filters.workflow) });
    if (filters.status) chips.push({ key: 'status', label: 'Detail status', value: String(filters.status) });
    return chips;
}

export function reportFilterParams(values) {
    return {
        year: values.year || undefined,
        domain: values.domain || undefined,
        period: values.period || undefined,
        office: values.office || undefined,
        protected_area_id: values.protected_area_id || undefined,
        workflow: values.workflow || undefined,
        status: values.status || undefined,
        ...(values.as_of_date ? { as_of_date: values.as_of_date } : {}),
    };
}

function ReportScope({ report, canExport }) {
    const filters = report.filters || EMPTY;
    const options = report.filter_options || EMPTY;
    const { errors = EMPTY } = usePage().props;
    const [draft, setDraft] = useState(filters);
    useEffect(() => setDraft(filters), [filters]);

    const change = (name, value) => setDraft(current => ({ ...current, [name]: value }));
    const select = name => event => change(name, event.target.value);
    const changeDomain = event => {
        const domain = event.target.value;
        setDraft(current => ({ ...current, domain, period: '', office: '', protected_area_id: '', workflow: '' }));
    };
    const appliedScope = makeFilterChips(filters, options).filter(chip => !['year', 'period', 'domain'].includes(chip.key));
    const years = [...new Set([filters.year ?? reportYearNow(), ...(options.years || [])])].sort((a, b) => b - a);
    const draftYearApplied = String(draft.year ?? '') === String(filters.year ?? '');
    const draftDomainApplied = (draft.domain || 'all') === (filters.domain || 'all');
    const periods = draftYearApplied && draftDomainApplied ? options.periods || [] : [];
    const families = (options.families || []).filter(family => draft.domain === 'all' || family.domain === draft.domain);
    const apply = event => {
        event.preventDefault();
        router.get(route('reports.index'), reportFilterParams(draft), { preserveScroll: true, preserveState: true, replace: true });
    };

    return (
        <Card className="p-4 sm:p-5">
            <form onSubmit={apply} className="space-y-4">
                <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <FloatingSelect id="reports-year" label="Reporting year" value={draft.year ?? ''} error={errors.year}
                        onChange={event => setDraft(current => ({ ...current, year: event.target.value, period: '' }))}>
                        {years.map(year => <option key={year} value={year}>{year}</option>)}
                    </FloatingSelect>
                    <FloatingSelect id="reports-domain" label="Program" value={draft.domain || 'all'} onChange={changeDomain} error={errors.domain}>
                        <option value="all">PA and ENGP</option>
                        <option value="pa">Protected Areas</option>
                        <option value="engp">ENGP</option>
                    </FloatingSelect>
                    <FloatingSelect id="reports-period" label="Reporting period" value={draft.period || ''} onChange={select('period')} error={errors.period}>
                        <option value="">All periods</option>
                        {periods.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </FloatingSelect>
                    <DatePicker id="reports-as-of" label="As of date" value={draft.as_of_date || ''} maxDate={report.today}
                        onChange={value => change('as_of_date', value)} error={errors.as_of_date} />
                </div>
                {(!draftYearApplied || !draftDomainApplied) && <p className="text-xs text-gray-600 dark:text-gray-400">Apply the year and program to load their reporting periods.</p>}
                <details className="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <summary className="cursor-pointer text-sm font-semibold">Office, report family and detail filters</summary>
                    <div className="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <FloatingSelect id="reports-office" label="Office" value={draft.office || ''} onChange={select('office')} error={errors.office}>
                            <option value="">All authorized offices</option>
                            {(options.offices || []).map(office => <option key={office} value={office}>{office}</option>)}
                        </FloatingSelect>
                        {draft.domain !== 'engp' && <FloatingSelect id="reports-pa" label="Protected Area" value={draft.protected_area_id || ''} onChange={select('protected_area_id')} error={errors.protected_area_id}>
                            <option value="">All authorized areas</option>
                            {(options.protected_areas || []).map(area => <option key={area.id} value={area.id}>{area.name}</option>)}
                        </FloatingSelect>}
                        <FloatingSelect id="reports-family" label="Report family" value={draft.workflow || ''} onChange={select('workflow')} error={errors.workflow}>
                            <option value="">All report families</option>
                            {families.map(family => <option key={family.value} value={family.value}>{family.label}</option>)}
                        </FloatingSelect>
                        <FloatingSelect id="reports-status" label="Detail status" value={draft.status || ''} onChange={select('status')} error={errors.status}>
                            <option value="">All detail statuses</option>
                            {(options.statuses || []).map(status => <option key={status} value={status}>{status}</option>)}
                        </FloatingSelect>
                    </div>
                    <p className="mt-2 text-xs text-gray-600 dark:text-gray-400">Detail status narrows follow-up items and Excel details. The compliance totals use the full applied scope.</p>
                </details>
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit">Apply scope</Button>
                    <Button variant="secondary" onClick={() => router.get(route('reports.index'))}>Reset</Button>
                </div>
            </form>
            {appliedScope.length > 0 && <ul className="mt-3 flex flex-wrap gap-2 text-xs" aria-label="Applied detail filters">
                {appliedScope.map(chip => <li key={chip.key} className="rounded-lg bg-gray-100 px-2.5 py-1 dark:bg-gray-800">{chip.label} · {chip.value}</li>)}
            </ul>}
            {canExport && <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-800" aria-label="Export applied summary">
                <span className="mr-1 text-sm font-semibold">Export applied scope</span>
                {[['pdf', 'PDF summary'], ['docx', 'Word summary'], ['xlsx', 'Excel details']].map(([format, label]) => (
                    <a key={format} href={route('reports.export', { ...reportFilterParams(filters), format })}
                        data-cds-action="true" data-cds-action-variant="secondary" className="inline-flex min-h-10 items-center rounded-ui border border-gray-300 px-3 py-2 text-sm font-semibold dark:border-gray-700">{label}</a>
                ))}
            </div>}
        </Card>
    );
}

function ComplianceSummary({ report }) {
    const summary = report.summary || EMPTY;
    const comparison = report.comparison;
    const change = comparison?.change_pp;
    const facts = [
        ['Received on time', summary.on_time],
        ['Received late', summary.late],
        ['Awaiting PENRO receipt', summary.awaiting_receipt],
        ['No submission record', summary.missing],
        ['Submission evidence missing', summary.unverified],
    ];
    return (
        <Card className="p-4 sm:p-5">
            <h2 data-cds-reveal="heading" className="text-lg font-bold">Compliance summary</h2>
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{report.period_label} · {report.filters?.scope_label} · Through {report.filters?.as_of_date}</p>
            <p className="mt-3 text-xl font-bold">{summary.due > 0
                ? <>{summary.received} of {summary.due} due received <span className="text-green-700 dark:text-green-400">({summary.receipt_rate}%)</span></>
                : 'No requirements due by this cutoff'}</p>
            <dl className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {facts.map(([label, value]) => <div data-cds-reveal="card" key={label}><dt className="text-sm text-gray-600 dark:text-gray-400">{label}</dt><dd className="font-bold">{value ?? 0}</dd></div>)}
            </dl>
            <p className="mt-3 text-xs text-gray-600 dark:text-gray-400">
                {summary.not_due ?? 0} not yet due · {summary.no_deadline ?? 0} with an unavailable deadline. The due total uses registered requirements and dated receipts through the cutoff.
            </p>
            <div className="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                <h3 data-cds-reveal="heading" className="font-semibold">Previous period</h3>
                {comparison ? <p className="text-sm">
                    {comparison.period_label} through {comparison.as_of_date} · {comparison.received} of {comparison.due} due received
                    {comparison.receipt_rate != null && <> ({comparison.receipt_rate}%)</>}.
                    {change != null ? <> Change in receipt rate {change > 0 ? '+' : ''}{change} percentage points.</> : <> A rate comparison requires due requirements in both periods.</>}
                </p> : <p className="text-sm text-gray-600 dark:text-gray-400">No comparable scheduled period is available for this scope.</p>}
            </div>
        </Card>
    );
}

function Breakdown({ breakdowns = EMPTY, domain }) {
    const [selectedGroup, setSelectedGroup] = useState('office');
    const available = groups.filter(group => domain !== 'engp' || group.value !== 'pa');
    const group = available.some(option => option.value === selectedGroup) ? selectedGroup : 'office';
    const rows = breakdowns[group] || [];
    return (
        <Card className="min-w-0 p-4 sm:p-5">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 data-cds-reveal="heading" className="text-lg font-bold">Where compliance gaps are concentrated</h2>
                <div className="w-full sm:w-52">
                    <FloatingSelect id="reports-breakdown" label="Group by" value={group} onChange={event => setSelectedGroup(event.target.value)}>
                        {available.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </FloatingSelect>
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="cds-data-table w-full min-w-[34rem] text-left text-sm">
                    <thead><tr>{['Group', 'Due', 'Received', 'Receipt rate', 'Without receipt'].map(label => <th key={label} scope="col" className="px-3 py-2">{label}</th>)}</tr></thead>
                    <tbody>{rows.length ? rows.map(row => <tr key={row.label}>
                        <th scope="row" className="px-3 py-2 font-semibold">{row.label}</th><td className="px-3 py-2">{row.due}</td>
                        <td className="px-3 py-2">{row.received}</td><td className="px-3 py-2">{row.receipt_rate == null ? '—' : row.receipt_rate + '%'}</td>
                        <td className="px-3 py-2">{row.unresolved}</td>
                    </tr>) : <tr><td colSpan={5} className="px-3 py-3">No registered requirements for this scope.</td></tr>}</tbody>
                </table>
            </div>
        </Card>
    );
}

function FollowUps({ actions = [], count = 0, canExport }) {
    return (
        <Card className="p-4 sm:p-5">
            <h2 data-cds-reveal="heading" className="text-lg font-bold">Priority follow-up</h2>
            {count > actions.length && <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">Showing {actions.length} of {count} items, earliest deadlines first.{canExport && ' Excel contains the supporting details.'}</p>}
            {actions.length ? <ul className="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                {actions.map(row => <li data-cds-reveal="item" key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div className="min-w-0">
                        <h3 data-cds-reveal="heading" className="font-semibold">{row.report} · {row.period}</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-400">{row.scope} · {row.status}{row.due_date && <> · Due {row.due_date}</>}</p>
                        <p className="text-sm">{row.action_office && <strong>{row.action_office} · </strong>}{row.next_action}</p>
                    </div>
                    {row.tracking_url && <Link href={row.tracking_url} className="rounded-md px-2 py-1 text-sm font-semibold text-green-800 underline dark:text-green-300">Open in Tracking</Link>}
                </li>)}
            </ul> : <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">No follow-up items for the applied scope and detail filter.</p>}
        </Card>
    );
}

export default function Index({ report = EMPTY, canExport = false }) {
    return (
        <AuthenticatedLayout title="Reports">
            <main className="min-w-0 space-y-5 pb-6">
                <PageHeader title="Management Reports" description="Review compliance by reporting period, compare results, and see which items need follow-up." />
                <ReportScope report={report} canExport={canExport} />
                <ComplianceSummary report={report} />
                <Breakdown breakdowns={report.breakdowns} domain={report.filters?.domain} />
                <FollowUps actions={report.actions} count={report.action_count} canExport={canExport} />
            </main>
        </AuthenticatedLayout>
    );
}
