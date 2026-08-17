import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SuccessAlert from './Components/SuccessAlert';
import SuccessModal from './Components/SuccessModal';
import MapView from './Components/MapView';
import { Head, useForm } from '@inertiajs/react';

export default function BamsIndex({ auth, protectedAreas = [], bamsRecords = [], spatialData = null }) {
    const [activeTab, setActiveTab] = useState('list');

    // States for Modals (Success, Single Delete, Bulk Delete)
    const [showSuccess, setShowSuccess] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Action completed successfully.');
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [recordToDelete, setRecordToDelete] = useState(null);

    // Form for PMP Single Entry (Annex 6.8 Standards)
    const form = useForm({
        protected_area_id: '',
        plot_no: '',
        quadrat_no: '',
        transect_no: '',
        date: '',
        time: '',
        observer: '',
        vegetation_type: '',
        weather: '',
        elevation: '',
        gps_unit: '',
        lat: '',
        long: '',
        species_code: '',
        dbh: '',
        th: '',
        mh: '',
        bearing: '',
        distance: '',
        remarks: ''
    });

    // Form for Excel / CSV Import
    const excelForm = useForm({
        protected_area_id: '',
        file: null
    });

    // Form for Shapefile / GeoJSON Import
    const spatialForm = useForm({
        protected_area_id: '',
        spatial_file: null
    });

    const submitRecord = (e) => {
        e.preventDefault();
        setSuccessMessage('Permanent Monitoring Plot record successfully added.');
        setShowSuccess(true);
        form.reset();
    };

    const submitExcelImport = (e) => {
        e.preventDefault();
        setSuccessMessage('Excel / CSV data successfully imported and processed.');
        setShowSuccess(true);
        excelForm.reset();
    };

    // GI-FIX: Actual Inertia post request to the backend controller
    const submitSpatialImport = (e) => {
        e.preventDefault();

        spatialForm.post(route('bams.store-spatial'), {
            preserveScroll: true,
            onSuccess: () => {
                setSuccessMessage('Spatial boundary file successfully uploaded, converted, and rendered!');
                setShowSuccess(true);
                spatialForm.reset();
                setActiveTab('map'); // Automatically switches to Map View after upload!
            },
            onError: (errors) => {
                console.error("Spatial upload error:", errors);
            }
        });
    };

    const confirmDelete = () => {
        setShowDeleteConfirm(false);
        setRecordToDelete(null);
        setSuccessMessage('Record successfully deleted.');
        setShowSuccess(true);
    };

    const confirmBulkDelete = () => {
        setShowBulkDeleteConfirm(false);
        setSelectedIds([]);
        setSuccessMessage('Selected records successfully deleted.');
        setShowSuccess(true);
    };

    return (
        <AuthenticatedLayout user={auth?.user}>
            <Head title="Permanent Monitoring Plot - BAMS" />

            <div className="py-6 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-900 min-h-screen">
                <div className="max-w-7xl mx-auto space-y-6">

                    <SuccessAlert />

                    <SuccessModal
                        show={showSuccess}
                        onClose={() => setShowSuccess(false)}
                        title="Success!"
                        message={successMessage}
                    />

                    {/* CUSTOM BULK DELETE CONFIRMATION MODAL */}
                    {showBulkDeleteConfirm && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                            <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">
                                <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">⚠️</div>
                                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Delete Selected Records?</h3>
                                <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Are you sure you want to delete {selectedIds.length} selected record(s)? This cannot be undone.</p>
                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setShowBulkDeleteConfirm(false)} className="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition">Cancel</button>
                                    <button type="button" onClick={confirmBulkDelete} className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold shadow-sm transition">Yes, Delete All</button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* CUSTOM SINGLE DELETE CONFIRMATION MODAL */}
                    {showDeleteConfirm && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                            <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">
                                <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">⚠️</div>
                                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Are you sure?</h3>
                                <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Do you really want to delete this record? This process cannot be undone.</p>
                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setShowDeleteConfirm(false)} className="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition">Cancel</button>
                                    <button type="button" onClick={confirmDelete} className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold shadow-sm transition">Yes, Delete</button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* MANAGEMENT PLAN STYLE GRADIENT HEADER BANNER */}
                    <div className="sticky top-20 z-10 relative overflow-hidden rounded-xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-md">
                        {/* Glowing light circle effect sa kilid */}
                        <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/15 blur-2xl pointer-events-none"></div>

                        <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-white">
                                    Field Data Sheet for Permanent Monitoring Plot
                                </h1>
                                <p className="mt-1 text-sm text-green-100">
                                    Terrestrial Ecosystems Floristic Survey, Spatial Mapping, and Tree Measurements.
                                </p>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="bg-white/10 backdrop-blur-md text-white border border-white/20 px-4 py-2 rounded-xl text-xs font-bold tracking-wider uppercase">
                                    BAMS Operations
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* NAVIGATION TABS */}
                    <div className="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700 pb-3 no-print">
                        <button onClick={() => setActiveTab('list')} className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'list' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            📄 Database Records
                        </button>
                        <button onClick={() => setActiveTab('add')} className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'add' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            ➕ Encode Field Sheet
                        </button>
                        <button onClick={() => setActiveTab('map')} className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'map' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            🗺️ Map View
                        </button>
                        <button onClick={() => setActiveTab('excel-import')} className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'excel-import' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            📊 Excel / CSV Import
                        </button>
                        <button onClick={() => setActiveTab('spatial-import')} className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'spatial-import' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            🌐 Spatial File Import
                        </button>
                    </div>

                    {/* TAB 1: RECORDS VIEW */}
                    {activeTab === 'list' && (
                        <div className="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden p-6 border border-gray-100 dark:border-gray-700 space-y-4">
                            <div className="flex justify-between items-center">
                                <h3 className="text-base font-bold text-gray-900 dark:text-white">Permanent Monitoring Plot Records</h3>
                                {bamsRecords.length > 0 && (
                                    <button onClick={() => setShowBulkDeleteConfirm(true)} className="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 rounded-xl text-xs font-bold transition">
                                        🗑️ Delete Selected
                                    </button>
                                )}
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse border border-gray-300 dark:border-gray-700 text-xs">
                                    <thead className="bg-green-800 text-white uppercase font-bold text-center">
                                        <tr>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-16">NO.</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3">PROTECTED AREA</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3">SPECIES CODE</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-24">DBH (CM)</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-24">TH (M)</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-24">MH (M)</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3">BEARING</th>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3">DISTANCE (M)</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                                        {bamsRecords.length > 0 ? (
                                            bamsRecords.map((record, index) => (
                                                <tr key={record.id || index}>
                                                    <td className="border border-gray-300 p-3 text-center">{index + 1}</td>
                                                    <td className="border border-gray-300 p-3">{record.protected_area?.name || 'N/A'}</td>
                                                    <td className="border border-gray-300 p-3 italic">{record.species_code}</td>
                                                    <td className="border border-gray-300 p-3 text-center">{record.dbh}</td>
                                                    <td className="border border-gray-300 p-3 text-center">{record.th}</td>
                                                    <td className="border border-gray-300 p-3 text-center">{record.mh}</td>
                                                    <td className="border border-gray-300 p-3">{record.bearing}</td>
                                                    <td className="border border-gray-300 p-3">{record.distance}</td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="8" className="text-center py-16 text-gray-500 italic">
                                                    No records found yet. Use "Encode Field Sheet".
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* TAB 2: ENCODE FORM */}
                    {activeTab === 'add' && (
                        <div className="max-w-5xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">
                            <div className="border-b border-gray-200 dark:border-gray-700 pb-4 mb-6">
                                <h3 className="text-xl font-bold text-gray-900 dark:text-white">Field Data Entry Sheet</h3>
                                <p className="text-sm text-gray-500">Enter the metadata headers and tree details according to the official manual sheet.</p>
                            </div>

                            <form onSubmit={submitRecord} className="space-y-6">
                                <div className="border border-gray-300 dark:border-gray-700 rounded-xl overflow-hidden bg-gray-50 dark:bg-gray-900/50 p-4 space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area:</label>
                                            <select value={form.data.protected_area_id} onChange={e => form.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" required>
                                                <option value="">Select Protected Area</option>
                                                {protectedAreas.map(pa => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Date:</label>
                                            <input type="date" value={form.data.date} onChange={e => form.setData('date', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Time:</label>
                                            <input type="text" placeholder="e.g. 08:30 AM" value={form.data.time} onChange={e => form.setData('time', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                        <div className="space-y-3">
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Plot No.</label>
                                                <input type="text" placeholder="Plot No." value={form.data.plot_no} onChange={e => form.setData('plot_no', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Quadrat No.</label>
                                                <input type="text" placeholder="Quadrat No." value={form.data.quadrat_no} onChange={e => form.setData('quadrat_no', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Transect No.</label>
                                                <input type="text" placeholder="Transect No." value={form.data.transect_no} onChange={e => form.setData('transect_no', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                        </div>

                                        <div className="space-y-3">
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Coordinates:</label>
                                                <div className="grid grid-cols-2 gap-2">
                                                    <input type="text" placeholder="N" value={form.data.lat} onChange={e => form.setData('lat', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                                    <input type="text" placeholder="E" value={form.data.long} onChange={e => form.setData('long', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Elevation (masl):</label>
                                                <input type="text" placeholder="masl" value={form.data.elevation} onChange={e => form.setData('elevation', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">GPS Unit:</label>
                                                <input type="text" placeholder="GPS Unit model" value={form.data.gps_unit} onChange={e => form.setData('gps_unit', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                        </div>

                                        <div className="space-y-3">
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Observer(s):</label>
                                                <textarea rows="2" placeholder="Observer names" value={form.data.observer} onChange={e => form.setData('observer', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs"></textarea>
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Vegetation Type:</label>
                                                <input type="text" placeholder="Vegetation Type" value={form.data.vegetation_type} onChange={e => form.setData('vegetation_type', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Weather:</label>
                                                <input type="text" placeholder="Weather condition" value={form.data.weather} onChange={e => form.setData('weather', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="space-y-3 bg-gray-50 dark:bg-gray-900/50 p-4 rounded-xl border border-gray-300 dark:border-gray-700">
                                    <h4 className="font-bold text-green-700 dark:text-green-400 text-sm">🌲 Tree / Species Record Entry</h4>

                                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Species Code</label>
                                            <input type="text" value={form.data.species_code} onChange={e => form.setData('species_code', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs italic" placeholder="e.g. Anonggo" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">DBH (cm)</label>
                                            <input type="text" value={form.data.dbh} onChange={e => form.setData('dbh', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" placeholder="18" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">TH (m)</label>
                                            <input type="text" value={form.data.th} onChange={e => form.setData('th', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" placeholder="10.3" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">MH (m)</label>
                                            <input type="text" value={form.data.mh} onChange={e => form.setData('mh', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" placeholder="4.2" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Bearing</label>
                                            <input type="text" value={form.data.bearing} onChange={e => form.setData('bearing', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" placeholder="N 67° E" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Distance (m)</label>
                                            <input type="text" value={form.data.distance} onChange={e => form.setData('distance', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" placeholder="4.2" />
                                        </div>
                                        <div className="md:col-span-2">
                                            <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Remarks</label>
                                            <input type="text" value={form.data.remarks} onChange={e => form.setData('remarks', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs" placeholder="Notes" />
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" disabled={form.processing} className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl shadow-sm transition text-sm">
                                    💾 Save Record
                                </button>
                            </form>
                        </div>
                    )}

                    {/* TAB 3: MAP VIEW */}
                    {activeTab === 'map' && (
                        <MapView bamsRecords={bamsRecords} spatialData={spatialData} />
                    )}

                    {/* TAB 4: EXCEL / CSV IMPORT */}
                    {activeTab === 'excel-import' && (
                        <div className="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">
                            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-1">Bulk Import Excel / CSV Data</h3>
                            <p className="text-sm text-gray-500 mb-6">Upload spreadsheet files following the BAMS data sheet template format.</p>

                            <form onSubmit={submitExcelImport} className="space-y-5">
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area</label>
                                    <select value={excelForm.data.protected_area_id} onChange={e => excelForm.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-sm" required>
                                        <option value="">Select Protected Area</option>
                                        {protectedAreas.map(pa => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Excel / CSV File</label>
                                    <div className="flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-xl p-2 bg-gray-50 dark:bg-gray-900">
                                        <label className="cursor-pointer bg-green-100 hover:bg-green-200 text-green-800 font-bold px-4 py-2 rounded-lg text-xs transition">
                                            Choose File
                                            <input type="file" name="file" accept=".xlsx, .xls, .csv" onChange={e => excelForm.setData('file', e.target.files[0])} className="hidden" required />
                                        </label>
                                        <span className="text-xs text-gray-500 truncate">
                                            {excelForm.data.file ? excelForm.data.file.name : "No file chosen"}
                                        </span>
                                    </div>
                                </div>
                                <button type="submit" disabled={excelForm.processing} className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl transition shadow-sm">
                                    🚀 Upload and Process Excel Data
                                </button>
                            </form>
                        </div>
                    )}

                    {/* TAB 5: SPATIAL FILE IMPORT */}
                    {activeTab === 'spatial-import' && (
                        <div className="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">
                            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-1">Import Spatial Boundary File</h3>
                            <p className="text-sm text-gray-500 mb-6">Upload GeoJSON / JSON files to render your spatial boundaries directly on the map.</p>

                            <form onSubmit={submitSpatialImport} className="space-y-5">
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area</label>
                                    <select value={spatialForm.data.protected_area_id} onChange={e => spatialForm.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-sm" required>
                                        <option value="">Select Protected Area</option>
                                        {protectedAreas.map(pa => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Spatial File (.geojson, .json)</label>
                                    <div className="flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-xl p-2 bg-gray-50 dark:bg-gray-900">
                                        <label className="cursor-pointer bg-green-100 hover:bg-green-200 text-green-800 font-bold px-4 py-2 rounded-lg text-xs transition">
                                            Choose Spatial File
                                            <input
                                                type="file"
                                                name="spatial_file"
                                                accept=".json, .geojson, .txt"
                                                onChange={e => spatialForm.setData('spatial_file', e.target.files[0])}
                                                className="hidden"
                                                required
                                            />
                                        </label>
                                        <span className="text-xs text-gray-500 truncate">
                                            {spatialForm.data.spatial_file ? spatialForm.data.spatial_file.name : "No file chosen"}
                                        </span>
                                    </div>
                                    <p className="text-[11px] text-gray-400 mt-1.5">Supported formats: GeoJSON (.geojson, .json) so they can be read directly by the system.</p>
                                </div>
                                <button type="submit" disabled={spatialForm.processing} className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl transition shadow-sm">
                                    🌐 Upload and Render Spatial Data
                                </button>
                            </form>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
