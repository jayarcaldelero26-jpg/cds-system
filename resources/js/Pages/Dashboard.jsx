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
    bmsRecordsCount = 0,
    bamsRecordsCount = 0,
    newSpeciesCount = 0,
    bmsThreatsCount = 0,
    imeaAssessmentsCount = 0,
    totalFacilitiesCount = 0,
    functionalFacilitiesCount = 0,
    awsCount = 0,
    dbActivities = [],
    semester1Count = 0,
    semester2Count = 0
}) {
    const { auth } = usePage().props;

    const totalManagedPlans = activeManagementPlansCount + expiredManagementPlansCount + plansForUpdatingCount;
    const activePercent = totalManagedPlans > 0 ? Math.round((activeManagementPlansCount / totalManagedPlans) * 100) : 0;
    const expiredPercent = totalManagedPlans > 0 ? Math.round((expiredManagementPlansCount / totalManagedPlans) * 100) : 0;
    const updatingPercent = totalManagedPlans > 0 ? Math.round((plansForUpdatingCount / totalManagedPlans) * 100) : 0;

    const maxSemesterCount = Math.max(semester1Count, semester2Count, 1);
    const sem1HeightPercent = Math.max(Math.round((semester1Count / maxSemesterCount) * 75), 15);
    const sem2HeightPercent = Math.max(Math.round((semester2Count / maxSemesterCount) * 75), 15);

    const columns = [
        {
            key: 'activity',
            label: 'Activity',
            render: (row) => <span className="font-semibold text-gray-900 dark:text-white">{row.activity}</span>
        },
        {
            key: 'module',
            label: 'Module',
            render: (row) => {
                let badgeColor = 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
                if (row.module === 'BMS') badgeColor = 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300';
                else if (row.module === 'BAMS') badgeColor = 'bg-purple-50 text-purple-700 border border-purple-200 dark:bg-purple-950/50 dark:text-purple-300';
                else if (row.module === 'IMEA') badgeColor = 'bg-cyan-50 text-cyan-700 border border-cyan-200 dark:bg-cyan-950/50 dark:text-cyan-300';
                else if (row.module === 'Management Plans') badgeColor = 'bg-blue-50 text-blue-700 border border-blue-200 dark:bg-blue-950/50 dark:text-blue-300';
                else if (row.module === 'AWS') badgeColor = 'bg-sky-50 text-sky-700 border border-sky-200 dark:bg-sky-950/50 dark:text-sky-300';

                return (
                    <span className={`text-[11px] font-bold px-2.5 py-1 rounded-md ${badgeColor}`}>
                        {row.module}
                    </span>
                );
            }
        },
        {
            key: 'date',
            label: 'Date',
            cellClassName: 'text-gray-500 text-sm',
            render: (row) => <span className="text-xs">{row.date}</span>
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge variant={row.status === 'Completed' ? 'active' : 'pending'}>{row.status}</StatusBadge>
        }
    ];

    return (
        <AuthenticatedLayout title="Consolidated CDS Dashboard">

            <div className="relative z-0 pb-3 pt-2">
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-emerald-600 via-teal-700 to-cyan-800 p-6 text-white shadow-xl">
                    <div className="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-white/15 pointer-events-none"></div>

                    <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-emerald-100 opacity-90">PENRO Davao Oriental</p>
                            <h1 className="mt-1 text-xl sm:text-2xl font-extrabold tracking-tight text-white">PENRO Davao Oriental - CDS Dashboard</h1>
                            <p className="mt-1 max-w-3xl text-xs sm:text-sm text-emerald-100 opacity-90">
                                Unified monitoring overview for Protected Areas, Management Plans, BMS, BAMS, IMEA, AWS, and Infrastructure Inventories.
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
                <Card className="border-l-4 border-l-emerald-600 bg-white dark:bg-gray-800 shadow-sm rounded-2xl">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Welcome back, Conservation Development Section 👋</p>
                            <h2 className="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">Conservation Development Section IMS</h2>
                            <p className="mt-1 max-w-2xl text-xs sm:text-sm text-gray-600 dark:text-gray-300">
                                Real-time consolidated tracking across biodiversity monitoring systems, permanent plots, automated weather stations, and management plans.
                            </p>
                        </div>
                        <span className="inline-flex w-fit items-center gap-1.5 rounded-lg bg-gray-50 dark:bg-gray-900 px-3 py-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-300 shadow-xs border border-emerald-200 dark:border-emerald-800">
                            <span className="h-2 w-2 rounded-full bg-emerald-500" />
                            PENRO Davao Oriental
                        </span>
                    </div>
                </Card>

                {/* STATISTICS CARDS GRID */}
                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Consolidated system statistics">

                    {/* Protected Areas */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-600 to-green-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">🌲</span>
                            <span className="text-3xl font-extrabold tracking-tight">{protectedAreasCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-emerald-100">Protected Areas</h4>
                        </div>
                    </div>

                    {/* Management Plans */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">📄</span>
                            <span className="text-3xl font-extrabold tracking-tight">{activeManagementPlansCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-blue-100">Management Plans</h4>
                        </div>
                    </div>

                    {/* BMS Records */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">🐾</span>
                            <span className="text-3xl font-extrabold tracking-tight">{bmsRecordsCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-3">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-teal-100">BMS Records</h4>
                            {newSpeciesCount > 0 && (
                                <Link href={route('bms.index')} className="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-amber-500/30 border border-amber-400/40 px-2 py-0.5 text-[11px] font-medium text-amber-100 hover:bg-amber-500/40 transition">
                                    <span className="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                    <span>{newSpeciesCount} new species detected →</span>
                                </Link>
                            )}
                        </div>
                    </div>

                    {/* BAMS Records */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-purple-600 to-fuchsia-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">📊</span>
                            <span className="text-3xl font-extrabold tracking-tight">{bamsRecordsCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-purple-100">BAMS Records</h4>
                        </div>
                    </div>

                    {/* BMS Threats */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-rose-600 to-pink-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">⚠️</span>
                            <span className="text-3xl font-extrabold tracking-tight">{bmsThreatsCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-rose-100">BMS Threats</h4>
                        </div>
                    </div>

                    {/* IMEA Assessments */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-cyan-600 to-blue-900 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">📈</span>
                            <span className="text-3xl font-extrabold tracking-tight">{imeaAssessmentsCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-cyan-100">IMEA Assessments</h4>
                        </div>
                    </div>

                    {/* Facilities Inventory */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-500 to-rose-700 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">🏗️</span>
                            <span className="text-3xl font-extrabold tracking-tight">{totalFacilitiesCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-amber-100">Facilities Inventory</h4>
                        </div>
                    </div>

                    {/* AWS (Automated Weather Station) */}
                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-800 p-5 text-white shadow-md flex flex-col justify-between min-h-[135px] hover:scale-[1.01] transition-transform">
                        <div className="absolute -right-6 -top-6 h-28 w-28 rounded-full bg-white/10 pointer-events-none"></div>
                        <div className="relative z-10 flex items-center justify-between">
                            <span className="text-2xl">🌤️</span>
                            <span className="text-3xl font-extrabold tracking-tight">{awsCount}</span>
                        </div>
                        <div className="relative z-10 mt-auto pt-4">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-sky-100">Automated Weather Stations</h4>
                        </div>
                    </div>

                </section>

                {/* 📊 VISUAL ANALYTICS BREAKDOWN & SEMESTRAL BMS TRENDS GRAPH SECTION */}
                <section className="grid gap-6 lg:grid-cols-2">

                    {/* Management Plans Health */}
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">
                        <div className="flex items-center justify-between mb-5">
                            <div>
                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">Management Plans Health</h2>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Real-time status proportion across registered protected areas.</p>
                            </div>
                            <Link href="/management-plans/summary" className="text-xs font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-800 transition">Full Report →</Link>
                        </div>

                        <div className="space-y-4 bg-gray-50/50 dark:bg-gray-900/30 p-4 rounded-2xl border border-gray-100 dark:border-gray-800">
                            <div>
                                <div className="flex justify-between text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-300">
                                    <span>🟢 Active / Approved ({activeManagementPlansCount})</span>
                                    <span className="font-mono text-emerald-600">{activePercent}%</span>
                                </div>
                                <div className="h-3 w-full bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">
                                    <div className="h-full bg-emerald-600 rounded-full transition-all duration-500 shadow-sm" style={{ width: `${activePercent}%` }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex justify-between text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-300">
                                    <span>🔄 For Updating ({plansForUpdatingCount})</span>
                                    <span className="font-mono text-amber-600">{updatingPercent}%</span>
                                </div>
                                <div className="h-3 w-full bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">
                                    <div className="h-full bg-amber-500 rounded-full transition-all duration-500 shadow-sm" style={{ width: `${updatingPercent}%` }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex justify-between text-xs font-bold mb-1.5 text-gray-700 dark:text-gray-300">
                                    <span>🔴 Expired Plans ({expiredManagementPlansCount})</span>
                                    <span className="font-mono text-rose-600">{expiredPercent}%</span>
                                </div>
                                <div className="h-3 w-full bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">
                                    <div className="h-full bg-rose-600 rounded-full transition-all duration-500 shadow-sm" style={{ width: `${expiredPercent}%` }} />
                                </div>
                            </div>
                        </div>
                    </Card>

                    {/* Semestral Biodiversity Monitoring (BMS) Trends */}
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">Semestral Biodiversity Monitoring (BMS) Trends</h2>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Visual overview of wildlife & habitat sightings per semester.</p>
                            </div>
                            <Link href="/bms" className="text-xs font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-800 transition">View BMS →</Link>
                        </div>

                        <div className="mt-4">
                            <div className="flex items-end justify-around h-44 gap-6 px-4 bg-gray-50 dark:bg-gray-900/40 rounded-2xl p-4 border border-gray-100 dark:border-gray-800">
                                <div className="flex flex-col items-center justify-end gap-2 w-1/2 h-full">
                                    <span className="text-[11px] font-bold text-gray-500">Semester 1</span>
                                    <div className="w-full bg-teal-500/20 dark:bg-teal-500/30 rounded-t-xl flex items-center justify-center pb-2 transition-all" style={{ height: `${sem1HeightPercent}%` }}>
                                        <span className="text-xs font-bold text-teal-700 dark:text-teal-300 whitespace-nowrap">{semester1Count} Rec</span>
                                    </div>
                                    <span className="text-[10px] text-gray-400 font-medium">Jan - Jun</span>
                                </div>
                                <div className="flex flex-col items-center justify-end gap-2 w-1/2 h-full">
                                    <span className="text-[11px] font-bold text-gray-500">Semester 2</span>
                                    <div className="w-full bg-emerald-600 rounded-t-xl flex items-center justify-center pb-2 transition-all shadow-sm" style={{ height: `${sem2HeightPercent}%` }}>
                                        <span className="text-xs font-bold text-white whitespace-nowrap">{semester2Count} Rec</span>
                                    </div>
                                    <span className="text-[10px] text-gray-400 font-medium">Jul - Dec</span>
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

                    {/* IMEA & Infrastructure Status */}
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">IMEA & Infrastructure Status</h2>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Ecotourism impact assessments & facilities inventory.</p>
                            </div>
                            <Link href="/imea" className="text-xs font-bold text-emerald-700 hover:text-emerald-900 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 px-3 py-1.5 rounded-xl border border-emerald-200 dark:border-emerald-800 transition">View IMEA →</Link>
                        </div>

                        <div className="grid grid-cols-2 gap-4 mt-5">
                            <div className="bg-cyan-50/50 dark:bg-cyan-950/20 p-5 rounded-2xl border border-cyan-200/60 dark:border-cyan-800/50 text-center shadow-xs flex flex-col justify-center">
                                <span className="text-3xl font-black text-cyan-600 dark:text-cyan-400">{imeaAssessmentsCount}</span>
                                <h4 className="text-[11px] font-extrabold text-gray-600 dark:text-gray-300 mt-1 uppercase tracking-wider">IMEA Assessments</h4>
                            </div>
                            <div className="bg-blue-50/50 dark:bg-blue-950/20 p-5 rounded-2xl border border-blue-200/60 dark:border-blue-800/50 text-center shadow-xs flex flex-col justify-center">
                                <span className="text-3xl font-black text-blue-700 dark:text-blue-400">{totalFacilitiesCount}</span>
                                <h4 className="text-[11px] font-extrabold text-gray-600 dark:text-gray-300 mt-1 uppercase tracking-wider">Total Facilities</h4>
                                <p className="text-[11px] font-semibold text-gray-500 mt-0.5">{functionalFacilitiesCount} Functional Units</p>
                            </div>
                        </div>
                    </Card>

                    {/* Quick Module Navigation */}
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl p-6">
                        <h2 className="font-extrabold text-gray-950 dark:text-white text-base">Quick Module Navigation</h2>
                        <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Direct access to core database sections.</p>

                        <div className="mt-4 grid gap-3 text-xs font-bold">
                            <Link href="/protected-areas" className="rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 p-3.5 border border-emerald-200 dark:border-emerald-800/60 flex justify-between items-center transition hover:bg-emerald-100/80 shadow-xs">
                                <span className="flex items-center gap-2">🌲 Protected Areas Registry</span>
                                <span className="bg-white dark:bg-emerald-900 w-6 h-6 rounded-full flex items-center justify-center text-emerald-700 dark:text-emerald-200 shadow-xs">→</span>
                            </Link>
                            <Link href="/management-plans" className="rounded-xl bg-blue-50/70 dark:bg-blue-950/30 text-blue-800 dark:text-blue-300 p-3.5 border border-blue-200 dark:border-blue-800/60 flex justify-between items-center transition hover:bg-blue-100/80 shadow-xs">
                                <span className="flex items-center gap-2">📄 Management Plans</span>
                                <span className="bg-white dark:bg-blue-900 w-6 h-6 rounded-full flex items-center justify-center text-blue-700 dark:text-blue-200 shadow-xs">→</span>
                            </Link>
                            <Link href="/bms" className="rounded-xl bg-teal-50/70 dark:bg-teal-950/30 text-teal-800 dark:text-teal-300 p-3.5 border border-teal-200 dark:border-teal-800/60 flex justify-between items-center transition hover:bg-teal-100/80 shadow-xs">
                                <span className="flex items-center gap-2">🐾 Biodiversity Monitoring (BMS)</span>
                                <span className="bg-white dark:bg-teal-900 w-6 h-6 rounded-full flex items-center justify-center text-teal-700 dark:text-teal-200 shadow-xs">→</span>
                            </Link>
                        </div>
                    </Card>
                </section>

                {/* RECENT ACTIVITIES */}
                <section>
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl" padding="p-0">
                        <div className="flex items-center justify-between border-b border-gray-200 px-6 py-5 dark:border-gray-700">
                            <div>
                                <h2 className="font-extrabold text-gray-950 dark:text-white text-base">Recent System Activities</h2>
                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Latest actions across BMS, BAMS, IMEA, AWS, and Management Plans.</p>
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
