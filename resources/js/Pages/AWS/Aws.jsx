import { useState, useEffect } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import AwsTable from './AwsTable';
import AwsGraph from './AwsGraph';

export default function Aws({ awsRecords = [], rawRecords = [], chartRecords = [], protectedAreas = [], filters = {} }) {
    const { auth = {} } = usePage().props;

    // Kuhaon ang tab gikan sa URL kung naay ?tab=raw-data o ?tab=analytics
    const urlParams = new URLSearchParams(window.location.search);
    const urlTab = urlParams.get('tab');
    const initialTab = urlTab === 'raw-data' ? 'raw-data' : (urlTab === 'analytics' ? 'analytics' : 'reports');

    const [activeTab, setActiveTab] = useState(initialTab);

    // Reports records ug pagination
    const records = Array.isArray(awsRecords) ? awsRecords : (awsRecords.data || []);
    const pagination = Array.isArray(awsRecords) ? null : awsRecords;

    // Raw Data records ug pagination para sa pikas tab
    const rawDataList = Array.isArray(rawRecords) ? rawRecords : (rawRecords.data || []);
    const rawPagination = Array.isArray(rawRecords) ? null : rawRecords;

    const [selectedRecord, setSelectedRecord] = useState(null);
    const [isViewModalOpen, setIsViewModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);

    const [previewUrl, setPreviewUrl] = useState(null);
    const [existingFile, setExistingFile] = useState(null);

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Record created successfully.');

    const [deletingId, setDeletingId] = useState(null);
    const [selectedIds, setSelectedIds] = useState([]);

    useEffect(() => {
        if (records.length === 0 && rawDataList.length === 0 && selectedIds.length > 0) {
            setSelectedIds([]);
        }
    }, [records.length, rawDataList.length]);

    const { data, setData, post, processing, reset, errors, clearErrors } = useForm({
        protected_area_id: protectedAreas[0]?.id || '',
        station_name: '',
        location: '',
        report_period_type: 'Monthly',
        start_date: '',
        end_date: '',
        status: 'Approve',
        recommendation_remarks: '',
        report_file: null,
    });

    const importForm = useForm({
        file: null,
        protected_area_id: protectedAreas[0]?.id || '',
    });

    const handleTabChange = (tab) => {
        setActiveTab(tab);
        setSelectedIds([]);
    };

    const handleSelectAll = (e) => {
        const currentList = activeTab === 'raw-data' ? rawDataList : records;
        if (e.target.checked) {
            setSelectedIds(currentList.map((r) => r.id));
        } else {
            setSelectedIds([]);
        }
    };

    const handleSelectOne = (id, e) => {
        if (e) e.stopPropagation();
        if (selectedIds.includes(id)) {
            setSelectedIds(selectedIds.filter((item) => item !== id));
        } else {
            setSelectedIds([...selectedIds, id]);
        }
    };

    const handleFileChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData('report_file', file);
            setPreviewUrl(URL.createObjectURL(file));
        }
    };

    const removeFile = () => {
        setData({ ...data, report_file: null });
        setExistingFile(null);
        setPreviewUrl(null);
    };

    const handleFilterChange = (e) => {
        const paId = e.target.value;
        router.get(route('aws.index'), { protected_area_id: paId, tab: activeTab }, { preserveState: true });
    };

    const openCreateForm = () => {
        setSelectedRecord(null);
        clearErrors();
        reset();
        setPreviewUrl(null);
        setExistingFile(null);
        setData({
            protected_area_id: protectedAreas[0]?.id || '',
            station_name: '',
            location: '',
            report_period_type: 'Monthly',
            start_date: '',
            end_date: '',
            status: 'Approve',
            recommendation_remarks: '',
            report_file: null,
        });
        handleTabChange('form');
    };

    const openViewModal = (record) => {
        setSelectedRecord(record);
        setIsViewModalOpen(true);
    };

    const openEditModalFromView = (record) => {
        setIsViewModalOpen(false);
        setSelectedRecord(record);
        clearErrors();
        setData({
            protected_area_id: record.protected_area_id,
            station_name: record.station_name,
            location: record.location,
            report_period_type: record.report_period_type || 'Monthly',
            start_date: record.start_date || '',
            end_date: record.end_date || '',
            status: record.status || 'Approve',
            recommendation_remarks: record.recommendation_remarks || '',
            report_file: null,
        });

        const fileUrl = record.report_file_path ? `/storage/${record.report_file_path}` : null;
        setPreviewUrl(fileUrl);
        setExistingFile(record.report_file_path ? (record.report_file_name || record.report_file_path.split('/').pop()) : null);
        setIsEditModalOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (selectedRecord) {
            router.post(route('aws.update', selectedRecord.id), {
                ...data,
                _method: 'PUT',
            }, {
                forceFormData: true,
                onSuccess: () => {
                    reset();
                    setPreviewUrl(null);
                    setExistingFile(null);
                    setSelectedRecord(null);
                    setIsEditModalOpen(false);
                    setSuccessMessage('Record updated successfully.');
                    setShowSuccess(true);
                }
            });
        } else {
            post(route('aws.store'), {
                forceFormData: true,
                onSuccess: () => {
                    reset();
                    setPreviewUrl(null);
                    setExistingFile(null);
                    handleTabChange('reports');
                    setSuccessMessage('Record created successfully.');
                    setShowSuccess(true);
                }
            });
        }
    };

    const handleImportSubmit = (e) => {
        e.preventDefault();
        importForm.post(route('aws.import'), {
            onSuccess: () => {
                importForm.reset();
                setIsImportModalOpen(false);
                setActiveTab('raw-data');
                setSuccessMessage('Meteorological Data successfully imported.');
                setShowSuccess(true);
            },
        });
    };

    const promptDelete = (id) => {
        setDeletingId(id);
        setShowDeleteConfirm(true);
    };

    const confirmDelete = () => {
        if (!deletingId) return;
        router.delete(route('aws.destroy', deletingId), {
            onSuccess: () => {
                setShowDeleteConfirm(false);
                setIsEditModalOpen(false);
                setIsViewModalOpen(false);
                setDeletingId(null);
                setSelectedRecord(null);
                setSuccessMessage('Record deleted successfully.');
                setShowSuccess(true);
            }
        });
    };

    const confirmBulkDelete = () => {
        if (selectedIds.length === 0) return;
        router.post(route('aws.bulk-destroy'), { ids: selectedIds }, {
            onSuccess: () => {
                setShowBulkDeleteConfirm(false);
                setSelectedIds([]);
                setSuccessMessage('Selected records deleted successfully.');
                setShowSuccess(true);
            }
        });
    };

    return (
        <AuthenticatedLayout title="Automated Weather Stations (AWS)">
            <style>{`
                @keyframes stroke { 100% { stroke-dashoffset: 0; } }
                @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.15, 1.15, 1); } }
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
                .checkmark-circle { animation: scale 0.3s ease-in-out 0.3s both; }
                .checkmark-check { stroke-dasharray: 50; stroke-dashoffset: 50; animation: stroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.15s forwards; }
                .custom-table-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
                .custom-table-scrollbar::-webkit-scrollbar-thumb { background: rgba(156, 163, 175, 0.5); border-radius: 9999px; }
            `}</style>

            <div className="space-y-6">
                <div className="bg-gradient-to-r from-green-800 to-green-700 text-white p-6 rounded-2xl shadow-md flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-bold">Automated Weather Stations (AWS)</h1>
                        <p className="text-green-100 text-xs sm:text-sm mt-1">Consolidation of meteorological monitoring reports and document attachments.</p>
                    </div>
                    <div className="flex items-center gap-3 flex-wrap">
                        {activeTab !== 'form' && (
                            <>
                                <button
                                    onClick={() => setIsImportModalOpen(true)}
                                    className="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-xl text-xs font-bold text-white transition shadow-sm"
                                >
                                    📥 Import CSV
                                </button>
                                {auth.canCreateAws && (
                                    <button
                                        onClick={openCreateForm}
                                        className="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 px-4 py-2 rounded-xl text-xs font-bold text-white transition border border-white/20 shadow-sm"
                                    >
                                        + Add AWS Report
                                    </button>
                                )}
                            </>
                        )}
                        {activeTab === 'form' && (
                            <button
                                onClick={() => { handleTabChange('reports'); reset(); setPreviewUrl(null); setSelectedRecord(null); }}
                                className="inline-flex items-center gap-2 bg-gray-700 hover:bg-gray-800 px-4 py-2 rounded-xl text-xs font-bold text-white transition"
                            >
                                ← Back to Reports
                            </button>
                        )}
                    </div>
                </div>

                <div className="flex border-b border-gray-200 dark:border-gray-800 text-xs font-semibold gap-2">
                    <button
                        onClick={() => handleTabChange('reports')}
                        className={`pb-3 pt-2 px-4 font-bold border-b-2 transition flex items-center gap-2 ${
                            activeTab === 'reports' || activeTab === 'form'
                                ? 'border-green-600 text-green-600 dark:text-green-400 bg-green-50/50 dark:bg-green-950/20 rounded-t-xl'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-t-xl'
                        }`}
                    >
                        <span className="text-sm">📊</span> AWS Reports List
                    </button>
                    <button
                        onClick={() => handleTabChange('raw-data')}
                        className={`pb-3 pt-2 px-4 font-bold border-b-2 transition flex items-center gap-2 ${
                            activeTab === 'raw-data'
                                ? 'border-green-600 text-green-600 dark:text-green-400 bg-green-50/50 dark:bg-green-950/20 rounded-t-xl'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-t-xl'
                        }`}
                    >
                        <span className="text-sm">🏗️</span> AWS Raw Data Table
                    </button>
                    <button
                        onClick={() => handleTabChange('analytics')}
                        className={`pb-3 pt-2 px-4 font-bold border-b-2 transition flex items-center gap-2 ${
                            activeTab === 'analytics'
                                ? 'border-green-600 text-green-600 dark:text-green-400 bg-green-50/50 dark:bg-green-950/20 rounded-t-xl'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-t-xl'
                        }`}
                    >
                        <span className="text-sm">📈</span> Weather Analytics & Graph
                    </button>
                </div>

                {(activeTab === 'reports' || activeTab === 'raw-data') && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white dark:bg-gray-900 p-4 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                        <div className="w-full sm:w-72">
                            <label className="block text-[10px] font-bold text-gray-500 uppercase mb-1">Filter by Protected Area</label>
                            <select
                                value={filters.protected_area_id || ''}
                                onChange={handleFilterChange}
                                className="w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-xs px-3 py-2 font-medium"
                            >
                                <option value="">All Protected Areas</option>
                                {protectedAreas.map((pa) => (
                                    <option key={pa.id} value={pa.id}>{pa.name}</option>
                                ))}
                            </select>
                        </div>

                        {auth.canDeleteAws && ((activeTab === 'reports' && records.length > 0 && selectedIds.length > 0) || (activeTab === 'raw-data' && rawDataList.length > 0 && selectedIds.length > 0)) && (
                            <button
                                onClick={() => setShowBulkDeleteConfirm(true)}
                                className="inline-flex items-center gap-1.5 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-bold shadow-sm transition"
                            >
                                🗑️ Delete Selected ({selectedIds.length})
                            </button>
                        )}
                    </div>
                )}

                {/* REPORTS TABLE TAB */}
                {activeTab === 'reports' && (
                    <div className="space-y-4">
                        {records.length > 0 ? (
                            <Card className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl overflow-hidden" padding="p-0">
                                <div className="p-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                                    <div>
                                        <h3 className="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wider">AWS Reports List</h3>
                                        <p className="text-xs text-green-700 dark:text-green-400 font-semibold mt-0.5">📅 Consolidated AWS Monitoring Reports</p>
                                    </div>
                                    <span className="text-xs text-gray-500 italic">💡 Click any row to view full details</span>
                                </div>

                                <div className="overflow-x-auto custom-table-scrollbar">
                                    <table className="w-full text-left border-collapse text-xs">
                                        <thead>
                                            <tr className="border-b border-gray-200 bg-green-900 text-white uppercase tracking-wider dark:border-gray-700">
                                                <th className="px-3 py-3.5 w-10 text-center">
                                                    <input
                                                        type="checkbox"
                                                        onChange={handleSelectAll}
                                                        checked={records.length > 0 && selectedIds.length === records.length}
                                                        className="rounded border-gray-300 text-green-600 focus:ring-green-500"
                                                    />
                                                </th>
                                                <th className="px-4 py-3.5 font-semibold">Station Name</th>
                                                <th className="px-4 py-3.5 font-semibold">Location</th>
                                                <th className="px-4 py-3.5 font-semibold">Report Type</th>
                                                <th className="px-4 py-3.5 font-semibold">Start Date</th>
                                                <th className="px-4 py-3.5 font-semibold">End Date</th>
                                                <th className="px-4 py-3.5 font-semibold">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                                            {records.map((row) => (
                                                <tr
                                                    key={row.id}
                                                    onClick={() => openViewModal(row)}
                                                    className="cursor-pointer transition hover:bg-green-50/60 dark:hover:bg-green-950/30"
                                                >
                                                    <td className="px-3 py-3 text-center" onClick={(e) => e.stopPropagation()}>
                                                        <input
                                                            type="checkbox"
                                                            checked={selectedIds.includes(row.id)}
                                                            onChange={(e) => handleSelectOne(row.id, e)}
                                                            className="rounded border-gray-300 text-green-600 focus:ring-green-500"
                                                        />
                                                    </td>
                                                    <td className="px-4 py-3 font-semibold text-gray-900 dark:text-white">{row.station_name || '—'}</td>
                                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{row.location || '—'}</td>
                                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{row.report_period_type || 'Monthly'}</td>
                                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{row.start_date || '—'}</td>
                                                    <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{row.end_date || '—'}</td>
                                                    <td className="px-4 py-3">
                                                        <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold ${row.status === 'Approve' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'}`}>
                                                            {row.status || 'Approve'}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                        ) : (
                            <Card className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl flex flex-col items-center justify-center text-center py-24 px-6 bg-white dark:bg-gray-900">
                                <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/50 text-green-600 text-4xl mb-4 shadow-sm border border-green-100 dark:border-green-900">
                                    📄
                                </div>
                                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">
                                    No AWS Reports Found
                                </h3>
                                <p className="text-sm text-gray-500 dark:text-gray-400 max-w-sm mx-auto">
                                    No monitoring reports have been created yet. Click <strong className="text-gray-700 dark:text-gray-200">"+ Add AWS Report"</strong> to submit your first weather assessment.
                                </p>
                            </Card>
                        )}

                        {pagination?.links?.length > 3 && (
                            <nav className="flex flex-wrap items-center justify-end gap-1" aria-label="AWS pagination">
                                {pagination.links.map((link, index) => (
                                    <button
                                        key={`${link.label}-${index}`}
                                        type="button"
                                        disabled={!link.url || link.active}
                                        onClick={() =>
                                            link.url &&
                                            router.get(link.url, {}, { preserveState: true, preserveScroll: true })
                                        }
                                        className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${
                                            link.active
                                                ? 'bg-green-700 text-white'
                                                : link.url
                                                ? 'bg-white text-gray-700 hover:bg-green-50'
                                                : 'cursor-not-allowed text-gray-400'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </nav>
                        )}
                    </div>
                )}

                {/* RAW DATA TABLE TAB */}
                {activeTab === 'raw-data' && (
                    <AwsTable
                        records={rawDataList}
                        selectedIds={selectedIds}
                        handleSelectAll={handleSelectAll}
                        handleSelectOne={handleSelectOne}
                        pagination={rawPagination}
                    />
                )}

                {/* ANALYTICS & GRAPH TAB */}
                {activeTab === 'analytics' && (
                    <AwsGraph
                        chartRecords={chartRecords}
                        protectedAreas={protectedAreas}
                        filters={filters}
                    />
                )}

                {/* FORM TAB */}
                {activeTab === 'form' && (
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                        <div className="lg:col-span-7">
                            <Card padding="p-6 sm:p-8" className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl bg-white dark:bg-gray-900">
                                <form onSubmit={submit} id="awsForm" className="space-y-6">
                                    {Object.keys(errors).length > 0 && (
                                        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-medium text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300 space-y-1">
                                            <p className="font-bold">Please fix the following error(s):</p>
                                            <ul className="list-disc pl-4 space-y-0.5">
                                                {Object.values(errors).map((err, idx) => (
                                                    <li key={idx}>{err}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <div className="bg-gray-50/70 dark:bg-gray-800/40 p-5 rounded-2xl border border-gray-100 dark:border-gray-800 space-y-4">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-2 flex items-center gap-2">
                                            <span>📌</span> General Information & Status
                                        </h4>

                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Protected Area *</label>
                                                <select
                                                    value={data.protected_area_id}
                                                    onChange={(e) => setData('protected_area_id', e.target.value)}
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                    required
                                                >
                                                    <option value="">Select Protected Area</option>
                                                    {protectedAreas.map((pa) => (
                                                        <option key={pa.id} value={pa.id}>{pa.name}</option>
                                                    ))}
                                                </select>
                                                {errors.protected_area_id && <div className="text-red-500 text-xs mt-1">{errors.protected_area_id}</div>}
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Status *</label>
                                                <select
                                                    value={data.status}
                                                    onChange={(e) => setData('status', e.target.value)}
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                    required
                                                >
                                                    <option value="Approve">Approve</option>
                                                    <option value="Pending">Pending (For Review)</option>
                                                    <option value="Under Maintenance">Under Maintenance</option>
                                                </select>
                                                {errors.status && <div className="text-red-500 text-xs mt-1">{errors.status}</div>}
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Station Name *</label>
                                                <input
                                                    type="text"
                                                    value={data.station_name}
                                                    onChange={(e) => setData('station_name', e.target.value)}
                                                    placeholder="e.g. Mount Hamiguitan AWS"
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                    required
                                                />
                                                {errors.station_name && <div className="text-red-500 text-xs mt-1">{errors.station_name}</div>}
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Location *</label>
                                                <input
                                                    type="text"
                                                    value={data.location}
                                                    onChange={(e) => setData('location', e.target.value)}
                                                    placeholder="e.g. Sitio Tumalite, Brgy. La Union"
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                    required
                                                />
                                                {errors.location && <div className="text-red-500 text-xs mt-1">{errors.location}</div>}
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Report Type *</label>
                                                <select
                                                    value={data.report_period_type}
                                                    onChange={(e) => setData('report_period_type', e.target.value)}
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                >
                                                    <option value="Monthly">Monthly</option>
                                                    <option value="Quarterly">Quarterly</option>
                                                    <option value="Semestral">Semestral</option>
                                                </select>
                                                {errors.report_period_type && <div className="text-red-500 text-xs mt-1">{errors.report_period_type}</div>}
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Start Date *</label>
                                                <input
                                                    type="date"
                                                    value={data.start_date}
                                                    onChange={(e) => setData('start_date', e.target.value)}
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                    required
                                                />
                                                {errors.start_date && <div className="text-red-500 text-xs mt-1">{errors.start_date}</div>}
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">End Date *</label>
                                                <input
                                                    type="date"
                                                    value={data.end_date}
                                                    onChange={(e) => setData('end_date', e.target.value)}
                                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                                    required
                                                />
                                                {errors.end_date && <div className="text-red-500 text-xs mt-1">{errors.end_date}</div>}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="pt-2">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-3 flex items-center gap-2">
                                            <span>📋</span> Recommendation & Remarks
                                        </h4>
                                        <textarea
                                            rows="3"
                                            placeholder="Enter recommendation or remarks here..."
                                            value={data.recommendation_remarks}
                                            onChange={(e) => setData('recommendation_remarks', e.target.value)}
                                            className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs focus:border-green-600 focus:ring-green-600"
                                        ></textarea>
                                        {errors.recommendation_remarks && <div className="text-red-500 text-xs mt-1">{errors.recommendation_remarks}</div>}
                                    </div>

                                    <div className="border-t border-gray-100 dark:border-gray-800 pt-6">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-3 flex items-center gap-2">
                                            <span>📎</span> Attach Supporting Document
                                        </h4>
                                        <div className="space-y-3">
                                            <input
                                                type="file"
                                                accept=".xlsx, .xls, .docx, .pdf"
                                                onChange={handleFileChange}
                                                className="block w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 border border-gray-300 dark:border-gray-700 rounded-xl"
                                                required={!existingFile}
                                            />
                                            {errors.report_file && <div className="text-red-500 text-xs mt-1">{errors.report_file}</div>}

                                            {data.report_file && (
                                                <div className="flex items-center gap-2 bg-blue-600 text-white px-3.5 py-2 rounded-xl text-xs font-medium shadow-xs w-fit">
                                                    <span>📄 {data.report_file.name}</span>
                                                    <button type="button" onClick={removeFile} className="text-white/80 hover:text-white font-bold ml-1">✕</button>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                                        <button
                                            type="button"
                                            onClick={() => { handleTabChange('reports'); reset(); setPreviewUrl(null); }}
                                            className="rounded-xl border border-gray-300 px-5 py-2.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition shadow-xs"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="rounded-xl bg-green-700 hover:bg-green-800 px-6 py-2.5 text-xs font-bold text-white shadow-md transition flex items-center gap-1.5"
                                        >
                                            💾 Save Assessment Record
                                        </button>
                                    </div>
                                </form>
                            </Card>
                        </div>

                        <div className="lg:col-span-5 sticky top-28">
                            <Card padding="p-5" className="border border-gray-200 dark:border-gray-800 shadow-xl rounded-2xl bg-white dark:bg-gray-900">
                                <div className="flex items-center justify-between mb-3 pb-3 border-b border-gray-100 dark:border-gray-800">
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 flex items-center gap-2">
                                        <span>👁️</span> LIVE DOCUMENT PREVIEW {previewUrl && <span className="text-gray-500 text-[11px] font-normal ml-1 truncate max-w-[150px]">({data.report_file?.name})</span>}
                                    </h3>
                                    {previewUrl && (
                                        <a href={previewUrl} target="_blank" rel="noopener noreferrer" className="text-xs font-semibold text-green-700 dark:text-green-400 hover:underline">
                                            Fullscreen ↗
                                        </a>
                                    )}
                                </div>

                                {previewUrl ? (
                                    <div className="w-full h-[620px] bg-gray-100 dark:bg-gray-950 rounded-xl overflow-hidden border border-gray-300 dark:border-gray-800 shadow-inner flex items-center justify-center">
                                        <iframe src={previewUrl} title="Document Preview" className="w-full h-full border-0" />
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center h-[620px] text-center p-8 bg-gray-50 dark:bg-gray-950/50 rounded-xl border border-dashed border-gray-200 dark:border-gray-800">
                                        <span className="text-4xl mb-3">📁</span>
                                        <h4 className="text-sm font-semibold text-gray-800 dark:text-white">No file selected for preview</h4>
                                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xs">
                                            Upload a supporting document or report on the left form to view it here live.
                                        </p>
                                    </div>
                                )}
                            </Card>
                        </div>
                    </div>
                )}
            </div>

            {/* IMPORT CSV MODAL */}
            {isImportModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                    <div className="bg-white dark:bg-gray-900 rounded-2xl p-6 max-w-lg w-full shadow-2xl border border-gray-200 dark:border-gray-800 animate-pop-in space-y-6">
                        <div className="flex items-center justify-between border-b pb-4 dark:border-gray-800">
                            <h3 className="font-bold text-gray-900 dark:text-white text-base flex items-center gap-2">
                                <span>📥</span> Import Meteorological Data from CSV
                            </h3>
                            <button type="button" onClick={() => setIsImportModalOpen(false)} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>

                        <form onSubmit={handleImportSubmit} className="space-y-4">
                            <p className="text-xs text-gray-500">Upload a formatted CSV file and select the target Protected Area.</p>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Protected Area *</label>
                                <select
                                    value={importForm.data.protected_area_id || ''}
                                    onChange={(e) => importForm.setData('protected_area_id', e.target.value)}
                                    className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs"
                                    required
                                >
                                    <option value="">Select Protected Area</option>
                                    {protectedAreas.map((pa) => (
                                        <option key={pa.id} value={pa.id}>{pa.name}</option>
                                    ))}
                                </select>
                                {importForm.errors.protected_area_id && <div className="text-red-500 text-xs mt-1">{importForm.errors.protected_area_id}</div>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Select CSV File (.csv)</label>
                                <input
                                    type="file"
                                    accept=".csv, .txt"
                                    onChange={(e) => importForm.setData('file', e.target.files[0])}
                                    className="block w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-gray-300 dark:border-gray-700 rounded-xl"
                                    required
                                />
                                {importForm.errors.file && (
                                    <div className="max-h-28 overflow-y-auto text-red-600 dark:text-red-400 text-xs mt-2 font-semibold p-2.5 bg-red-50 dark:bg-red-950/50 rounded-xl border border-red-200 dark:border-red-900">
                                        ❌ {importForm.errors.file}
                                    </div>
                                )}
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-4 border-t dark:border-gray-800">
                                <button
                                    type="button"
                                    onClick={() => setIsImportModalOpen(false)}
                                    className="px-5 py-2.5 rounded-xl border text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={importForm.processing}
                                    className="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold shadow-sm transition flex items-center gap-1.5"
                                >
                                    {importForm.processing ? 'Importing...' : '🚀 Upload & Import CSV'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* VIEW MODAL */}
            {isViewModalOpen && selectedRecord && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs overflow-y-auto">
                    <div className="relative w-full max-w-3xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[90vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <div>
                                <h3 className="font-bold text-gray-900 dark:text-white text-base">AWS Monitoring Full Details</h3>
                                <p className="text-xs text-gray-500">{selectedRecord.protected_area?.name || 'N/A'} — Station: {selectedRecord.station_name}</p>
                            </div>
                            <button type="button" onClick={() => setIsViewModalOpen(false)} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-6 flex-1 text-sm">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Report Type</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedRecord.report_period_type}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Status</span>
                                    <span className="inline-block mt-0.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300">{selectedRecord.status}</span>
                                </div>
                                <div className="col-span-2">
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Monitoring Date Range</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedRecord.start_date} to {selectedRecord.end_date}</span>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📍 Station Location Information</h4>
                                <div className="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 space-y-2 text-xs">
                                    <div><span className="text-gray-500 block">Station Name:</span><span className="font-semibold text-gray-800 dark:text-gray-200 text-sm">{selectedRecord.station_name}</span></div>
                                    <div><span className="text-gray-500 block">Location:</span><span className="font-semibold text-gray-800 dark:text-gray-200 text-sm">{selectedRecord.location}</span></div>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📝 Recommendation & Remarks</h4>
                                <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 text-xs">
                                    <p className="text-gray-800 dark:text-gray-200">{selectedRecord.recommendation_remarks || 'No remarks provided.'}</p>
                                </div>
                            </div>

                            {selectedRecord.report_file_path && (
                                <div className="space-y-2">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📎 Attached Document</h4>
                                    <a
                                        href={`/storage/${selectedRecord.report_file_path}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-2 bg-green-700 text-white px-4 py-2 rounded-xl text-xs font-medium shadow-xs hover:bg-green-800 transition"
                                    >
                                        <span>📄 View / Download Attached Document ↗</span>
                                    </a>
                                </div>
                            )}
                        </div>

                        <div className="flex items-center justify-between px-6 py-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800">
                            {auth.canUpdateAws ? (
                                <button type="button" onClick={() => openEditModalFromView(selectedRecord)} className="rounded-xl bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 hover:bg-green-100 border border-green-200 transition">✏️ Edit This Record</button>
                            ) : <div></div>}
                            <button type="button" onClick={() => setIsViewModalOpen(false)} className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2 text-xs font-bold text-white shadow-md transition">Close Details</button>
                        </div>
                    </div>
                </div>
            )}

            {/* EDIT MODAL */}
            {isEditModalOpen && selectedRecord && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs">
                    <div className="relative w-full max-w-7xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[92vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <div className="flex items-center gap-2">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-400">🌤️</span>
                                <div>
                                    <h3 className="font-bold text-gray-900 dark:text-white text-sm sm:text-base">Edit AWS Report & Document Preview</h3>
                                    <p className="text-xs text-gray-500">Update weather station details and review attached files side-by-side.</p>
                                </div>
                            </div>
                            <button type="button" onClick={() => setIsEditModalOpen(false)} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 font-bold text-lg">✕</button>
                        </div>

                        <div className="flex-1 grid grid-cols-1 lg:grid-cols-12 gap-6 p-6 overflow-y-auto custom-table-scrollbar">
                            <div className="lg:col-span-6 space-y-5">
                                <form onSubmit={submit} id="edit-aws-form" className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Protected Area *</label>
                                        <select
                                            value={data.protected_area_id}
                                            onChange={(e) => setData('protected_area_id', e.target.value)}
                                            className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs"
                                            required
                                        >
                                            <option value="">Select Protected Area</option>
                                            {protectedAreas.map((pa) => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                        </select>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Station Name *</label>
                                            <input type="text" value={data.station_name} onChange={(e) => setData('station_name', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" required />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Location *</label>
                                            <input type="text" value={data.location} onChange={(e) => setData('location', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" required />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Report Type *</label>
                                            <select value={data.report_period_type} onChange={(e) => setData('report_period_type', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                                <option value="Monthly">Monthly</option>
                                                <option value="Quarterly">Quarterly</option>
                                                <option value="Semestral">Semestral</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Status *</label>
                                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm font-semibold shadow-xs">
                                                <option value="Approve">Approve</option>
                                                <option value="Pending">Pending</option>
                                                <option value="Under Maintenance">Under Maintenance</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Start Date *</label>
                                            <input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" required />
                                        </div>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">End Date *</label>
                                        <input type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" required />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Recommendation & Remarks</label>
                                        <textarea rows="2" value={data.recommendation_remarks} onChange={(e) => setData('recommendation_remarks', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>

                                    <div className="pt-2 border-t border-gray-100 dark:border-gray-800 space-y-3">
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Upload New Document File</label>
                                        <input type="file" accept=".pdf,.xlsx,.xls,.docx" onChange={handleFileChange} className="block w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 border border-gray-300 dark:border-gray-700 rounded-xl" />

                                        {existingFile && !data.report_file && (
                                            <div className="flex items-center gap-2 bg-green-700 text-white px-3 py-1.5 rounded-xl text-xs font-medium shadow-xs w-fit">
                                                <span>📄 {existingFile}</span>
                                                <button type="button" onClick={removeFile} className="text-white/80 hover:text-white font-bold ml-1">✕</button>
                                            </div>
                                        )}

                                        {data.report_file && (
                                            <div className="flex items-center gap-2 bg-blue-600 text-white px-3 py-1.5 rounded-xl text-xs font-medium shadow-xs w-fit">
                                                <span>📄 {data.report_file.name}</span>
                                                <button type="button" onClick={removeFile} className="text-white/80 hover:text-white font-bold ml-1">✕</button>
                                            </div>
                                        )}
                                    </div>
                                </form>
                            </div>

                            <div className="lg:col-span-6 flex flex-col bg-gray-50 dark:bg-gray-950 p-4 rounded-2xl border border-gray-200 dark:border-gray-800 h-[600px] sticky top-4">
                                <div className="flex items-center justify-between mb-3 pb-2 border-b border-gray-200 dark:border-gray-800">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        👁️ LIVE DOCUMENT PREVIEW {previewUrl && <span className="normal-case text-gray-500 text-[11px] font-normal ml-1">({data.report_file?.name || existingFile})</span>}
                                    </h4>
                                    {previewUrl && (<a href={previewUrl} target="_blank" rel="noopener noreferrer" className="text-xs font-semibold text-green-700 hover:underline">Fullscreen ↗</a>)}
                                </div>
                                <div className="flex-1 w-full bg-white dark:bg-gray-900 rounded-xl overflow-hidden border border-gray-300 dark:border-gray-800 flex items-center justify-center">
                                    {previewUrl ? (<iframe src={previewUrl} title="Document Preview" className="w-full h-full border-0" />) : (<div className="text-center p-6 text-gray-400 text-xs">📁 No file selected or available for preview</div>)}
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between px-6 py-4 border-t border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            {auth.canDeleteAws ? (
                                <button type="button" onClick={() => promptDelete(selectedRecord.id)} className="rounded-xl bg-red-50 px-4 py-2.5 text-xs font-semibold text-red-700 hover:bg-red-100 border border-red-200 transition">🗑️ Delete Record</button>
                            ) : <div></div>}
                            <div className="flex gap-2">
                                <button type="button" onClick={() => { setIsEditModalOpen(false); openViewModal(selectedRecord); }} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">← Back</button>
                                <button type="submit" form="edit-aws-form" disabled={processing} className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2.5 text-xs font-bold text-white shadow-md transition">💾 Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* BULK DELETE CONFIRMATION */}
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

            {/* DELETE CONFIRMATION */}
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

            {/* SUCCESS MODAL */}
            {showSuccess && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
                    <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center animate-pop-in">
                        <div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-950 mb-4 shadow-sm">
                            <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor">
                                <path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 font-sans">Success!</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">{successMessage}</p>
                        <button
                            type="button"
                            onClick={() => { setShowSuccess(false); }}
                            className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition text-sm"
                        >
                            Continue
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
