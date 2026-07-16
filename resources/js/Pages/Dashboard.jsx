import { Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import Card from '../Components/Card';
import DataTable from '../Components/DataTable';
import PageHeader from '../Components/PageHeader';
import StatusBadge from '../Components/StatusBadge';

export default function Dashboard({
    protectedAreasCount = 0,
    activeManagementPlansCount = 0,
    expiredManagementPlansCount = 0,
    plansForUpdatingCount = 0,
    ppaCount = 0,
    issueCount = 0,
    lawinCount = 0,
    technicalReportsCount = 0,
    dbActivities = [] // Gidawat ang dynamic activities gikan sa backend
}) {
    const { auth } = usePage().props;

    const statistics = [
        { label: 'Protected Areas', value: protectedAreasCount, detail: 'Registered PA Baselines', icon: '🌲' },
        { label: 'Active Management Plans', value: activeManagementPlansCount, detail: 'Current approved plans', icon: '📄' },
        { label: 'Active PPA Projects', value: ppaCount, detail: 'Ongoing development works', icon: '📂' },
        { label: 'LAWIN Patrols', value: lawinCount, detail: 'Total patrols conducted', icon: '🛡️' },
        { label: 'Reported Threats/Issues', value: issueCount, detail: 'Environmental issues logged', icon: '⚠️' },
        { label: 'Technical Reports', value: technicalReportsCount, detail: 'Compiled AWS records', icon: '📝' },
        { label: 'Expired Plans', value: expiredManagementPlansCount, detail: 'Plans requiring review', icon: '🔴' },
        { label: 'Plans for Updating', value: plansForUpdatingCount, detail: 'Currently in update cycle', icon: '🔄' },
    ];

    const columns = [
        { key: 'activity', label: 'Activity', render: (row) => <span className="font-semibold text-gray-900 dark:text-white">{row.activity}</span> },
        { key: 'module', label: 'Module', render: (row) => <span className="text-xs font-medium px-2.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">{row.module}</span> },
        { key: 'date', label: 'Date', cellClassName: 'text-gray-500 text-sm', render: (row) => <span>{row.date}</span> },
        { key: 'status', label: 'Status', render: (row) => <StatusBadge variant={row.status === 'Completed' ? 'active' : 'pending'}>{row.status}</StatusBadge> }
    ];

    return (
        <AuthenticatedLayout title="Dashboard">
            <PageHeader title="Dashboard" description="Overview of CDS operations, active projects, and system metrics." />

            {/* WELCOME BANNER */}
            <Card className="mt-6 border-l-4 border-l-green-700 bg-gradient-to-r from-green-50/70 to-emerald-50/20 dark:from-green-950/20 dark:to-transparent">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm font-semibold text-green-800 dark:text-green-400">Welcome back, {auth.user?.name} 👋</p>
                        <h2 className="mt-1 text-xl font-bold text-gray-900 dark:text-white">Conservation Development Section IMS</h2>
                        <p className="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                            Monitor protected areas, track active budgets, manage lawin patrols, and access critical PENRO reports in a unified database.
                        </p>
                    </div>
                    <span className="inline-flex w-fit items-center gap-1.5 rounded-lg bg-white dark:bg-gray-900 px-3.5 py-2 text-xs font-semibold text-green-800 dark:text-green-300 shadow-xs border border-green-100 dark:border-green-900">
                        <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                        PENRO Davao Oriental
                    </span>
                </div>
            </Card>

            {/* METRICS GRID */}
            <section className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="System statistics">
                {statistics.map((statistic) => (
                    <Card key={statistic.label} padding="p-5" className="hover:shadow-md transition-all duration-200 border border-gray-100 dark:border-gray-800">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{statistic.label}</p>
                                <p className="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">{statistic.value}</p>
                                <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{statistic.detail}</p>
                            </div>
                            <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-green-50/80 dark:bg-green-950/50 text-xl shadow-xs" aria-hidden="true">
                                {statistic.icon}
                            </span>
                        </div>
                    </Card>
                ))}
            </section>

            <section className="mt-6 grid gap-6 xl:grid-cols-3">
                {/* RECENT ACTIVITIES (DYNAMIC NA!) */}
                <Card className="xl:col-span-2 border border-gray-100 dark:border-gray-800" padding="p-0">
                    <div className="flex items-center justify-between border-b border-gray-200 px-6 py-5 dark:border-gray-700">
                        <div>
                            <h2 className="font-bold text-gray-950 dark:text-white">Recent Activities</h2>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Latest system actions and data submissions.</p>
                        </div>
                        <Link href="/reports" className="text-sm font-semibold text-green-700 hover:text-green-900 dark:text-green-400">Analyze System</Link>
                    </div>
                    {/* Mogamit na og dbActivities nga gikan sa database */}
                    <DataTable columns={columns} rows={dbActivities} emptyTitle="No recent activities" emptyDescription="System activity will appear here once records are created." />
                </Card>

                {/* QUICK ACTIONS & SYSTEM INFO */}
                <div className="space-y-6">
                    <Card className="border border-gray-100 dark:border-gray-800">
                        <h2 className="font-bold text-gray-950 dark:text-white">Quick Tasks</h2>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Frequently used conservation tasks.</p>
                        <div className="mt-4 grid gap-2.5">
                            {/* 🛡️ LUWAS NA NGA PAG-CHECK: Ipakita lang ang Add Protected Area kon CDS Admin (canViewProtectedAreas ug canManageUsers) */}
                            {auth?.canViewProtectedAreas && auth?.canManageUsers ? (
                                <Link href="/protected-areas/create" className="rounded-lg bg-green-800 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm transition hover:bg-green-900">
                                    + Add Protected Area
                                </Link>
                            ) : null}

                            <Link href="/program-project-activities/create" className="rounded-lg border border-green-700 dark:border-green-800 text-green-800 dark:text-green-400 px-4 py-2.5 text-center text-sm font-semibold hover:bg-green-50/50 dark:hover:bg-green-950/20 transition">
                                📂 Record New PPA
                            </Link>
                            <Link href="/lawin-monitorings" className="rounded-lg border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2.5 text-center text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-850 transition">
                                🛡️ Track Lawin Patrols
                            </Link>
                        </div>
                    </Card>

                    <Card className="border border-gray-100 dark:border-gray-800">
                        <h2 className="font-bold text-gray-900 dark:text-white">Office Context</h2>
                        <dl className="mt-4 space-y-3.5 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt className="text-gray-500 dark:text-gray-400">Bureau</dt>
                                <dd className="text-right font-medium text-gray-800 dark:text-gray-200">DENR Region XI</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-gray-500 dark:text-gray-400">Office</dt>
                                <dd className="text-right font-medium text-gray-800 dark:text-gray-200">PENRO Davao Oriental</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-gray-500 dark:text-gray-400">Section</dt>
                                <dd className="text-right font-medium text-gray-800 dark:text-gray-200">CDS (Conservation Dev.)</dd>
                            </div>
                            <div className="flex justify-between gap-3 pt-2.5 border-t border-gray-100 dark:border-gray-800">
                                <dt className="text-gray-500 dark:text-gray-400">Status</dt>
                                <dd><StatusBadge variant="active">Operational</StatusBadge></dd>
                            </div>
                        </dl>
                    </Card>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
