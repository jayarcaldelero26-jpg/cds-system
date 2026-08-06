import { Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import Card from '../Components/Card';
import DataTable from '../Components/DataTable';
import PageHeader from '../Components/PageHeader';
import StatusBadge from '../Components/StatusBadge';
import StatCard from '../Components/StatCard';

export default function Dashboard({
    protectedAreasCount = 0,
    activeManagementPlansCount = 0,
    expiredManagementPlansCount = 0,
    plansForUpdatingCount = 0,
    bmsRecordsCount = 0,
    bamsRecordsCount = 0,
    bmsThreatsCount = 0,
    imeaAssessmentsCount = 0,
    totalFacilitiesCount = 0,
    functionalFacilitiesCount = 0,
    dbActivities = []
}) {
    const { auth } = usePage().props;

    const totalManagedPlans = activeManagementPlansCount + expiredManagementPlansCount + plansForUpdatingCount;
    const activePercent = totalManagedPlans > 0 ? Math.round((activeManagementPlansCount / totalManagedPlans) * 100) : 0;
    const expiredPercent = totalManagedPlans > 0 ? Math.round((expiredManagementPlansCount / totalManagedPlans) * 100) : 0;
    const updatingPercent = totalManagedPlans > 0 ? Math.round((plansForUpdatingCount / totalManagedPlans) * 100) : 0;

    const statistics = [
        { label: 'Protected Areas', value: protectedAreasCount, icon: '🌲', color: 'bg-gradient-to-br from-green-600 to-green-800' },
        { label: 'Management Plans', value: activeManagementPlansCount, icon: '📄', color: 'bg-gradient-to-br from-blue-600 to-blue-800' },
        { label: 'BMS Records', value: bmsRecordsCount, icon: '🐾', color: 'bg-gradient-to-br from-emerald-600 to-teal-800' },
        { label: 'BAMS Plots', value: bamsRecordsCount, icon: '📊', color: 'bg-gradient-to-br from-purple-600 to-indigo-800' },
        { label: 'BMS Threats', value: bmsThreatsCount, icon: '⚠️', color: 'bg-gradient-to-br from-rose-600 to-red-800' },
        { label: 'IMEA Assessments', value: imeaAssessmentsCount, icon: '📈', color: 'bg-gradient-to-br from-cyan-600 to-blue-800' },
        { label: 'Facilities Inventory', value: totalFacilitiesCount, icon: '🏗️', color: 'bg-gradient-to-br from-amber-600 to-orange-700' },
    ];

    const columns = [
        { key: 'activity', label: 'Activity', render: (row) => <span className="font-semibold text-gray-900 dark:text-white">{row.activity}</span> },
        { key: 'module', label: 'Module', render: (row) => <span className="text-xs font-medium px-2.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">{row.module}</span> },
        { key: 'date', label: 'Date', cellClassName: 'text-gray-500 text-sm', render: (row) => <span>{row.date}</span> },
        { key: 'status', label: 'Status', render: (row) => <StatusBadge variant={row.status === 'Completed' ? 'active' : 'pending'}>{row.status}</StatusBadge> }
    ];

    return (
        <AuthenticatedLayout title="Consolidated CDS Dashboard">

            {/* STICKY / FROZEN HEADER NGA WALAY PUTI NGA LAPAS UG DILI MOTABON SA SEARCH */}
            <div className="sticky top-16 z-20 bg-transparent pb-3 pt-2">
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-xl">
                    <div className="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/15 pointer-events-none"></div>

                    <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-green-100 opacity-90">PENRO Davao Oriental</p>
                            <h1 className="mt-1 text-xl sm:text-2xl font-extrabold tracking-tight text-white">PENRO Davao Oriental - CDS Dashboard</h1>
                            <p className="mt-1 max-w-3xl text-xs sm:text-sm text-green-100 opacity-90">
                                Unified monitoring overview for Protected Areas, Management Plans, BMS, BAMS, IMEA, and Infrastructure Inventories.
                            </p>
                        </div>
                        <span className="inline-flex w-fit items-center gap-1.5 rounded-xl bg-white/10 backdrop-blur-md px-3.5 py-2 text-xs font-semibold text-white shadow-xs border border-white/20 whitespace-nowrap">
                            <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse" />
                            Live System
                        </span>
                    </div>
                </div>
            </div>

            <div className="space-y-6 mt-4">

                {/* WELCOME BANNER */}
                <Card className="border-l-4 border-l-green-700 bg-white dark:bg-gray-800 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-green-700 dark:text-green-400">Welcome back, Conservation Development Section 👋</p>
                            <h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">Conservation Development Section IMS</h2>
                            <p className="mt-1 max-w-2xl text-xs sm:text-sm text-gray-600 dark:text-gray-300">
                                Real-time consolidated tracking across biodiversity monitoring systems, permanent plots, ecotourism impact assessments, and management plans.
                            </p>
                        </div>
                        <span className="inline-flex w-fit items-center gap-1.5 rounded-lg bg-gray-50 dark:bg-gray-900 px-3 py-1.5 text-xs font-semibold text-green-700 dark:text-green-300 shadow-xs border border-green-200 dark:border-green-800">
                            <span className="h-2 w-2 rounded-full bg-emerald-500" />
                            PENRO Davao Oriental
                        </span>
                    </div>
                </Card>

                {/* STATISTICS CARDS GRID */}
                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Consolidated system statistics">
                    {statistics.map((statistic) => (
                        <StatCard
                            key={statistic.label}
                            title={statistic.label}
                            value={statistic.value}
                            icon={statistic.icon}
                            color={statistic.color}
                        />
                    ))}
                </section>

                {/* 📊 VISUAL ANALYTICS BREAKDOWN & SEMESTRAL BMS TRENDS GRAPH SECTION */}
                <section className="grid gap-6 lg:grid-cols-2">
                    {/* Management Plans Health */}
                    <Card className="border border-gray-100 dark:border-gray-800">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="font-bold text-gray-950 dark:text-white text-base">Management Plans Health</h2>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Real-time status proportion across registered protected areas.</p>
                            </div>
                            <Link href="/management-plans/summary" className="text-xs font-semibold text-green-700 hover:text-green-900 dark:text-green-400">Full Report →</Link>
                        </div>

                        <div className="space-y-4 mt-4">
                            <div>
                                <div className="flex justify-between text-xs font-semibold mb-1 text-gray-700 dark:text-gray-300">
                                    <span>🟢 Active / Approved ({activeManagementPlansCount})</span>
                                    <span>{activePercent}%</span>
                                </div>
                                <div className="h-3 w-full bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                                    <div className="h-full bg-emerald-600 rounded-full transition-all duration-500" style={{ width: `${activePercent}%` }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex justify-between text-xs font-semibold mb-1 text-gray-700 dark:text-gray-300">
                                    <span>🔄 For Updating ({plansForUpdatingCount})</span>
                                    <span>{updatingPercent}%</span>
                                </div>
                                <div className="h-3 w-full bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                                    <div className="h-full bg-amber-500 rounded-full transition-all duration-500" style={{ width: `${updatingPercent}%` }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex justify-between text-xs font-semibold mb-1 text-gray-700 dark:text-gray-300">
                                    <span>🔴 Expired Plans ({expiredManagementPlansCount})</span>
                                    <span>{expiredPercent}%</span>
                                </div>
                                <div className="h-3 w-full bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                                    <div className="h-full bg-rose-600 rounded-full transition-all duration-500" style={{ width: `${expiredPercent}%` }} />
                                </div>
                            </div>
                        </div>
                    </Card>

                    {/* 📈 SEMESTRAL BMS TRENDS GRAPH CARD */}
                    <Card className="border border-gray-100 dark:border-gray-800">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="font-bold text-gray-950 dark:text-white text-base">Semestral Biodiversity Monitoring (BMS) Trends</h2>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Visual overview of wildlife & habitat sightings per semester.</p>
                            </div>
                            <Link href="/bms" className="text-xs font-semibold text-green-700 hover:text-green-900 dark:text-green-400">View BMS →</Link>
                        </div>

                        <div className="mt-4 pt-2">
                            <div className="flex items-end justify-around h-36 gap-6 px-4 bg-gray-50 dark:bg-gray-900/40 rounded-xl p-4 border border-gray-100 dark:border-gray-800">
                                <div className="flex flex-col items-center gap-2 w-1/2">
                                    <span className="text-[11px] font-bold text-gray-500">Semester 1</span>
                                    <div className="w-full bg-emerald-500/30 rounded-t-lg h-24 flex items-end justify-center pb-2">
                                        <span className="text-xs font-bold text-emerald-700 dark:text-emerald-400">18 Records</span>
                                    </div>
                                    <span className="text-[10px] text-gray-400">Jan - Jun</span>
                                </div>
                                <div className="flex flex-col items-center gap-2 w-1/2">
                                    <span className="text-[11px] font-bold text-gray-500">Semester 2</span>
                                    <div className="w-full bg-green-600 rounded-t-lg h-32 flex items-end justify-center pb-2">
                                        <span className="text-xs font-bold text-white">24 Records</span>
                                    </div>
                                    <span className="text-[10px] text-gray-400">Jul - Dec</span>
                                </div>
                            </div>
                            <div className="flex items-center justify-between mt-3 text-[11px] text-gray-500 px-1">
                                <span>Total Recorded Indices: <strong className="text-gray-900 dark:text-white">{bmsRecordsCount}</strong></span>
                                <span className="text-emerald-600 font-semibold">Semestral Monitoring Cycle</span>
                            </div>
                        </div>
                    </Card>
                </section>

                {/* IMEA & Facilities Overview Section */}
                <section className="grid gap-6 lg:grid-cols-2">
                    <Card className="border border-gray-100 dark:border-gray-800">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="font-bold text-gray-950 dark:text-white text-base">IMEA & Infrastructure Status</h2>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Ecotourism impact assessments & facilities inventory.</p>
                            </div>
                            <Link href="/imea" className="text-xs font-semibold text-green-700 hover:text-green-900 dark:text-green-400">View IMEA →</Link>
                        </div>

                        <div className="grid grid-cols-2 gap-4 mt-6">
                            <div className="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700 text-center">
                                <span className="text-2xl font-extrabold text-green-700 dark:text-green-400">{imeaAssessmentsCount}</span>
                                <h4 className="text-xs font-bold text-gray-600 dark:text-gray-300 mt-1 uppercase">IMEA Assessments</h4>
                            </div>
                            <div className="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700 text-center">
                                <span className="text-2xl font-extrabold text-blue-700 dark:text-blue-400">{totalFacilitiesCount}</span>
                                <h4 className="text-xs font-bold text-gray-600 dark:text-gray-300 mt-1 uppercase">Total Facilities</h4>
                                <p className="text-[11px] text-gray-500 mt-0.5">{functionalFacilitiesCount} Functional Units</p>
                            </div>
                        </div>
                    </Card>

                    {/* QUICK MODULE NAVIGATION */}
                    <Card className="border border-gray-100 dark:border-gray-800">
                        <h2 className="font-bold text-gray-950 dark:text-white">Quick Module Navigation</h2>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Direct access to core database sections.</p>
                        <div className="mt-4 grid gap-2.5 text-xs font-semibold">
                            <Link href="/protected-areas" className="rounded-xl bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-300 p-3 border border-green-200 dark:border-green-800 flex justify-between items-center transition hover:bg-green-100">
                                <span>🌲 Protected Areas Registry</span> <span>→</span>
                            </Link>
                            <Link href="/management-plans" className="rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 p-3 border border-blue-200 dark:border-blue-800 flex justify-between items-center transition hover:bg-blue-100">
                                <span>📄 Management Plans</span> <span>→</span>
                            </Link>
                            <Link href="/bms" className="rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 p-3 border border-emerald-200 dark:border-emerald-800 flex justify-between items-center transition hover:bg-emerald-100">
                                <span>🐾 Biodiversity Monitoring (BMS)</span> <span>→</span>
                            </Link>
                        </div>
                    </Card>
                </section>

                {/* RECENT ACTIVITIES */}
                <section>
                    <Card className="border border-gray-100 dark:border-gray-800" padding="p-0">
                        <div className="flex items-center justify-between border-b border-gray-200 px-6 py-5 dark:border-gray-700">
                            <div>
                                <h2 className="font-bold text-gray-950 dark:text-white">Recent System Activities</h2>
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Latest actions across BMS, BAMS, IMEA, and Management Plans.</p>
                            </div>
                        </div>

                        {dbActivities.length > 0 ? (
                            <DataTable columns={columns} rows={dbActivities} />
                        ) : (
                            <div className="p-8 text-center">
                                <h3 className="text-sm font-semibold text-gray-900 dark:text-white">No recent activities recorded yet</h3>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
                                    Quick access to core system modules:
                                </p>
                                <div className="mt-4 flex flex-wrap justify-center gap-2">
                                    <Link href="/protected-areas" className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition">Protected Areas</Link>
                                    <Link href="/management-plans" className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition">Management Plans</Link>
                                    <Link href="/bms" className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition">BMS / BAMS</Link>
                                    <Link href="/imea" className="rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-200 transition">IMEA</Link>
                                </div>
                            </div>
                        )}
                    </Card>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
