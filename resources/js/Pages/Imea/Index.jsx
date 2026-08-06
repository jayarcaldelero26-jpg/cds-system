import { Link, useForm, usePage, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Card';
import StatusBadge from '@/Components/StatusBadge';

export default function ImeaIndex({ assessments, facilities = { data: [] }, protectedAreas }) {
    const { props } = usePage();

    const [activeTab, setActiveTab] = useState('assessments');

    // IMEA Assessment Modal States
    const [selectedAssessment, setSelectedAssessment] = useState(null);
    const [isViewAssessmentModalOpen, setIsViewAssessmentModalOpen] = useState(false);
    const [isAssessmentModalOpen, setIsAssessmentModalOpen] = useState(false);

    // Facility Modal States
    const [selectedFacility, setSelectedFacility] = useState(null);
    const [isFacilityModalOpen, setIsFacilityModalOpen] = useState(false);
    const [isViewFacilityModalOpen, setIsViewFacilityModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);

    const [selectedFacilityIds, setSelectedFacilityIds] = useState([]);
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [showFacilityDeleteConfirm, setShowFacilityDeleteConfirm] = useState(false);
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
    const [showSuccess, setShowSuccess] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Action completed successfully.');

    const [attachedFiles, setAttachedFiles] = useState([]);
    const [existingFiles, setExistingFiles] = useState([]);
    const [activePreview, setActivePreview] = useState(null);

    useEffect(() => {
        if (props.flash?.status) {
            if (props.flash.status === 'imea-assessment-created' || props.flash.status === 'facility-created') {
                setSuccessMessage('Record created successfully.');
                setShowSuccess(true);
            } else if (props.flash.status === 'facility-updated' || props.flash.status === 'imea-assessment-updated') {
                setSuccessMessage('Record updated successfully.');
                setShowSuccess(true);
            } else if (props.flash.status === 'facility-deleted' || props.flash.status === 'imea-assessment-deleted') {
                setSuccessMessage('Record deleted successfully.');
                setShowSuccess(true);
            } else if (props.flash.status === 'facility-imported') {
                setSuccessMessage('Facilities imported successfully from Excel.');
                setShowSuccess(true);
            }
        }
    }, [props.flash]);

    const { data, setData, delete: destroy, processing, reset, errors } = useForm({
        protected_area_id: '',
        pamo_name: '',
        assessment_year: '',
        assessment_period: 'Annual',
        visitor_arrivals: '',
        trail_condition: '',
        solid_waste_generation_kg: '',
        wildlife_disturbance: '',
        vegetation_damage: '',
        water_quality: '',
        carrying_capacity_compliance: true,
        community_benefits_income: '',
        visitor_satisfaction_rate: '',
        biodiversity_impact_notes: '',
        environment_impact_notes: '',
        social_cultural_impact_notes: '',
        economic_impact_notes: '',
        general_remarks: '',
        status: 'Pending',
        attachments: [],
        removed_attachments: [],
    });

    // Facility Form
    const facilityForm = useForm({
        protected_area_id: '',
        inventory_date: '',
        facility_type: '',
        unit_no: 1,
        year_established: '',
        location_brgy_muni: '',
        management_zone: 'MUZ',
        within_easement_zone: 'No',
        coordinates: '',
        source_of_fund: '',
        description: '',
        status: 'Functional',
        typhoon_affected: 'No',
        tenurial_instrument: '',
        recommendations: '',
        remarks: '',
        attachments: [],
    });

    const importForm = useForm({
        protected_area_id: '',
        file: null,
    });

    const openViewAssessmentModal = (row) => {
        setSelectedAssessment(row);
        setAttachedFiles([]);

        let rawFiles = [];
        const possibleValues = [row.attachments, row.file_path, row.attachment, row.file, row.documents, row.document, row.media];
        for (const val of possibleValues) {
            if (val) {
                if (Array.isArray(val)) { rawFiles = val; break; }
                else if (typeof val === 'string') { try { const parsed = JSON.parse(val); rawFiles = Array.isArray(parsed) ? parsed : [parsed]; break; } catch(e) { rawFiles = [val]; break; } }
                else if (typeof val === 'object') { rawFiles = [val]; break; }
            }
        }
        const formattedExisting = rawFiles.map((file, idx) => {
            const filePath = typeof file === 'string' ? file : (file.url || file.path || file.file_path || file.file_name);
            const fileName = typeof file === 'string' ? file.split('/').pop() : (file.name || file.original_name || `Document ${idx + 1}`);
            const fileUrl = filePath && filePath.startsWith('http') ? filePath : `/storage/${filePath}`;
            return { id: file.id || idx, name: fileName, url: fileUrl, original: file };
        });
        setExistingFiles(formattedExisting);
        setActivePreview(formattedExisting.length > 0 ? formattedExisting[0] : null);

        setData({
            protected_area_id: row.protected_area_id || '',
            pamo_name: row.pamo_name || '',
            assessment_year: row.assessment_year || '',
            assessment_period: row.assessment_period || 'Annual',
            visitor_arrivals: row.visitor_arrivals || '',
            trail_condition: row.trail_condition || '',
            solid_waste_generation_kg: row.solid_waste_generation_kg || '',
            wildlife_disturbance: row.wildlife_disturbance || '',
            vegetation_damage: row.vegetation_damage || '',
            water_quality: row.water_quality || '',
            carrying_capacity_compliance: row.carrying_capacity_compliance === 1 || row.carrying_capacity_compliance === true,
            community_benefits_income: row.community_benefits_income || '',
            visitor_satisfaction_rate: row.visitor_satisfaction_rate || '',
            biodiversity_impact_notes: row.biodiversity_impact_notes || '',
            environment_impact_notes: row.environment_impact_notes || '',
            social_cultural_impact_notes: row.social_cultural_impact_notes || '',
            economic_impact_notes: row.economic_impact_notes || '',
            general_remarks: row.general_remarks || '',
            status: row.status || 'Pending',
            attachments: [],
            removed_attachments: [],
        });
        setIsViewAssessmentModalOpen(true);
    };

    const openAssessmentEditModal = (row) => {
        setIsViewAssessmentModalOpen(false);
        openViewAssessmentModal(row);
        setIsAssessmentModalOpen(true);
    };

    const openFacilityModal = (facility = null) => {
        facilityForm.clearErrors();
        if (facility) {
            setSelectedFacility(facility);
            facilityForm.setData({
                protected_area_id: facility.protected_area_id || '',
                inventory_date: facility.inventory_date || '',
                facility_type: facility.facility_type || '',
                unit_no: facility.unit_no || 1,
                year_established: facility.year_established || '',
                location_brgy_muni: facility.location_brgy_muni || '',
                management_zone: facility.management_zone || 'MUZ',
                within_easement_zone: facility.within_easement_zone || 'No',
                coordinates: facility.coordinates || '',
                source_of_fund: facility.source_of_fund || '',
                description: facility.description || '',
                status: facility.status || 'Functional',
                typhoon_affected: facility.typhoon_affected || 'No',
                tenurial_instrument: facility.tenurial_instrument || '',
                recommendations: facility.recommendations || '',
                remarks: facility.remarks || '',
                attachments: [],
            });
        } else {
            setSelectedFacility(null);
            facilityForm.reset();
        }
        setIsFacilityModalOpen(true);
    };

    const openViewFacilityModal = (facility) => {
        setSelectedFacility(facility);
        setIsViewFacilityModalOpen(true);
    };

    const closeAssessmentModal = () => {
        setIsAssessmentModalOpen(false);
        setSelectedAssessment(null);
        setAttachedFiles([]);
        setExistingFiles([]);
        setActivePreview(null);
        reset();
    };

    const closeFacilityModal = () => {
        setIsFacilityModalOpen(false);
        setSelectedFacility(null);
        facilityForm.reset();
        facilityForm.clearErrors();
    };

    const handleUpdate = (e) => {
        e.preventDefault();
        if (!selectedAssessment) return;
        router.post(`/imea/${selectedAssessment.id}`, {
            _method: 'PUT',
            ...data,
            attachments: attachedFiles.map(item => item.file),
        }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsAssessmentModalOpen(false);
                setSuccessMessage('Assessment updated successfully.');
                setShowSuccess(true);
            },
        });
    };

    const handleFacilitySubmit = (e) => {
        e.preventDefault();

        if (selectedFacility) {
            facilityForm.put(`/imea/facilities/${selectedFacility.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    closeFacilityModal();
                    setSuccessMessage('Facility updated successfully.');
                    setShowSuccess(true);
                }
            });
        } else {
            facilityForm.post('/imea/facilities', {
                preserveScroll: true,
                onSuccess: () => {
                    closeFacilityModal();
                    setSuccessMessage('Facility created successfully.');
                    setShowSuccess(true);
                }
            });
        }
    };

    const handleImportSubmit = (e) => {
        e.preventDefault();
        importForm.post('/imea/facilities-import', {
            preserveScroll: true,
            onSuccess: () => {
                setIsImportModalOpen(false);
                importForm.reset();
            },
        });
    };

    const handleBulkDelete = () => { if (selectedFacilityIds.length === 0) return; setShowBulkDeleteConfirm(true); };
    const confirmBulkDelete = () => {
        router.post('/imea/facilities-bulk-delete', { ids: selectedFacilityIds }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowBulkDeleteConfirm(false);
                setSelectedFacilityIds([]);
                setSuccessMessage('Selected facilities deleted successfully.');
                setShowSuccess(true);
            }
        });
    };

    const toggleSelectAll = (e) => { e.target.checked ? setSelectedFacilityIds(facilities.data.map(f => f.id)) : setSelectedFacilityIds([]); };
    const toggleSelectFacility = (id) => { selectedFacilityIds.includes(id) ? setSelectedFacilityIds(selectedFacilityIds.filter(item => item !== id)) : setSelectedFacilityIds([...selectedFacilityIds, id]); };

    const confirmDelete = () => {
        if (!selectedAssessment) return;
        destroy(`/imea/${selectedAssessment.id}`, { preserveScroll: false, onSuccess: () => { setShowDeleteConfirm(false); setIsAssessmentModalOpen(false); setIsViewAssessmentModalOpen(false); setSuccessMessage('Record deleted successfully.'); setShowSuccess(true); } });
    };

    const confirmFacilityDelete = () => {
        if (!selectedFacility) return;
        router.delete(`/imea/facilities/${selectedFacility.id}`, { preserveScroll: true, onSuccess: () => { setShowFacilityDeleteConfirm(false); closeFacilityModal(); setIsViewFacilityModalOpen(false); setSuccessMessage('Facility deleted successfully.'); setShowSuccess(true); } });
    };

    return (
        <AuthenticatedLayout title="IMEA Monitoring">
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

            <div className="sticky top-20 z-10 relative overflow-hidden rounded-xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-md mb-6">
                <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/15 blur-2xl pointer-events-none"></div>
                <div className="relative z-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl sm:text-2xl font-bold tracking-tight">Integrated Protected Area Ecotourism Monitoring (IMEA)</h1>
                        <p className="text-xs sm:text-sm text-green-100 mt-1 opacity-90">Consolidation of ecotourism impact assessments and infrastructure inventories of PAMOs.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        {activeTab === 'assessments' ? (
                            <>
                                <Link href="/imea/report" className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">📊 View Summary Report</Link>
                                <Link href="/imea/create" className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">+ Add IMEA Assessment</Link>
                            </>
                        ) : (
                            <div className="flex items-center gap-2">
                                <Link href="/imea/facilities-report" className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">📊 View Facilities Summary Report</Link>
                                <button onClick={() => setIsImportModalOpen(true)} className="inline-flex items-center justify-center rounded-xl bg-blue-600/80 hover:bg-blue-600 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">📥 Import CSV</button>
                                <button onClick={() => openFacilityModal()} className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">+ Add Facility / Infrastructure</button>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="flex border-b border-gray-200 dark:border-gray-700 mb-6 gap-6">
                <button type="button" onClick={() => setActiveTab('assessments')} className={`pb-3 px-4 text-sm font-bold border-b-2 transition flex items-center gap-2 ${activeTab === 'assessments' ? 'border-green-700 text-green-700 dark:text-green-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'}`}>📊 IMEA Assessments</button>
                <button type="button" onClick={() => setActiveTab('facilities')} className={`pb-3 px-4 text-sm font-bold border-b-2 transition flex items-center gap-2 ${activeTab === 'facilities' ? 'border-green-700 text-green-700 dark:text-green-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'}`}>🏗️ Facilities & Infrastructures Inventory</button>
            </div>

            {/* TAB 1: IMEA ASSESSMENTS */}
            {activeTab === 'assessments' && (
                <Card padding="p-0" className="border border-gray-100 dark:border-gray-800 overflow-hidden shadow-xl rounded-2xl">
                    {assessments?.data?.length > 0 ? (
                        <div className="overflow-x-auto custom-table-scrollbar">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="border-b border-gray-200 bg-green-900 text-white text-xs uppercase tracking-wider dark:border-gray-700">
                                        <th className="px-6 py-3.5 font-semibold">Protected Area</th>
                                        <th className="px-6 py-3.5 font-semibold">PAMO Office</th>
                                        <th className="px-6 py-3.5 font-semibold">Status</th>
                                        <th className="px-6 py-3.5 font-semibold">Year / Period</th>
                                        <th className="px-6 py-3.5 font-semibold">Visitor Arrivals</th>
                                        <th className="px-6 py-3.5 font-semibold">Carrying Capacity</th>
                                        <th className="px-6 py-3.5 font-semibold">Satisfaction</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 text-sm dark:divide-gray-800">
                                    {assessments.data.map((row) => (
                                        <tr key={row.id} onClick={() => openViewAssessmentModal(row)} className="cursor-pointer transition hover:bg-green-50/60 dark:hover:bg-green-950/30">
                                            <td className="px-6 py-4 font-semibold text-gray-900 dark:text-white">{row.protected_area?.name || 'N/A'}</td>
                                            <td className="px-6 py-4 text-gray-700 dark:text-gray-300">{row.pamo_name}</td>
                                            <td className="px-6 py-4"><StatusBadge variant={row.status === 'Approved' ? 'active' : 'pending'}>{row.status || 'Pending'}</StatusBadge></td>
                                            <td className="px-6 py-4 text-gray-700 dark:text-gray-300">{row.assessment_year} ({row.assessment_period})</td>
                                            <td className="px-6 py-4 text-gray-700 dark:text-gray-300">{row.visitor_arrivals ?? '0'}</td>
                                            <td className="px-6 py-4"><StatusBadge variant={row.carrying_capacity_compliance ? 'active' : 'pending'}>{row.carrying_capacity_compliance ? 'Compliant' : 'Exceeded'}</StatusBadge></td>
                                            <td className="px-6 py-4 text-gray-700 dark:text-gray-300">{row.visitor_satisfaction_rate ? `${row.visitor_satisfaction_rate}%` : 'N/A'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-12 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/50 text-green-700 dark:text-green-400 mb-3 text-xl">🌿</div>
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-white">No IMEA assessments recorded yet</h3>
                        </div>
                    )}
                </Card>
            )}

            {/* TAB 2: FACILITIES & INFRASTRUCTURES INVENTORY */}
            {activeTab === 'facilities' && (
                <Card padding="p-0" className="border border-gray-100 dark:border-gray-800 overflow-hidden shadow-xl rounded-2xl">
                    {selectedFacilityIds.length > 0 && (
                        <div className="p-3 bg-red-50 dark:bg-red-950/40 border-b border-red-200 dark:border-red-900 flex items-center justify-between">
                            <span className="text-xs font-bold text-red-700 dark:text-red-300">{selectedFacilityIds.length} item(s) selected</span>
                            <button onClick={handleBulkDelete} className="bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">🗑️ Delete Selected Items</button>
                        </div>
                    )}
                    {facilities?.data?.length > 0 ? (
                        <div className="overflow-x-auto custom-table-scrollbar">
                            <table className="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr className="border-b border-gray-200 bg-green-900 text-white uppercase tracking-wider dark:border-gray-700">
                                        <th className="px-4 py-3.5 w-10 text-center"><input type="checkbox" onChange={toggleSelectAll} checked={facilities.data.length > 0 && selectedFacilityIds.length === facilities.data.length} className="rounded border-gray-300 text-green-600 focus:ring-green-500" /></th>
                                        <th className="px-4 py-3.5 font-semibold">Protected Area</th>
                                        <th className="px-4 py-3.5 font-semibold">Facility / Structure</th>
                                        <th className="px-4 py-3.5 font-semibold">Unit</th>
                                        <th className="px-4 py-3.5 font-semibold">Year Established</th>
                                        <th className="px-4 py-3.5 font-semibold">Location (Brgy/Muni)</th>
                                        <th className="px-4 py-3.5 font-semibold">Zone</th>
                                        <th className="px-4 py-3.5 font-semibold">Easement</th>
                                        <th className="px-4 py-3.5 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
                                    {facilities.data.map((row) => (
                                        <tr key={row.id} onClick={() => openViewFacilityModal(row)} className="cursor-pointer transition hover:bg-green-50/60 dark:hover:bg-green-950/30">
                                            <td className="px-4 py-3 text-center" onClick={(e) => e.stopPropagation()}>
                                                <input type="checkbox" checked={selectedFacilityIds.includes(row.id)} onChange={() => toggleSelectFacility(row.id)} className="rounded border-gray-300 text-green-600 focus:ring-green-500" />
                                            </td>
                                            <td className="px-4 py-3 font-semibold text-gray-900 dark:text-white">{row.protected_area?.name || 'N/A'}</td>
                                            <td className="px-4 py-3 font-medium text-gray-800 dark:text-gray-200">{row.facility_type}</td>
                                            <td className="px-4 py-3">{row.unit_no}</td>
                                            <td className="px-4 py-3">{row.year_established || '—'}</td>
                                            <td className="px-4 py-3">{row.location_brgy_muni || '—'}</td>
                                            <td className="px-4 py-3 font-semibold">{row.management_zone}</td>
                                            <td className="px-4 py-3">{row.within_easement_zone}</td>
                                            <td className="px-4 py-3">
                                                <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${row.status === 'Functional' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>{row.status}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-12 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/50 text-green-700 dark:text-green-400 mb-3 text-xl">🏗️</div>
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-white">No facilities or infrastructures recorded yet</h3>
                            <p className="text-xs text-gray-500 mt-1">Click "+ Add Facility / Infrastructure" or "📥 Import CSV" to populate records.</p>
                        </div>
                    )}
                </Card>
            )}

            {/* VIEW ASSESSMENT FULL DETAILS MODAL */}
            {isViewAssessmentModalOpen && selectedAssessment && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs overflow-y-auto">
                    <div className="relative w-full max-w-4xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[90vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <div>
                                <h3 className="font-bold text-gray-900 dark:text-white text-base">IMEA Assessment Full Details</h3>
                                <p className="text-xs text-gray-500">{selectedAssessment.protected_area?.name || 'N/A'} — PAMO: {selectedAssessment.pamo_name}</p>
                            </div>
                            <button type="button" onClick={() => setIsViewAssessmentModalOpen(false)} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-6 flex-1 text-sm">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Assessment Year</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedAssessment.assessment_year} ({selectedAssessment.assessment_period})</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Status</span>
                                    <span className="inline-block mt-0.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300">{selectedAssessment.status || 'Pending'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Visitor Arrivals</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedAssessment.visitor_arrivals ?? '0'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Satisfaction Rate</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedAssessment.visitor_satisfaction_rate ? `${selectedAssessment.visitor_satisfaction_rate}%` : 'N/A'}</span>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">🔍 Impact Assessment Indicators</h4>
                                <div className="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 space-y-3">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                        <div><span className="text-gray-500 block">Trail Condition:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedAssessment.trail_condition || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Wildlife Disturbance:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedAssessment.wildlife_disturbance || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Vegetation Damage:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedAssessment.vegetation_damage || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Water Quality:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedAssessment.water_quality || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Solid Waste Generation:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedAssessment.solid_waste_generation_kg ? `${selectedAssessment.solid_waste_generation_kg} kg` : '—'}</span></div>
                                        <div><span className="text-gray-500 block">Carrying Capacity Compliance:</span><span className="font-semibold text-green-700 dark:text-green-400">{selectedAssessment.carrying_capacity_compliance ? 'Compliant' : 'Exceeded'}</span></div>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📝 Detailed Impact Notes & Remarks</h4>
                                <div className="space-y-3 text-xs">
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Biodiversity Impact Notes:</span><p className="text-gray-800 dark:text-gray-200">{selectedAssessment.biodiversity_impact_notes || 'None.'}</p></div>
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Environment Impact Notes:</span><p className="text-gray-800 dark:text-gray-200">{selectedAssessment.environment_impact_notes || 'None.'}</p></div>
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Social / Cultural Impact Notes:</span><p className="text-gray-800 dark:text-gray-200">{selectedAssessment.social_cultural_impact_notes || 'None.'}</p></div>
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Economic Impact & Livelihood:</span><p className="text-gray-800 dark:text-gray-200">{selectedAssessment.economic_impact_notes || 'None.'}</p></div>
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">General Remarks:</span><p className="text-gray-800 dark:text-gray-200">{selectedAssessment.general_remarks || 'None.'}</p></div>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between px-6 py-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800">
                            <button type="button" onClick={() => { setIsViewAssessmentModalOpen(false); openAssessmentEditModal(selectedAssessment); }} className="rounded-xl bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 hover:bg-green-100 border border-green-200 transition">✏️ Edit This Assessment</button>
                            <button type="button" onClick={() => setIsViewAssessmentModalOpen(false)} className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2 text-xs font-bold text-white shadow-md transition">Close Details</button>
                        </div>
                    </div>
                </div>
            )}

            {/* EDIT MODAL FOR IMEA ASSESSMENTS */}
            {isAssessmentModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs">
                    <div className="relative w-full max-w-7xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[92vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <div className="flex items-center gap-2">
                                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-400">📊</span>
                                <div>
                                    <h3 className="font-bold text-gray-900 dark:text-white text-sm sm:text-base">Edit IMEA Assessment & Document Preview</h3>
                                    <p className="text-xs text-gray-500">Update monitoring indicators and review attached documents side-by-side.</p>
                                </div>
                            </div>
                            <button type="button" onClick={closeAssessmentModal} className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 font-bold text-lg">✕</button>
                        </div>

                        <div className="flex-1 grid grid-cols-1 lg:grid-cols-12 gap-6 p-6 overflow-y-auto custom-table-scrollbar">
                            <div className="lg:col-span-6 space-y-5">
                                <form onSubmit={handleUpdate} id="edit-imea-form" className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Protected Area *</label>
                                        <select value={data.protected_area_id} onChange={(e) => setData('protected_area_id', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                            <option value="">Select Protected Area</option>
                                            {protectedAreas?.map((pa) => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                        </select>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">PAMO Office *</label>
                                            <input type="text" value={data.pamo_name} onChange={(e) => setData('pamo_name', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Assessment Year *</label>
                                            <input type="number" value={data.assessment_year} onChange={(e) => setData('assessment_year', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Verification Status *</label>
                                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm font-semibold shadow-xs">
                                                <option value="Pending">Pending (For Review)</option>
                                                <option value="Approved">Approved (Verified)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Assessment Period *</label>
                                            <select value={data.assessment_period} onChange={(e) => setData('assessment_period', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                                <option value="Annual">Annual</option>
                                                <option value="Semestral - 1st Semester">Semestral - 1st Semester</option>
                                                <option value="Semestral - 2nd Semester">Semestral - 2nd Semester</option>
                                                <option value="Q1">Q1</option>
                                                <option value="Q2">Q2</option>
                                                <option value="Q3">Q3</option>
                                                <option value="Q4">Q4</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Visitor Arrivals</label>
                                            <input type="number" value={data.visitor_arrivals} onChange={(e) => setData('visitor_arrivals', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Solid Waste (kg)</label>
                                            <input type="number" step="0.01" value={data.solid_waste_generation_kg} onChange={(e) => setData('solid_waste_generation_kg', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Satisfaction (%)</label>
                                            <input type="number" step="0.01" value={data.visitor_satisfaction_rate} onChange={(e) => setData('visitor_satisfaction_rate', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        </div>
                                    </div>

                                    {/* IMPACT ASSESSMENT INDICATORS */}
                                    <div className="pt-2 border-t border-gray-100 dark:border-gray-800 space-y-3">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">🔍 Impact Assessment Indicators</h4>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Trail Condition</label>
                                                <input type="text" value={data.trail_condition} onChange={(e) => setData('trail_condition', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Wildlife Disturbance</label>
                                                <input type="text" value={data.wildlife_disturbance} onChange={(e) => setData('wildlife_disturbance', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Vegetation Damage</label>
                                                <input type="text" value={data.vegetation_damage} onChange={(e) => setData('vegetation_damage', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Water Quality</label>
                                                <input type="text" value={data.water_quality} onChange={(e) => setData('water_quality', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                        </div>
                                    </div>

                                    {/* DETAILED IMPACT NOTES */}
                                    <div className="pt-2 border-t border-gray-100 dark:border-gray-800 space-y-3">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📝 Detailed Impact Notes</h4>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Biodiversity Impact Notes</label>
                                                <textarea rows="2" value={data.biodiversity_impact_notes} onChange={(e) => setData('biodiversity_impact_notes', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Environment Impact Notes</label>
                                                <textarea rows="2" value={data.environment_impact_notes} onChange={(e) => setData('environment_impact_notes', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Social / Cultural Impact Notes</label>
                                                <textarea rows="2" value={data.social_cultural_impact_notes} onChange={(e) => setData('social_cultural_impact_notes', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Economic Impact & Livelihood</label>
                                                <textarea rows="2" value={data.economic_impact_notes} onChange={(e) => setData('economic_impact_notes', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                            </div>
                                        </div>
                                    </div>

                                    {/* ATTACHMENTS & REMARKS SECTION */}
                                    <div className="pt-2 border-t border-gray-100 dark:border-gray-800 space-y-3">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📎 Attachments & Remarks</h4>
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Upload Additional Files (PDF)</label>
                                            <input
                                                type="file"
                                                multiple
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                onChange={(e) => {
                                                    const files = Array.from(e.target.files).map(file => ({ name: file.name, file }));
                                                    setAttachedFiles([...attachedFiles, ...files]);
                                                }}
                                                className="block w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 border border-gray-300 dark:border-gray-700 rounded-xl"
                                            />
                                        </div>

                                        {existingFiles.length > 0 && (
                                            <div>
                                                <span className="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Existing Files (Click to Preview):</span>
                                                <div className="flex flex-wrap gap-2">
                                                    {existingFiles.map((file, idx) => (
                                                        <div key={idx} className="flex items-center gap-2 bg-green-700 text-white px-3 py-1.5 rounded-xl text-xs font-medium shadow-xs">
                                                            <button type="button" onClick={() => setActivePreview(file)} className="truncate max-w-[180px] hover:underline text-left">
                                                                📄 {file.name}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setExistingFiles(existingFiles.filter((_, i) => i !== idx));
                                                                    setData('removed_attachments', [...data.removed_attachments, file.original]);
                                                                    if (activePreview?.url === file.url) setActivePreview(null);
                                                                }}
                                                                className="text-white/80 hover:text-white font-bold ml-1"
                                                            >
                                                                ✕
                                                            </button>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {attachedFiles.length > 0 && (
                                            <div>
                                                <span className="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Newly Added Files:</span>
                                                <div className="flex flex-wrap gap-2">
                                                    {attachedFiles.map((item, idx) => (
                                                        <div key={idx} className="flex items-center gap-2 bg-blue-600 text-white px-3 py-1.5 rounded-xl text-xs font-medium shadow-xs">
                                                            <span className="truncate max-w-[180px]">📄 {item.name}</span>
                                                            <button
                                                                type="button"
                                                                onClick={() => setAttachedFiles(attachedFiles.filter((_, i) => i !== idx))}
                                                                className="text-white/80 hover:text-white font-bold ml-1"
                                                            >
                                                                ✕
                                                            </button>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">General Remarks / Notes</label>
                                            <textarea rows="2" value={data.general_remarks} onChange={(e) => setData('general_remarks', e.target.value)} placeholder="Enter remarks or notes..." className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        </div>
                                    </div>
                                </form>
                            </div>

                            {/* LIVE DOCUMENT PREVIEW COLUMN */}
                            <div className="lg:col-span-6 flex flex-col bg-gray-50 dark:bg-gray-950 p-4 rounded-2xl border border-gray-200 dark:border-gray-800 h-[650px] sticky top-4">
                                <div className="flex items-center justify-between mb-3 pb-2 border-b border-gray-200 dark:border-gray-800">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">
                                        👁️ LIVE DOCUMENT PREVIEW {activePreview && <span className="normal-case text-gray-500 text-[11px] font-normal ml-1">({activePreview.name})</span>}
                                    </h4>
                                    {activePreview && (<a href={activePreview.url} target="_blank" rel="noopener noreferrer" className="text-xs font-semibold text-green-700 hover:underline">Fullscreen ↗</a>)}
                                </div>
                                <div className="flex-1 w-full bg-white dark:bg-gray-900 rounded-xl overflow-hidden border border-gray-300 dark:border-gray-800 flex items-center justify-center">
                                    {activePreview ? (<iframe src={activePreview.url} title={activePreview.name} className="w-full h-full border-0" />) : (<div className="text-center p-6 text-gray-400 text-xs">📁 No file selected or available for preview</div>)}
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between px-6 py-4 border-t border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <button type="button" onClick={() => setShowDeleteConfirm(true)} className="rounded-xl bg-red-50 px-4 py-2.5 text-xs font-semibold text-red-700 hover:bg-red-100 border border-red-200 transition">🗑️ Delete Record</button>
                            <div className="flex gap-2">
                                <button type="button" onClick={() => { closeAssessmentModal(); openViewAssessmentModal(selectedAssessment); }} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">← Back</button>
                                <button type="submit" form="edit-imea-form" disabled={processing} className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2.5 text-xs font-bold text-white shadow-md transition">💾 Save Changes</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL FOR ADDING/EDITING FACILITIES */}
            {isFacilityModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs overflow-y-auto">
                    <div className="relative w-full max-w-4xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[90vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <h3 className="font-bold text-gray-900 dark:text-white text-base">
                                {selectedFacility ? 'Edit Facility / Infrastructure Record' : 'Add Facility / Infrastructure Record'}
                            </h3>
                            <button type="button" onClick={closeFacilityModal} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>

                        <form onSubmit={handleFacilitySubmit} className="flex flex-col flex-1 overflow-hidden">
                            <div className="p-6 overflow-y-auto space-y-4 flex-1">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="sm:col-span-2">
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Protected Area *</label>
                                        <select value={facilityForm.data.protected_area_id} onChange={(e) => facilityForm.setData('protected_area_id', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                            <option value="">Select Protected Area</option>
                                            {protectedAreas?.map((pa) => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                        </select>
                                        {facilityForm.errors.protected_area_id && <div className="text-red-500 text-xs mt-1 font-bold">{facilityForm.errors.protected_area_id}</div>}
                                    </div>

                                    <div className="sm:col-span-2">
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Date Conducted / Inventory As-Of Period *</label>
                                        <input
                                            type="text"
                                            value={facilityForm.data.inventory_date}
                                            onChange={(e) => facilityForm.setData('inventory_date', e.target.value)}
                                            placeholder="e.g. July 2022, Q1 2026"
                                            className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs"
                                        />
                                        {facilityForm.errors.inventory_date && <div className="text-red-500 text-xs mt-1 font-bold">{facilityForm.errors.inventory_date}</div>}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Types of Facilities / Structure *</label>
                                        <input type="text" value={facilityForm.data.facility_type} onChange={(e) => facilityForm.setData('facility_type', e.target.value)} placeholder="e.g. Boardwalk, Birdwatch Tower" className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        {facilityForm.errors.facility_type && <div className="text-red-500 text-xs mt-1 font-bold">{facilityForm.errors.facility_type}</div>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Unit (no.) *</label>
                                        <input type="number" value={facilityForm.data.unit_no} onChange={(e) => facilityForm.setData('unit_no', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                        {facilityForm.errors.unit_no && <div className="text-red-500 text-xs mt-1 font-bold">{facilityForm.errors.unit_no}</div>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Year Established</label>
                                        <input type="number" value={facilityForm.data.year_established} onChange={(e) => facilityForm.setData('year_established', e.target.value)} placeholder="e.g. 2015" className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Area Location (Brgy/Municipality/Province)</label>
                                        <input type="text" value={facilityForm.data.location_brgy_muni} onChange={(e) => facilityForm.setData('location_brgy_muni', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Management Zone</label>
                                        <select value={facilityForm.data.management_zone} onChange={(e) => facilityForm.setData('management_zone', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                            <option value="MUZ">MUZ (Multiple Use Zone)</option>
                                            <option value="SPZ">SPZ (Strict Protection Zone)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Within Easement Zone?</label>
                                        <select value={facilityForm.data.within_easement_zone} onChange={(e) => facilityForm.setData('within_easement_zone', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                            <option value="No">No</option>
                                            <option value="Yes">Yes</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Coordinates</label>
                                        <input type="text" value={facilityForm.data.coordinates} onChange={(e) => facilityForm.setData('coordinates', e.target.value)} placeholder="6°44'9.54N, 126°8'31E" className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Source of Fund</label>
                                        <input type="text" value={facilityForm.data.source_of_fund} onChange={(e) => facilityForm.setData('source_of_fund', e.target.value)} placeholder="DENR, PLGU, LGU, etc." className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Description (Function / Objective)</label>
                                        <textarea rows="2" value={facilityForm.data.description} onChange={(e) => facilityForm.setData('description', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Status</label>
                                        <select value={facilityForm.data.status} onChange={(e) => facilityForm.setData('status', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs">
                                            <option value="Functional">Functional</option>
                                            <option value="Under Renovation">Under Renovation</option>
                                            <option value="Under Construction">Under Construction</option>
                                            <option value="Dilapidated">Dilapidated</option>
                                            <option value="Abandoned">Abandoned</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Tenurial Instrument / Permits</label>
                                        <input type="text" value={facilityForm.data.tenurial_instrument} onChange={(e) => facilityForm.setData('tenurial_instrument', e.target.value)} placeholder="SAPA, PACBRMA, ECC, PAMB permit" className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Appropriate Recommendations</label>
                                        <input type="text" value={facilityForm.data.recommendations} onChange={(e) => facilityForm.setData('recommendations', e.target.value)} placeholder="For demolition, for funding, need repairs" className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Remarks</label>
                                        <textarea rows="2" value={facilityForm.data.remarks} onChange={(e) => facilityForm.setData('remarks', e.target.value)} placeholder="Additional notes..." className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" />
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between px-6 py-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800">
                                <div className="flex items-center gap-2">
                                    {selectedFacility && (
                                        <button type="button" onClick={() => setShowFacilityDeleteConfirm(true)} className="rounded-xl bg-red-50 px-4 py-2 text-xs font-semibold text-red-700 hover:bg-red-100 border border-red-200 transition">Delete Record</button>
                                    )}
                                    <button type="button" onClick={() => { closeFacilityModal(); openViewFacilityModal(selectedFacility); }} className="rounded-xl border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">← Back</button>
                                </div>
                                <div className="flex gap-2">
                                    <button type="button" onClick={closeFacilityModal} className="rounded-xl border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                                    <button type="submit" disabled={facilityForm.processing} className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2 text-xs font-bold text-white shadow-md transition">
                                        {facilityForm.processing ? 'Saving...' : 'Save Facility'}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* VIEW FACILITY DETAILS MODAL */}
            {isViewFacilityModalOpen && selectedFacility && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs overflow-y-auto">
                    <div className="relative w-full max-w-4xl rounded-2xl bg-white dark:bg-gray-900 shadow-2xl max-h-[90vh] flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <div>
                                <h3 className="font-bold text-gray-900 dark:text-white text-base">Facility Full Details</h3>
                                <p className="text-xs text-gray-500">{selectedFacility.protected_area?.name || 'N/A'} — {selectedFacility.facility_type}</p>
                            </div>
                            <button type="button" onClick={() => setIsViewFacilityModalOpen(false)} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-6 flex-1 text-sm">
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Unit (no.)</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedFacility.unit_no}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Year Established</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedFacility.year_established || '—'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Status</span>
                                    <span className="inline-block mt-0.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300">{selectedFacility.status}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-semibold text-gray-500 uppercase">Typhoon Affected</span>
                                    <span className="text-base font-bold text-gray-900 dark:text-white">{selectedFacility.typhoon_affected || 'No'}</span>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📍 Location & Zoning Indicators</h4>
                                <div className="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 space-y-3">
                                    <div className="bg-green-50/70 dark:bg-green-950/40 p-3 rounded-lg border border-green-200 dark:border-green-800">
                                        <span className="block text-xs font-semibold text-green-800 dark:text-green-400">Inventory As-Of Date / Period:</span>
                                        <p className="text-sm font-bold text-gray-900 dark:text-white">{selectedFacility.inventory_date || '—'}</p>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                        <div><span className="text-gray-500 block">Location (Brgy/Muni):</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedFacility.location_brgy_muni || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Management Zone:</span><span className="font-semibold text-green-700 dark:text-green-400">{selectedFacility.management_zone}</span></div>
                                        <div><span className="text-gray-500 block">Within Easement Zone?:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedFacility.within_easement_zone}</span></div>
                                        <div><span className="text-gray-500 block">Coordinates:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedFacility.coordinates || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Source of Fund:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedFacility.source_of_fund || '—'}</span></div>
                                        <div><span className="text-gray-500 block">Tenurial Instrument / Permits:</span><span className="font-semibold text-gray-800 dark:text-gray-200">{selectedFacility.tenurial_instrument || 'None'}</span></div>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">📝 Description, Recommendations & Remarks</h4>
                                <div className="space-y-3 text-xs">
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Description (Function / Objective):</span><p className="text-gray-800 dark:text-gray-200">{selectedFacility.description || 'No description provided.'}</p></div>
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Appropriate Recommendations:</span><p className="text-gray-800 dark:text-gray-200">{selectedFacility.recommendations || 'No recommendations.'}</p></div>
                                    <div className="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700"><span className="block font-semibold text-gray-500 mb-1">Remarks:</span><p className="text-gray-800 dark:text-gray-200">{selectedFacility.remarks || 'No remarks.'}</p></div>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between px-6 py-4 bg-gray-50 dark:bg-gray-800/40 border-t border-gray-100 dark:border-gray-800">
                            <button type="button" onClick={() => { setIsViewFacilityModalOpen(false); openFacilityModal(selectedFacility); }} className="rounded-xl bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 hover:bg-green-100 border border-green-200 transition">✏️ Edit This Facility</button>
                            <button type="button" onClick={() => setIsViewFacilityModalOpen(false)} className="rounded-xl bg-green-700 hover:bg-green-800 px-5 py-2 text-xs font-bold text-white shadow-md transition">Close Details</button>
                        </div>
                    </div>
                </div>
            )}

            {/* IMPORT EXCEL/CSV MODAL */}
            {isImportModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                    <div className="relative w-full max-w-lg rounded-2xl bg-white dark:bg-gray-900 shadow-2xl flex flex-col overflow-hidden animate-pop-in border border-gray-200 dark:border-gray-800">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/40">
                            <h3 className="font-bold text-gray-900 dark:text-white text-base">📥 Import Facilities via CSV/Excel</h3>
                            <button type="button" onClick={() => { setIsImportModalOpen(false); importForm.reset(); }} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>
                        <form onSubmit={handleImportSubmit} className="flex flex-col p-6 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Select Target Protected Area *</label>
                                <select value={importForm.data.protected_area_id} onChange={(e) => importForm.setData('protected_area_id', e.target.value)} className="block w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm shadow-xs" required>
                                    <option value="">-- Select Protected Area to Assign --</option>
                                    {protectedAreas?.map((pa) => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Upload CSV / Excel File *</label>
                                <input type="file" accept=".csv, .xlsx, .xls" onChange={(e) => importForm.setData('file', e.target.files[0])} className="block w-full text-sm text-gray-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-gray-300 dark:border-gray-700 rounded-xl" required />
                            </div>
                            <div className="flex justify-end gap-2 pt-4 border-t border-gray-100 dark:border-gray-800">
                                <button type="button" onClick={() => { setIsImportModalOpen(false); importForm.reset(); }} className="rounded-xl border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                                <button type="submit" disabled={importForm.processing} className="rounded-xl bg-blue-600 hover:bg-blue-700 px-5 py-2 text-xs font-bold text-white shadow-md transition">{importForm.processing ? 'Importing...' : 'Upload & Import'}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* DELETE ALERTS */}
            {showDeleteConfirm && (<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"><div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 text-center animate-pop-in"><div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 mb-4 text-red-600 text-2xl">⚠️</div><h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Are you sure?</h3><p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Do you really want to delete this record?</p><div className="flex gap-3"><button type="button" onClick={() => setShowDeleteConfirm(false)} className="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-sm font-semibold">Cancel</button><button type="button" onClick={confirmDelete} className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold">Yes, Delete</button></div></div></div>)}
            {showFacilityDeleteConfirm && (<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"><div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 text-center animate-pop-in"><div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 mb-4 text-red-600 text-2xl">⚠️</div><h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Delete Facility?</h3><p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Do you really want to delete this facility record?</p><div className="flex gap-3"><button type="button" onClick={() => setShowFacilityDeleteConfirm(false)} className="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-sm font-semibold">Cancel</button><button type="button" onClick={confirmFacilityDelete} className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold">Yes, Delete</button></div></div></div>)}
            {showBulkDeleteConfirm && (<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"><div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 text-center animate-pop-in"><div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 mb-4 text-red-600 text-2xl">⚠️</div><h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Delete Selected?</h3><p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Do you really want to delete {selectedFacilityIds.length} selected facilities?</p><div className="flex gap-3"><button type="button" onClick={() => setShowBulkDeleteConfirm(false)} className="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-sm font-semibold">Cancel</button><button type="button" onClick={confirmBulkDelete} className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold">Yes, Delete</button></div></div></div>)}

            {/* SUCCESS MODAL */}
            {showSuccess && (<div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs"><div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 text-center animate-pop-in"><div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 mb-4 shadow-sm"><svg className="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor"><path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg></div><h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Success!</h3><p className="text-sm text-gray-600 dark:text-gray-300 mb-6">{successMessage}</p><button type="button" onClick={() => setShowSuccess(false)} className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition text-sm">Continue</button></div></div>)}
        </AuthenticatedLayout>
    );
}
