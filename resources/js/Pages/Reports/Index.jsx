import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Index({ stats }) {
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
    };

    const handlePrint = () => {
        window.print();
    };

    return (
        <AuthenticatedLayout title="Executive Reports">
            <div className="print:hidden">
                <PageHeader
                    title="Executive Summary & Reports"
                    description="Real-time consolidated information system metrics and active conservation indicators."
                    actions={
                        <button
                            type="button"
                            onClick={handlePrint}
                            className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900"
                        >
                            Print Summary Report
                        </button>
                    }
                />
            </div>

            {/* PRINT-ONLY HEADER */}
            <div className="hidden print:block text-center border-b-2 border-gray-800 pb-4 mb-6">
                <h1 className="text-xl font-bold uppercase text-gray-900">Republic of the Philippines</h1>
                <h2 className="text-lg font-semibold text-gray-800">Department of Environment and Natural Resources</h2>
                <p className="text-sm text-gray-600">PENRO Davao Oriental - Conservation Development Section (CDS)</p>
                <h3 className="text-md font-bold mt-4 uppercase tracking-wider text-green-800">eDATS EXECUTIVE REPORT</h3>
                <p className="text-xs text-gray-500 mt-1">Generated Date: {new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
            </div>

            <div className="mt-6 space-y-6">
                {/* 1. OVERVIEW SUMMARY STATS */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-xs">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Protected Areas</p>
                        <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{stats.protected_areas_count}</p>
                        <p className="text-xs text-green-600 mt-1">Registered Baselines</p>
                    </Card>

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-xs">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Management Plans</p>
                        <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{stats.management_plans_count}</p>
                        <p className="text-xs text-green-600 mt-1">Legally Bound Plans</p>
                    </Card>

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-xs">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Lawin Patrol Distance</p>
                        <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{stats.total_patrol_distance} km</p>
                        <p className="text-xs text-green-600 mt-1">Accumulated Patrol Tracks</p>
                    </Card>

                    <Card className="border border-gray-100 dark:border-gray-800 shadow-xs">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total PPA Budget</p>
                        <p className="mt-2 text-xl font-bold text-gray-900 dark:text-white font-mono">{formatCurrency(stats.total_ppa_budget)}</p>
                        <p className="text-xs text-green-600 mt-2">Active Capital Outlays</p>
                    </Card>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    {/* 2. PPA ALLOCATIONS */}
                    <Card title="Programs, Projects & Activities Budget Summary" className="shadow-xs">
                        {stats.ppa_by_category.length === 0 ? (
                            <p className="text-gray-500 italic text-sm">No PPA records registered to display budget metrics.</p>
                        ) : (
                            <div className="space-y-4 mt-2">
                                {stats.ppa_by_category.map((item) => (
                                    <div key={item.category} className="border-b border-gray-100 dark:border-gray-800 pb-3 last:border-0 last:pb-0">
                                        <div className="flex justify-between text-sm font-medium text-gray-800 dark:text-gray-200">
                                            <span>{item.category} ({item.count} items)</span>
                                            <span className="font-mono">{formatCurrency(item.total_budget)}</span>
                                        </div>
                                        {/* Progressive Bar */}
                                        <div className="w-full bg-gray-200 dark:bg-gray-800 h-2.5 rounded-full mt-2 overflow-hidden">
                                            <div
                                                className="bg-green-700 h-full rounded-full"
                                                style={{ width: `${Math.min(100, (item.total_budget / (stats.total_ppa_budget || 1)) * 100)}%` }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>

                    {/* 3. THREATS & ISSUES STATUS */}
                    <Card title="Reported Environmental Issues & Status" className="shadow-xs">
                        {stats.issues_by_status.length === 0 ? (
                            <p className="text-gray-500 italic text-sm">No environmental threat issues reported.</p>
                        ) : (
                            <div className="space-y-4 mt-2">
                                {stats.issues_by_status.map((item) => (
                                    <div key={item.status} className="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-3 last:border-0 last:pb-0">
                                        <span className="text-sm font-semibold text-gray-700 dark:text-gray-300">{item.status}</span>
                                        <span className="inline-flex items-center justify-center rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-800 dark:bg-green-950 dark:text-green-300">
                                            {item.count} reported
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>
                </div>

                {/* 4. DETAILS RECORD BREAKDOWN */}
                <Card title="Activity Log & System Records Summary" className="shadow-xs">
                    <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 text-sm">
                        <div className="p-4 border border-gray-100 dark:border-gray-800 rounded-lg">
                            <span className="block text-xs font-medium text-gray-500">Technical Reports (AWS)</span>
                            <span className="text-xl font-bold block mt-1">{stats.technical_reports_count}</span>
                        </div>
                        <div className="p-4 border border-gray-100 dark:border-gray-800 rounded-lg">
                            <span className="block text-xs font-medium text-gray-500">Ecotourism Impact Monitoring</span>
                            <span className="text-xl font-bold block mt-1">{stats.ecotourism_count}</span>
                        </div>
                        <div className="p-4 border border-gray-100 dark:border-gray-800 rounded-lg">
                            <span className="block text-xs font-medium text-gray-500">Lawin Patrol Operations</span>
                            <span className="text-xl font-bold block mt-1">{stats.lawin_count}</span>
                        </div>
                    </div>
                </Card>
            </div>

            {/* PRINT-ONLY SIGNATURE */}
            <div className="hidden print:block mt-16 text-right">
                <p className="text-sm text-gray-700">Prepared by:</p>
                <div className="mt-8 border-t border-gray-500 w-64 ml-auto pt-2 text-center">
                    <p className="font-bold text-sm text-gray-900">eDATS System Administrator</p>
                    <p className="text-xs text-gray-500">Conservation Development Section</p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
