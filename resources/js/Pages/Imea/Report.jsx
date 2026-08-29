import { FloatingSelect } from "@/Components/Form";import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Card';
import { ResponsiveContainer, BarChart, Bar, LineChart, Line, XAxis, YAxis, Tooltip, Legend, CartesianGrid } from 'recharts';

export default function ImeaReport({
  totalVisitors,
  totalWaste,
  avgSatisfaction,
  assessmentsList,
  muzCount,
  spzCount,
  newStructuresCount,
  facilitiesList,
  protectedAreas,
  availableYears,
  filters
}) {
  const [selectedYear, setSelectedYear] = useState(filters.year || '');
  const [selectedPeriod, setSelectedPeriod] = useState(filters.period || '');
  const [selectedPA, setSelectedPA] = useState(filters.protected_area_id || '');
  useEffect(() => {
    setSelectedYear(filters.year || '');
    setSelectedPeriod(filters.period || '');
    setSelectedPA(filters.protected_area_id || '');
  }, [filters.year, filters.period, filters.protected_area_id]);

  const [selectedRecord, setSelectedRecord] = useState(null);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);

  const handleFilterChange = (type, value) => {
    const queryParams = {
      year: type === 'year' ? value : selectedYear,
      period: type === 'period' ? value : selectedPeriod,
      protected_area_id: type === 'pa' ? value : selectedPA
    };

    Object.keys(queryParams).forEach((key) => {
      if (!queryParams[key]) delete queryParams[key];
    });

    router.get('/imea/report', queryParams, {
      preserveState: true,
      preserveScroll: true,
      replace: true
    });
  };

  const resetFilters = () => {
    setSelectedYear('');
    setSelectedPeriod('');
    setSelectedPA('');
    router.get('/imea/report', {}, { preserveState: true, preserveScroll: true, replace: true });
  };

  const openModal = (row) => {
    setSelectedRecord(row);
    setIsDetailModalOpen(true);
  };

  const closeModal = () => {
    setIsDetailModalOpen(false);
    setSelectedRecord(null);
  };

  const handlePrint = () => {
    window.print();
  };

  const chartData = assessmentsList.map((item) => ({
    name: item.protected_area?.name ? item.protected_area.name.replace('Protected Landscape', 'PL').replace('Range Wildlife Sanctuary', 'RWS') : 'N/A',
    periodLabel: `${item.assessment_year} (${item.assessment_period})`,
    visitors: Number(item.visitor_arrivals || 0),
    waste: Number(item.solid_waste_generation_kg || 0),
    satisfaction: Number(item.visitor_satisfaction_rate || 0)
  }));

  return (
    <AuthenticatedLayout title="IMEA Summary Report">
            <style>{`
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
                .custom-table-scrollbar {
                    scrollbar-width: thin;
                    scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
                }
                .custom-table-scrollbar::-webkit-scrollbar {
                    width: 6px;
                    height: 6px;
                }
                .custom-table-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(156, 163, 175, 0.5);
                    border-radius: 9999px;
                }

                .print-header { display: none; }
                .print-title-section { display: none; }

                /* Print Layout */
                @media print {
                    @page { size: A4 portrait; margin: 12mm; }
                    body { background: white !important; color: black !important; font-family: "Times New Roman", Times, serif !important; font-size: 9pt !important; -webkit-print-color-adjust: exact; }
                    aside, nav, header, footer, .no-print { display: none !important; }

                    main, .py-6, .px-4, .max-w-7xl {
                        padding-left: 12px !important;
                        padding-right: 12px !important;
                        padding-top: 0 !important;
                        padding-bottom: 0 !important;
                        margin: 0 !important;
                        max-width: none !important;
                        width: 100% !important;
                        box-shadow: none !important;
                        border: none !important;
                        background: white !important;
                    }

                    .shadow-xl, .shadow-lg, .shadow-md { box-shadow: none !important; }

                    .print-header { display: block !important; }
                    .print-title-section { display: block !important; }

                    .dashboard-stats-grid {
                        display: grid !important;
                        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
                        gap: 10px !important;
                        margin-bottom: 14px !important;
                    }

                    .dashboard-charts-grid {
                        display: flex !important;
                        flex-direction: column !important;
                        gap: 16px !important;
                        width: 100% !important;
                        margin-bottom: 14px !important;
                    }

                    .dashboard-card-item {
                        padding: 12px !important;
                        border-radius: 8px !important;
                        page-break-inside: avoid;
                        position: relative !important;
                        border: 1px solid #d1d5db !important;
                    }

                    .dashboard-chart-box {
                        height: 220px !important;
                        width: 100% !important;
                        position: relative !important;
                        margin-top: 8px !important;
                        margin-bottom: 4px !important;
                    }

                    .print-table-container {
                        page-break-before: always !important;
                        margin-top: 20px !important;
                    }
                }
            `}</style>

            <div className="px-4 sm:px-6 lg:px-8 mx-auto max-w-7xl">

                {/* OPISYAL NGA DENR PRINT HEADER */}
                <div className="print-header space-y-1.5 pb-2 mb-2 border-b-2 border-black" style={{ fontFamily: '"Times New Roman", Times, serif' }}>
                    <div className="flex items-center justify-between">
                        <div className="w-24 h-24 flex items-center justify-center shrink-0">
                            <img src="/images/DENR LOGO.png" alt="DENR Logo" className="w-24 h-24 object-contain" onError={(e) => {e.target.style.display = 'none';}} />
                        </div>
                        <div className="text-center space-y-0.5 flex-1 px-2">
                            <p style={{ fontSize: '8pt' }} className="font-bold tracking-widest text-black">REPUBLIC OF THE PHILIPPINES</p>
                            <p style={{ fontSize: '9pt' }} className="font-extrabold text-blue-900 tracking-wide">Department of Environment and Natural Resources</p>
                            <p style={{ fontSize: '9pt' }} className="font-extrabold text-green-800 tracking-wide">PROVINCIAL ENVIRONMENT AND NATURAL RESOURCES OFFICE</p>
                            <p style={{ fontSize: '8pt' }} className="font-semibold text-gray-800">GOVERNMENT CENTER, DAHICAN, CITY OF MATI</p>
                            <p style={{ fontSize: '7.5pt' }} className="text-gray-700">TEL #: 3883-275 | EMAIL ADD: PENRODAVAOORIENTAL@DENR.GOV.PH</p>
                        </div>
                        <div className="w-24 h-24 flex items-center justify-center shrink-0">
                            <img src="/images/Bagong Pilipinas logo.png" alt="Bagong Pilipinas Logo" className="w-24 h-24 object-contain" onError={(e) => {e.target.style.display = 'none';}} />
                        </div>
                    </div>
                </div>

                {/* TITLE SECTION */}
                <div className="print-title-section text-center space-y-0.5 pt-2 pb-3 mb-3" style={{ fontFamily: '"Times New Roman", Times, serif' }}>
                    <h4 style={{ fontSize: '9pt' }} className="font-bold tracking-wider text-black uppercase">CONSERVATION AND DEVELOPMENT SECTION</h4>
                    <h2 style={{ fontSize: '10pt' }} className="font-extrabold uppercase tracking-wide text-black">
                        INTEGRATED MONITORING OF ECOTOURISM ACTIVITY (IMEA) REPORT
                    </h2>
                </div>

                {/* Header Banner (Normal view) */}
                <div className="sticky top-20 z-10 relative overflow-hidden rounded-xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-md mb-6 no-print">
                    <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/15 blur-2xl pointer-events-none"></div>

                    <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-white">Integrated Protected Area Ecotourism Monitoring (IMEA)</h1>
                            <p className="mt-1 text-sm text-green-100">
                                Consolidation of ecotourism impact assessments and infrastructure inventories of PAMOs.
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <button
                type="button"
                onClick={handlePrint}
                className="inline-flex items-center justify-center rounded-xl bg-white text-green-900 hover:bg-green-50 px-4 py-2.5 text-xs sm:text-sm font-bold shadow-sm transition whitespace-nowrap">

                                🖨️ Print / Save PDF
                            </button>
                            <Link
                href="/imea"
                className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">

                                ← Back to List
                            </Link>
                        </div>
                    </div>
                </div>

                {/* Filter Section */}
                <div className="no-print">
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-4 sm:p-6 mb-6">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4 items-end">
                            <div>

                                <FloatingSelect id="report-filter-by-protected-area" label="Filter by Protected Area"
                value={selectedPA}
                onChange={(e) => {
                  setSelectedPA(e.target.value);
                  handleFilterChange('pa', e.target.value);
                }} size="sm">


                                    <option value="">All Protected Areas</option>
                                    {protectedAreas.map((pa) =>
                  <option key={pa.id} value={pa.id}>{pa.name}</option>
                  )}
                                </FloatingSelect>
                            </div>
                            <div>

                                <FloatingSelect id="report-filter-by-year" label="Filter by Year"
                value={selectedYear}
                onChange={(e) => {
                  setSelectedYear(e.target.value);
                  handleFilterChange('year', e.target.value);
                }} size="sm">


                                    <option value="">All Years</option>
                                    {availableYears.map((year) =>
                  <option key={year} value={year}>{year}</option>
                  )}
                                </FloatingSelect>
                            </div>
                            <div>

                                <FloatingSelect id="report-filter-by-period" label="Filter by Period"
                value={selectedPeriod}
                onChange={(e) => {
                  setSelectedPeriod(e.target.value);
                  handleFilterChange('period', e.target.value);
                }} size="sm">


                                    <option value="">All Periods</option>
                                    <option value="Annual">Annual</option>
                                    <option value="Semestral - 1st Semester">Semestral - 1st Semester</option>
                                    <option value="Semestral - 2nd Semester">Semestral - 2nd Semester</option>
                                    <option value="Q1">Q1</option>
                                    <option value="Q2">Q2</option>
                                    <option value="Q3">Q3</option>
                                    <option value="Q4">Q4</option>
                                </FloatingSelect>
                            </div>
                            <div>
                                <button
                  type="button"
                  onClick={resetFilters}
                  className="w-full rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold py-2.5 px-4 text-sm transition">

                                    Reset Filters
                                </button>
                            </div>
                        </div>
                    </Card>
                </div>

                {/* IMPACT SUMMARY STATS */}
                <div className="dashboard-stats-grid grid grid-cols-1 gap-6 sm:grid-cols-3 mb-4">
                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-green-600">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Visitor Arrivals</div>
                        <div className="text-3xl font-extrabold text-green-800 dark:text-green-400 mt-2">
                            {Number(totalVisitors || 0).toLocaleString()}
                        </div>
                        <p className="text-xs text-gray-400 mt-1">Aggregated from filtered data</p>
                    </Card>

                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-green-600">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Solid Waste Generation</div>
                        <div className="text-3xl font-extrabold text-green-800 dark:text-green-400 mt-2">
                            {Number(totalWaste || 0).toLocaleString()} <span className="text-sm font-medium text-gray-500">kg</span>
                        </div>
                        <p className="text-xs text-gray-400 mt-1">Total recorded waste</p>
                    </Card>

                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-green-600">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Avg. Visitor Satisfaction</div>
                        <div className="text-3xl font-extrabold text-green-800 dark:text-green-400 mt-2">
                            {avgSatisfaction ? `${avgSatisfaction}%` : 'N/A'}
                        </div>
                        <p className="text-xs text-gray-400 mt-1">Rating average</p>
                    </Card>
                </div>

                {/* FACILITIES & INFRASTRUCTURE STATS */}
                <div className="dashboard-stats-grid grid grid-cols-1 gap-6 sm:grid-cols-3 mb-6">
                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-blue-500">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">MUZ Structures</div>
                        <div className="text-3xl font-extrabold text-blue-800 dark:text-blue-400 mt-2">
                            {muzCount || 0}
                        </div>
                        <p className="text-xs text-gray-400 mt-1">Inside Multiple Use Zone</p>
                    </Card>

                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-amber-500">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">SPZ Structures</div>
                        <div className="text-3xl font-extrabold text-amber-800 dark:text-amber-400 mt-2">
                            {spzCount || 0}
                        </div>
                        <p className="text-xs text-gray-400 mt-1">Inside Strict Protection Zone</p>
                    </Card>

                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-indigo-500">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">New Structures Built</div>
                        <div className="text-3xl font-extrabold text-indigo-800 dark:text-indigo-400 mt-2">
                            {newStructuresCount || 0}
                        </div>
                        <p className="text-xs text-gray-400 mt-1">Constructed after 2022 baseline</p>
                    </Card>
                </div>

                {/* VISUAL CHARTS & TRENDS SECTION */}
                {chartData.length > 0 &&
        <div className="dashboard-charts-grid grid grid-cols-1 gap-6 lg:grid-cols-2 mb-6">
                        <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6">
                            <h3 className="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-2">Visitor Arrivals & Waste per Area</h3>
                            <div className="dashboard-chart-box h-72 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData}>
                                        <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                                        <XAxis dataKey="name" fontSize={10} tickLine={false} />
                                        <YAxis fontSize={10} />
                                        <Tooltip contentStyle={{ backgroundColor: '#111827', borderColor: '#374151', borderRadius: '0.75rem', color: '#fff', fontSize: '11px' }} />
                                        <Legend wrapperStyle={{ fontSize: '10px' }} />
                                        <Bar dataKey="visitors" name="Visitors" fill="#166534" radius={[4, 4, 0, 0]} />
                                        <Bar dataKey="waste" name="Waste (kg)" fill="#0d9488" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </Card>

                        <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6">
                            <h3 className="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-2">Visitor Satisfaction Rate Trends (%)</h3>
                            <div className="dashboard-chart-box h-72 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={chartData}>
                                        <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                                        <XAxis dataKey="periodLabel" fontSize={10} tickLine={false} />
                                        <YAxis domain={[0, 100]} fontSize={10} />
                                        <Tooltip contentStyle={{ backgroundColor: '#111827', borderColor: '#374151', borderRadius: '0.75rem', color: '#fff', fontSize: '11px' }} />
                                        <Legend wrapperStyle={{ fontSize: '10px' }} />
                                        <Line type="monotone" dataKey="satisfaction" name="Satisfaction Rate (%)" stroke="#10b981" strokeWidth={2.5} dot={{ r: 4 }} activeDot={{ r: 6 }} />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </Card>
                    </div>
        }

                {/* Clickable Table List for Assessments */}
                <div className="print-table-container">
                    <Card padding="p-0" className="border border-gray-100 dark:border-gray-800 overflow-hidden shadow-xl rounded-2xl mb-6">
                        <div className="p-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center no-print">
                            <h3 className="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wider">Detailed Assessment Records & Status</h3>
                            <span className="text-xs text-gray-500 italic">💡 Click any row to view full details</span>
                        </div>
                        {assessmentsList && assessmentsList.length > 0 ?
            <div className="overflow-x-auto custom-table-scrollbar">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="border-b border-gray-200 bg-green-900 text-white text-xs uppercase tracking-wider dark:border-gray-700">
                                            <th className="px-6 py-3.5 font-semibold">Protected Area / PAMO</th>
                                            <th className="px-6 py-3.5 font-semibold">Year / Period</th>
                                            <th className="px-6 py-3.5 font-semibold">Trail & Water Condition</th>
                                            <th className="px-6 py-3.5 font-semibold">Impact Notes</th>
                                            <th className="px-6 py-3.5 font-semibold">Carrying Capacity</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 text-sm dark:divide-gray-800">
                                        {assessmentsList.map((row) =>
                  <tr
                    key={row.id}
                    onClick={() => openModal(row)}
                    className="cursor-pointer transition hover:bg-green-50/60 dark:hover:bg-green-950/30">

                                                <td className="px-6 py-4 font-semibold text-gray-900 dark:text-white">
                                                    <div>{row.protected_area?.name || 'N/A'}</div>
                                                    <div className="text-xs text-gray-500 font-normal">{row.pamo_name}</div>
                                                </td>
                                                <td className="px-6 py-4 text-gray-700 dark:text-gray-300">
                                                    {row.assessment_year} ({row.assessment_period})
                                                </td>
                                                <td className="px-6 py-4 text-xs text-gray-700 dark:text-gray-300 space-y-1">
                                                    <div><strong className="text-gray-900 dark:text-white">Trail:</strong> {row.trail_condition || 'N/A'}</div>
                                                    <div><strong className="text-gray-900 dark:text-white">Water:</strong> {row.water_quality || 'N/A'}</div>
                                                </td>
                                                <td className="px-6 py-4 text-xs text-gray-700 dark:text-gray-300 max-w-xs truncate">
                                                    <div><strong className="text-gray-900 dark:text-white">Biodiversity:</strong> {row.biodiversity_impact_notes || 'None'}</div>
                                                    <div><strong className="text-gray-900 dark:text-white">Env:</strong> {row.environment_impact_notes || 'None'}</div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <span className={`px-2.5 py-1 text-xs font-semibold rounded-full ${row.carrying_capacity_compliance ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'}`}>
                                                        {row.carrying_capacity_compliance ? 'Compliant' : 'Exceeded'}
                                                    </span>
                                                </td>
                                            </tr>
                  )}
                                    </tbody>
                                </table>
                            </div> :

            <div className="p-12 text-center">
                                <p className="text-xs text-gray-500 dark:text-gray-400">No assessment records found for the selected filters.</p>
                            </div>
            }
                    </Card>
                </div>

            </div>

            {/* FULL DETAILS MODAL */}
            {isDetailModalOpen && selectedRecord &&
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs no-print">
                    <div className="relative w-full max-w-3xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900 max-h-[90vh] overflow-y-auto animate-pop-in custom-table-scrollbar border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-4 dark:border-gray-800">
                            <div className="flex items-center gap-2">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-400">📋</span>
                                <div>
                                    <h3 className="font-bold text-gray-900 dark:text-white">Assessment Full Details</h3>
                                    <p className="text-xs text-gray-500">{selectedRecord.protected_area?.name} — {selectedRecord.pamo_name}</p>
                                </div>
                            </div>
                            <button type="button" onClick={closeModal} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 font-bold text-lg">✕</button>
                        </div>

                        <div className="mt-4 space-y-6 text-sm">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-gray-50 dark:bg-gray-800/40 p-4 rounded-xl border border-gray-100 dark:border-gray-800">
                                <div>
                                    <span className="text-xs text-gray-500 block">Year / Period</span>
                                    <span className="font-semibold text-gray-900 dark:text-white">{selectedRecord.assessment_year} ({selectedRecord.assessment_period})</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500 block">Visitor Arrivals</span>
                                    <span className="font-semibold text-gray-900 dark:text-white">{selectedRecord.visitor_arrivals ?? '0'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500 block">Solid Waste</span>
                                    <span className="font-semibold text-gray-900 dark:text-white">{selectedRecord.solid_waste_generation_kg ?? '0'} kg</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500 block">Satisfaction Rate</span>
                                    <span className="font-semibold text-green-700 dark:text-green-400">{selectedRecord.visitor_satisfaction_rate ? `${selectedRecord.visitor_satisfaction_rate}%` : 'N/A'}</span>
                                </div>
                            </div>

                            <div>
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-2">Indicators & Status</h4>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs bg-gray-50 dark:bg-gray-800/30 p-4 rounded-xl">
                                    <div><strong className="text-gray-900 dark:text-white">Trail Condition:</strong> {selectedRecord.trail_condition || 'N/A'}</div>
                                    <div><strong className="text-gray-900 dark:text-white">Wildlife Disturbance:</strong> {selectedRecord.wildlife_disturbance || 'N/A'}</div>
                                    <div><strong className="text-gray-900 dark:text-white">Vegetation Damage:</strong> {selectedRecord.vegetation_damage || 'N/A'}</div>
                                    <div><strong className="text-gray-900 dark:text-white">Water Quality:</strong> {selectedRecord.water_quality || 'N/A'}</div>
                                    <div><strong className="text-gray-900 dark:text-white">Carrying Capacity:</strong> {selectedRecord.carrying_capacity_compliance ? 'Compliant' : 'Exceeded'}</div>
                                </div>
                            </div>

                            <div>
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-2">Detailed Impact & Livelihood Notes</h4>
                                <div className="space-y-3 text-xs">
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Biodiversity Impact Notes:</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedRecord.biodiversity_impact_notes || 'No notes provided.'}</p>
                                    </div>
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Environment Impact Notes:</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedRecord.environment_impact_notes || 'No notes provided.'}</p>
                                    </div>
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Social / Cultural Impact Notes:</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedRecord.social_cultural_impact_notes || 'No notes provided.'}</p>
                                    </div>
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Economic Impact & Livelihood:</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedRecord.economic_impact_notes || 'No notes provided.'}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end pt-2 border-t border-gray-100 dark:border-gray-800">
                                <button
                type="button"
                onClick={closeModal}
                className="rounded-lg bg-green-800 hover:bg-green-900 text-white font-semibold py-2 px-5 text-xs transition">

                                    Close Details
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
      }
        </AuthenticatedLayout>);

}
