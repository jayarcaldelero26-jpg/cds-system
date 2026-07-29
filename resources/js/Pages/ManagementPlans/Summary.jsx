import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';
import StatusBadge from '../../Components/StatusBadge';

export default function Summary({ protectedAreas, selectedArea, summaryData, filters }) {
    const [selectedPa, setSelectedPa] = useState(filters.protected_area_id || '');

    const handleProtectedAreaChange = (e) => {
        const paId = e.target.value;
        setSelectedPa(paId);
        router.get('/management-plans/summary', { protected_area_id: paId }, { preserveState: true, replace: true });
    };

    const handlePrint = () => {
        window.print();
    };

    const selectClass = 'mt-1.5 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

    return (
        <AuthenticatedLayout title="Management Plan Summary">
            {/* Print CSS to completely hide sidebar and header during print */}
            <style>{`
                @media print {
                    aside, header, nav, button, .print\\:hidden {
                        display: none !important;
                    }
                    .lg\\:pl-72 {
                        padding-left: 0 !important;
                        margin: 0 !important;
                    }
                    body, html {
                        background-color: white !important;
                        color: black !important;
                    }
                }
            `}</style>

            {/* PageHeader kay matago kon i-print */}
            <div className="print:hidden">
                <PageHeader
                    title="Management Plan Summary & Reports"
                    description="Generated summary of management plans per Protected Area based on system inputs."
                    actions={
                        <div className="flex gap-3">
                            <button
                                type="button"
                                onClick={handlePrint}
                                className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900"
                            >
                                🖨️ Print / Save PDF Report
                            </button>
                            <Link
                                href="/management-plans"
                                className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                            >
                                ← Back to Registry
                            </Link>
                        </div>
                    }
                />
            </div>

            {/* SELECTION CARD (Matago kon i-print) */}
            <Card className="mt-6 print:hidden">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">
                    Select Protected Area to Generate Summary Report
                </label>
                <select
                    value={selectedPa}
                    onChange={handleProtectedAreaChange}
                    className={selectClass}
                >
                    <option value="">-- All Protected Areas (Overall Summary) --</option>
                    {protectedAreas.map((area) => (
                        <option key={area.id} value={area.id}>
                            {area.name}
                        </option>
                    ))}
                </select>
            </Card>

            {/* PRINTABLE REPORT CONTAINER */}
            <div className="mt-6 space-y-6 print:mt-0 print:space-y-4">

                {/* 🏛️ PORMAL NGA OPISYAL NGA DENR HEADER (Mas dako nga Logo) */}
                <div className="hidden print:flex items-center justify-between border-b-2 border-[#592a0c] pb-4 mb-4 px-2">
                    {/* Left Logo (DENR) */}
                    <div className="flex-shrink-0">
                        <img src="/images/DENR LOGO.png" alt="DENR Logo" className="h-20 w-20 object-contain" />
                    </div>

                    {/* Center Text */}
                    <div className="text-center flex-1 mx-2">
                        <p className="text-[11px] font-medium text-gray-800 uppercase tracking-wide">Republic of the Philippines</p>
                        <h1 className="text-xs font-bold text-blue-800">Department of Environment and Natural Resources</h1>
                        <h2 className="text-xs font-bold text-green-700 uppercase">PROVINCIAL ENVIRONMENT AND NATURAL RESOURCES OFFICE</h2>
                        <p className="text-[10px] text-gray-600 uppercase">GOVERNMENT CENTER, DAHICAN, CITY OF MATI</p>
                        <p className="text-[10px] text-gray-600 uppercase">TEL #: 3883-275 | EMAIL ADD: PENRODAVAOORIENTAL@DENR.GOV.PH</p>
                    </div>

                    {/* Right Logo (Bagong Pilipinas) */}
                    <div className="flex-shrink-0">
                        <img src="/images/Bagong Pilipinas logo.png" alt="Bagong Pilipinas Logo" className="h-20 object-contain" />
                    </div>
                </div>

                <Card className="bg-white dark:bg-gray-800 border-l-4 border-l-green-800 print:border print:shadow-none print:p-4">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white print:text-base">
                        {selectedArea ? selectedArea.name : 'Overall Protected Areas Summary'}
                    </h2>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1 print:text-xs">
                        DENR PENRO Mati - Conservation Development Section (CDS)
                    </p>
                </Card>

                {/* METRICS CARDS */}
                <div className="grid gap-4 md:grid-cols-3 print:grid-cols-3 print:gap-2">
                    <Card className="bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900 print:border-gray-300 print:p-3">
                        <p className="text-xs font-semibold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Total Plans Recorded</p>
                        <p className="text-3xl font-extrabold text-emerald-900 dark:text-emerald-100 mt-2 print:text-2xl">{summaryData.total_plans}</p>
                    </Card>

                    <Card className="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 print:border-gray-300 print:p-3">
                        <p className="text-xs font-semibold text-blue-800 dark:text-blue-300 uppercase tracking-wider">Approved Plans</p>
                        <p className="text-3xl font-extrabold text-blue-900 dark:text-blue-100 mt-2 print:text-2xl">
                            {summaryData.by_status['Active'] || summaryData.by_status['Approved'] || 0}
                        </p>
                    </Card>

                    <Card className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 print:border-gray-300 print:p-3">
                        <p className="text-xs font-semibold text-amber-800 dark:text-amber-300 uppercase tracking-wider">Pending / For Update</p>
                        <p className="text-3xl font-extrabold text-amber-900 dark:text-amber-100 mt-2 print:text-2xl">
                            {(summaryData.by_status['For Update'] || 0) + (summaryData.by_status['Under Review'] || 0)}
                        </p>
                    </Card>
                </div>

                {/* BREAKDOWN SECTION */}
                <div className="grid gap-6 md:grid-cols-2 print:grid-cols-2 print:gap-4">
                    <Card className="print:border print:shadow-none print:p-4">
                        <h3 className="text-base font-semibold text-gray-900 dark:text-white mb-4 print:text-sm print:mb-2">Breakdown by Plan Type</h3>
                        <div className="space-y-3 print:space-y-1.5">
                            {Object.keys(summaryData.by_type).length > 0 ? (
                                Object.entries(summaryData.by_type).map(([type, count]) => (
                                    <div key={type} className="flex justify-between items-center border-b border-gray-100 dark:border-gray-700 pb-2 print:pb-1">
                                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300 print:text-xs">{type}</span>
                                        <span className="text-sm font-bold text-gray-900 dark:text-white bg-gray-100 dark:bg-gray-800 px-2.5 py-1 rounded print:text-xs print:px-2 print:py-0.5">{count}</span>
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-gray-400 italic">No plan type records found.</p>
                            )}
                        </div>
                    </Card>

                    <Card className="print:border print:shadow-none print:p-4">
                        <h3 className="text-base font-semibold text-gray-900 dark:text-white mb-4 print:text-sm print:mb-2">Breakdown by Status</h3>
                        <div className="space-y-3 print:space-y-1.5">
                            {Object.keys(summaryData.by_status).length > 0 ? (
                                Object.entries(summaryData.by_status).map(([status, count]) => {
                                    const displayStatus = status === 'Active' ? 'Approved' : status;
                                    return (
                                        <div key={status} className="flex justify-between items-center border-b border-gray-100 dark:border-gray-700 pb-2 print:pb-1">
                                            <span className="text-sm font-medium text-gray-700 dark:text-gray-300 print:text-xs">{displayStatus}</span>
                                            <span className="text-sm font-bold text-gray-900 dark:text-white bg-gray-100 dark:bg-gray-800 px-2.5 py-1 rounded print:text-xs print:px-2 print:py-0.5">{count}</span>
                                        </div>
                                    );
                                })
                            ) : (
                                <p className="text-sm text-gray-400 italic">No status records found.</p>
                            )}
                        </div>
                    </Card>
                </div>

                {/* DETAILED PLANS TABLE */}
                <Card title="Detailed Plans List" description="List of management plans included in this summary report." className="print:border print:shadow-none print:p-4">
                    <div className="overflow-x-auto mt-4 print:mt-2">
                        <table className="w-full text-left text-sm text-gray-600 dark:text-gray-300 print:text-xs print:text-black">
                            <thead className="bg-gray-50 text-xs uppercase text-gray-700 dark:bg-gray-700 dark:text-gray-200 print:bg-gray-200 print:text-black">
                                <tr>
                                    <th className="px-4 py-3 print:px-2 print:py-1.5">Protected Area</th>
                                    <th className="px-4 py-3 print:px-2 print:py-1.5">Type</th>
                                    <th className="px-4 py-3 print:px-2 print:py-1.5">Title</th>
                                    <th className="px-4 py-3 print:px-2 print:py-1.5">Year</th>
                                    <th className="px-4 py-3 print:px-2 print:py-1.5">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {summaryData.plans.length > 0 ? (
                                    summaryData.plans.map((plan) => (
                                        <tr key={plan.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-white print:px-2 print:py-1.5 print:text-black">{plan.protected_area_name}</td>
                                            <td className="px-4 py-3 print:px-2 print:py-1.5">{plan.plan_type}</td>
                                            <td className="px-4 py-3 print:px-2 print:py-1.5">{plan.title}</td>
                                            <td className="px-4 py-3 print:px-2 print:py-1.5">{plan.prepared_year}</td>
                                            <td className="px-4 py-3 print:px-2 print:py-1.5">
                                                <StatusBadge variant={plan.status === 'Active' || plan.status === 'Approved' ? 'active' : 'pending'}>
                                                    {plan.status === 'Active' ? 'Approved' : plan.status}
                                                </StatusBadge>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="px-4 py-6 text-center text-gray-400 italic">
                                            No management plans recorded for this selection.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Card>

            </div>
        </AuthenticatedLayout>
    );
}
