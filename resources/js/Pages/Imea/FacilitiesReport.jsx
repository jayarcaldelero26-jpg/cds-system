import { FloatingSelect } from "@/Components/Form";import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Card';
import Tooltip from '@/Components/Tooltip';

export default function FacilitiesReport({
  totalFacilities,
  muzCount,
  spzCount,
  newStructuresCount,
  facilitiesList,
  protectedAreas,
  inventoryDates = [], // Lista sa mga na-encode nga inventory dates para sa dropdown
  filters
}) {
  const canExportImea = usePage().props.auth?.canExportImea ?? false;
  const [selectedPA, setSelectedPA] = useState(filters.protected_area_id || '');
  const [selectedZone, setSelectedZone] = useState(filters.zone || '');
  const [selectedInventoryDate, setSelectedInventoryDate] = useState(filters.inventory_date || '');

  // Facility Detail Modal State
  const [selectedFacility, setSelectedFacility] = useState(null);
  const [isFacilityModalOpen, setIsFacilityModalOpen] = useState(false);

  // Dynamic As-Of Date para sa header ug print view
  const currentInventoryDateDisplay = selectedInventoryDate ?
  `As of ${selectedInventoryDate}` :
  facilitiesList && facilitiesList.length > 0 && facilitiesList[0].inventory_date ?
  `As of ${facilitiesList[0].inventory_date}` :
  'As of Recent Inventory';

  const handleFilterChange = (type, value) => {
    const queryParams = {
      protected_area_id: type === 'pa' ? value : selectedPA,
      zone: type === 'zone' ? value : selectedZone,
      inventory_date: type === 'date' ? value : selectedInventoryDate
    };

    Object.keys(queryParams).forEach((key) => {
      if (!queryParams[key]) delete queryParams[key];
    });

    router.get('/imea/facilities-report', queryParams, {
      preserveState: true,
      preserveScroll: true,
      replace: true
    });
  };

  const resetFilters = () => {
    setSelectedPA('');
    setSelectedZone('');
    setSelectedInventoryDate('');
    router.get('/imea/facilities-report', {}, { preserveState: true });
  };

  const handlePrint = () => {
    window.print();
  };

  const openFacilityModal = (facility) => {
    setSelectedFacility(facility);
    setIsFacilityModalOpen(true);
  };

  const closeFacilityModal = () => {
    setIsFacilityModalOpen(false);
    setSelectedFacility(null);
  };

  return (
    <AuthenticatedLayout title="Facilities Summary Report">
            <style>{`
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
                .custom-table-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
                .custom-table-scrollbar::-webkit-scrollbar-thumb { background: rgba(156, 163, 175, 0.5); border-radius: 9999px; }

                .print-header { display: none; }
                .print-title-section { display: none; }

                @media print {
                    @page { size: A4 landscape; margin: 10mm; }
                    body { background: white !important; color: black !important; font-family: "Times New Roman", Times, serif !important; font-size: 8.5pt !important; -webkit-print-color-adjust: exact; }
                    aside, nav, header, footer, .no-print { display: none !important; }

                    main, .py-6, .px-4, .max-w-7xl {
                        padding: 0 !important; margin: 0 !important; max-width: none !important; width: 100% !important; background: white !important; border: none !important; box-shadow: none !important;
                    }

                    .print-header { display: block !important; }
                    .print-title-section { display: block !important; }
                    .dashboard-stats-grid { display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)) !important; gap: 10px !important; margin-bottom: 14px !important; }
                    .dashboard-card-item { padding: 10px !important; border: 1px solid #d1d5db !important; page-break-inside: avoid; }
                    .print-table-container { margin-top: 10px !important; }
                }
            `}</style>

            <div className="px-4 sm:px-6 lg:px-8 mx-auto max-w-7xl">

                {/* OPISYAL NGA DENR PRINT HEADER */}
                <div className="print-header space-y-1.5 pb-2 mb-2 border-b-2 border-black" style={{ fontFamily: '"Times New Roman", Times, serif' }}>
                    <div className="flex items-center justify-between">
                        <div className="w-20 h-20 flex items-center justify-center shrink-0">
                            <img src="/images/DENR LOGO.png" alt="DENR Logo" className="w-20 h-20 object-contain" onError={(e) => {e.target.style.display = 'none';}} />
                        </div>
                        <div className="text-center space-y-0.5 flex-1 px-2">
                            <p style={{ fontSize: '8pt' }} className="font-bold tracking-widest text-black">REPUBLIC OF THE PHILIPPINES</p>
                            <p style={{ fontSize: '9pt' }} className="font-extrabold text-blue-900 tracking-wide">Department of Environment and Natural Resources</p>
                            <p style={{ fontSize: '9pt' }} className="font-extrabold text-green-800 tracking-wide">PROVINCIAL ENVIRONMENT AND NATURAL RESOURCES OFFICE</p>
                            <p style={{ fontSize: '8pt' }} className="font-semibold text-gray-800">GOVERNMENT CENTER, DAHICAN, CITY OF MATI</p>
                        </div>
                        <div className="w-20 h-20 flex items-center justify-center shrink-0">
                            <img src="/images/Bagong Pilipinas logo.png" alt="Bagong Pilipinas Logo" className="w-20 h-20 object-contain" onError={(e) => {e.target.style.display = 'none';}} />
                        </div>
                    </div>
                </div>

                {/* TITLE SECTION WITH DYNAMIC INVENTORY DATE INDICATOR */}
                <div className="print-title-section text-center space-y-0.5 pt-2 pb-3 mb-3" style={{ fontFamily: '"Times New Roman", Times, serif' }}>
                    <h4 style={{ fontSize: '9pt' }} className="font-bold tracking-wider text-black uppercase">CONSERVATION AND DEVELOPMENT SECTION</h4>
                    <h2 style={{ fontSize: '10pt' }} className="font-extrabold uppercase tracking-wide text-black">
                        INVENTORY OF EXISTING FACILITIES AND INFRASTRUCTURES WITHIN PROTECTED AREAS
                    </h2>
                    <p style={{ fontSize: '9pt' }} className="font-semibold text-gray-800 italic">
                        {currentInventoryDateDisplay}
                    </p>
                </div>

                {/* Header Banner (Normal view) */}
                <div className="sticky top-20 z-10 relative overflow-hidden rounded-xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-md mb-6 no-print">
                    <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-white">Facilities & Infrastructures Inventory Report</h1>
                            <p className="mt-1 text-sm text-green-100">Consolidated zoning and infrastructure records ({currentInventoryDateDisplay}).</p>
                        </div>
                        <div className="flex items-center gap-3">
                            {canExportImea &&
              <a
                href={`/imea/facilities-export?protected_area_id=${selectedPA}&zone=${selectedZone}&inventory_date=${selectedInventoryDate}`}
                className="inline-flex items-center justify-center rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 text-xs sm:text-sm font-bold shadow-sm transition whitespace-nowrap">

                                    Export to CSV
                                </a>
              }
                            <button onClick={handlePrint} className="inline-flex items-center justify-center rounded-xl bg-white text-green-900 hover:bg-green-50 px-4 py-2.5 text-xs sm:text-sm font-bold shadow-sm transition">
                                🖨️ Print / Save PDF
                            </button>
                            <Link href="/imea" className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition backdrop-blur-xs">
                                ← Back to List
                            </Link>
                        </div>
                    </div>
                </div>

                {/* 4-COLUMN FILTER SECTION (GI-APIL ANG DATE CONDUCTED DROPDOWN) */}
                <div className="no-print mb-6">
                    <Card className="border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4 items-end">
                            <div>

                                <FloatingSelect id="facilitiesreport-filter-by-protected-area" label="Filter by Protected Area" value={selectedPA} onChange={(e) => {setSelectedPA(e.target.value);handleFilterChange('pa', e.target.value);}} size="sm">
                                    <option value="">All Protected Areas</option>
                                    {protectedAreas.map((pa) => <option key={pa.id} value={pa.id}>{pa.name}</option>)}
                                </FloatingSelect>
                            </div>
                            <div>

                                <FloatingSelect id="facilitiesreport-filter-by-management-zone" label="Filter by Management Zone" value={selectedZone} onChange={(e) => {setSelectedZone(e.target.value);handleFilterChange('zone', e.target.value);}} size="sm">
                                    <option value="">All Zones</option>
                                    <option value="MUZ">MUZ (Multiple Use Zone)</option>
                                    <option value="SPZ">SPZ (Strict Protection Zone)</option>
                                </FloatingSelect>
                            </div>
                            <div>

                                <FloatingSelect id="facilitiesreport-date-conducted-as-of" label="Date Conducted (As Of)" value={selectedInventoryDate} onChange={(e) => {setSelectedInventoryDate(e.target.value);handleFilterChange('date', e.target.value);}}>
                                    <option value="">All Inventory Dates</option>
                                    {inventoryDates.map((dateVal, index) =>
                  <option key={index} value={dateVal}>{dateVal}</option>
                  )}
                                </FloatingSelect>
                            </div>
                            <div>
                                <button type="button" onClick={resetFilters} className="w-full rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold py-2.5 px-4 text-sm transition">
                                    Reset Filters
                                </button>
                            </div>
                        </div>
                    </Card>
                </div>

                {/* STATS CARDS */}
                <div className="dashboard-stats-grid grid grid-cols-1 gap-6 sm:grid-cols-4 mb-6">
                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-green-600">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500">Total Facilities</div>
                        <div className="text-3xl font-extrabold text-green-800 dark:text-green-400 mt-2">{totalFacilities || 0}</div>
                        <p className="text-xs text-gray-400 mt-1">Recorded items</p>
                    </Card>
                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-blue-500">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500">MUZ Structures</div>
                        <div className="text-3xl font-extrabold text-blue-800 dark:text-blue-400 mt-2">{muzCount || 0}</div>
                        <p className="text-xs text-gray-400 mt-1">Inside Multiple Use Zone</p>
                    </Card>
                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-amber-500">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500">SPZ Structures</div>
                        <div className="text-3xl font-extrabold text-amber-800 dark:text-amber-400 mt-2">{spzCount || 0}</div>
                        <p className="text-xs text-gray-400 mt-1">Inside Strict Protection Zone</p>
                    </Card>
                    <Card className="dashboard-card-item border border-gray-100 dark:border-gray-800 shadow-lg rounded-2xl bg-white dark:bg-gray-900 p-6 border-l-4 border-l-indigo-500">
                        <div className="text-xs font-bold uppercase tracking-wider text-gray-500">New Structures Built</div>
                        <div className="text-3xl font-extrabold text-indigo-800 dark:text-indigo-400 mt-2">{newStructuresCount || 0}</div>
                        <p className="text-xs text-gray-400 mt-1">Constructed after baseline</p>
                    </Card>
                </div>

                {/* FACILITIES TABLE WITH DYNAMIC AS-OF SUBTITLE */}
                <div className="print-table-container">
                    <Card padding="p-0" className="border border-gray-100 dark:border-gray-800 overflow-hidden shadow-xl rounded-2xl">
                        <div className="p-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                            <div>
                                <h3 className="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wider">Detailed Facilities & Infrastructures Inventory List</h3>
                                <p className="text-xs text-green-700 dark:text-green-400 font-semibold mt-0.5">📅 {currentInventoryDateDisplay}</p>
                            </div>
                            <span className="text-xs text-gray-500 italic no-print">💡 Click any row to view full details</span>
                        </div>
                        {facilitiesList && facilitiesList.length > 0 ?
            <div className="overflow-x-auto custom-table-scrollbar">
                                <table className="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr className="border-b border-gray-200 bg-green-900 text-white uppercase tracking-wider dark:border-gray-700">
                                            <th className="px-4 py-3.5 font-semibold">Protected Area</th>
                                            <th className="px-4 py-3.5 font-semibold">Facility / Structure</th>
                                            <th className="px-4 py-3.5 font-semibold">Unit</th>
                                            <th className="px-4 py-3.5 font-semibold">Year Established</th>
                                            <th className="px-4 py-3.5 font-semibold">Location</th>
                                            <th className="px-4 py-3.5 font-semibold">Zone</th>
                                            <th className="px-4 py-3.5 font-semibold">Easement</th>
                                            <th className="px-4 py-3.5 font-semibold">Status</th>
                                            <th className="px-4 py-3.5 font-semibold">Recommendations</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                                        {facilitiesList.map((row) =>
                  <tr
                    key={row.id}
                    onClick={() => openFacilityModal(row)}
                    className="cursor-pointer transition hover:bg-green-50/60 dark:hover:bg-green-950/30">

                                                <td className="px-4 py-3 font-semibold text-gray-900 dark:text-white">{row.protected_area?.name || 'N/A'}</td>
                                                <td className="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{row.facility_type}</td>
                                                <td className="px-4 py-3">{row.unit_no}</td>
                                                <td className="px-4 py-3">{row.year_established || '—'}</td>
                                                <td className="px-4 py-3">{row.location_brgy_muni || '—'}</td>
                                                <td className="px-4 py-3 font-semibold">{row.management_zone}</td>
                                                <td className="px-4 py-3">{row.within_easement_zone}</td>
                                                <td className="px-4 py-3">
                                                    <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${row.status === 'Functional' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 truncate max-w-xs">{row.recommendations ? <Tooltip content={row.recommendations}><span tabIndex={0} className="outline-none">{row.recommendations}</span></Tooltip> : '—'}</td>
                                            </tr>
                  )}
                                    </tbody>
                                </table>
                            </div> :

            <div className="p-12 text-center">
                                <p className="text-xs text-gray-500">No facility records found.</p>
                            </div>
            }
                    </Card>
                </div>

            </div>

            {/* FACILITY FULL DETAILS MODAL */}
            {isFacilityModalOpen && selectedFacility &&
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs no-print">
                    <div className="relative w-full max-w-3xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-900 max-h-[90vh] overflow-y-auto animate-pop-in custom-table-scrollbar border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-4 dark:border-gray-800">
                            <div className="flex items-center gap-2">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-400">🏗️</span>
                                <div>
                                    <h3 className="font-bold text-gray-900 dark:text-white">Facility Full Details</h3>
                                    <p className="text-xs text-gray-500">{selectedFacility.protected_area?.name || 'N/A'} — {selectedFacility.facility_type}</p>
                                </div>
                            </div>
                            <button type="button" onClick={closeFacilityModal} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 font-bold text-lg">✕</button>
                        </div>

                        <div className="mt-4 space-y-6 text-sm">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-gray-50 dark:bg-gray-800/40 p-4 rounded-xl border border-gray-100 dark:border-gray-800">
                                <div>
                                    <span className="text-xs text-gray-500 block">Unit (no.)</span>
                                    <span className="font-semibold text-gray-900 dark:text-white">{selectedFacility.unit_no}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500 block">Year Established</span>
                                    <span className="font-semibold text-gray-900 dark:text-white">{selectedFacility.year_established || '—'}</span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500 block">Status</span>
                                    <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-bold mt-0.5 ${selectedFacility.status === 'Functional' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>
                                        {selectedFacility.status}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500 block">Typhoon Affected</span>
                                    <span className="font-semibold text-gray-900 dark:text-white">{selectedFacility.typhoon_affected || 'No'}</span>
                                </div>
                            </div>

                            <div>
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-2">Location & Zoning Indicators</h4>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs bg-gray-50 dark:bg-gray-800/30 p-4 rounded-xl">
                                    <div className="sm:col-span-2"><strong className="text-gray-900 dark:text-white">Inventory As-Of Date / Period:</strong> <span className="text-green-700 font-bold">{selectedFacility.inventory_date || '—'}</span></div>
                                    <div><strong className="text-gray-900 dark:text-white">Location (Brgy/Muni):</strong> {selectedFacility.location_brgy_muni || '—'}</div>
                                    <div><strong className="text-gray-900 dark:text-white">Management Zone:</strong> <span className="text-green-700 font-bold">{selectedFacility.management_zone}</span></div>
                                    <div><strong className="text-gray-900 dark:text-white">Within Easement Zone:</strong> {selectedFacility.within_easement_zone}</div>
                                    <div><strong className="text-gray-900 dark:text-white">Coordinates:</strong> <span className="font-mono">{selectedFacility.coordinates || '—'}</span></div>
                                    <div className="sm:col-span-2"><strong className="text-gray-900 dark:text-white">Source of Fund:</strong> {selectedFacility.source_of_fund || '—'}</div>
                                    <div className="sm:col-span-2"><strong className="text-gray-900 dark:text-white">Tenurial Instrument / Permits:</strong> {selectedFacility.tenurial_instrument || '—'}</div>
                                </div>
                            </div>

                            <div>
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-2">Description, Recommendations & Remarks</h4>
                                <div className="space-y-3 text-xs">
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Description (Function / Objective):</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedFacility.description || 'No description provided.'}</p>
                                    </div>
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Appropriate Recommendations:</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedFacility.recommendations || 'No recommendations.'}</p>
                                    </div>
                                    <div className="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-800">
                                        <strong className="text-gray-900 dark:text-white block mb-1">Remarks:</strong>
                                        <p className="text-gray-600 dark:text-gray-300">{selectedFacility.remarks || 'No remarks.'}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end pt-2 border-t border-gray-100 dark:border-gray-800">
                                <button
                type="button"
                onClick={closeFacilityModal}
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
