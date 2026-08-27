import { FileInput } from "@/Components/Crud/FileInput";import { FloatingSelect, FloatingInput, FloatingTextarea } from "@/Components/Form";import { useState } from 'react';
import { useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Card';
import Tooltip from '@/Components/Tooltip';

export default function ImeaCreate({ protectedAreas }) {
  const [attachedFiles, setAttachedFiles] = useState([]);
  const [activePreviewIndex, setActivePreviewIndex] = useState(0);

  const { data, setData, post, processing, errors } = useForm({
    protected_area_id: '',
    pamo_name: '',
    assessment_year: new Date().getFullYear(),
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
    attachments: []
  });

  const handleFileChange = (e) => {
    const files = Array.from(e.target.files);
    const newFilesWithPreviews = files.map((file) => ({
      file,
      name: file.name,
      url: URL.createObjectURL(file),
      type: file.type
    }));
    const updatedFiles = [...attachedFiles, ...newFilesWithPreviews];
    setAttachedFiles(updatedFiles);
    setData('attachments', updatedFiles.map((item) => item.file));
    setActivePreviewIndex(updatedFiles.length - 1);
  };

  const handleRemoveFile = (indexToRemove) => {
    const updatedFiles = attachedFiles.filter((_, index) => index !== indexToRemove);
    setAttachedFiles(updatedFiles);
    setData('attachments', updatedFiles.map((item) => item.file));
    if (activePreviewIndex >= updatedFiles.length) {
      setActivePreviewIndex(Math.max(0, updatedFiles.length - 1));
    }
  };

  const submit = (e) => {
    e.preventDefault();
    post('/imea', { preserveScroll: true });
  };

  return (
    <AuthenticatedLayout title="Add IMEA Assessment">
            <style>{`
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
            `}</style>

            {/* Management Plan Style Gradient Header Banner */}
            <div className="sticky top-20 z-10 relative overflow-hidden rounded-xl bg-gradient-to-r from-green-600 via-green-700 to-green-800 p-6 text-white shadow-md mb-6">
                <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/15 blur-2xl pointer-events-none"></div>

                <div className="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-white">Add IMEA Assessment</h1>
                        <p className="text-xs sm:text-sm text-green-100 mt-1">Record a new integrated protected area ecotourism impact assessment.</p>
                    </div>
                    <Link
            href="/imea"
            className="inline-flex items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-sm transition whitespace-nowrap backdrop-blur-xs">

                        ← Back to List
                    </Link>
                </div>
            </div>

            {/* SPLIT LAYOUT: FORM SA LEFT (SPAN 7), LIVE PREVIEW SA RIGHT (SPAN 5) */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                {/* LEFT SIDE: FORM */}
                <div className="lg:col-span-7">
                    <Card padding="p-6 sm:p-8" className="border border-gray-100 dark:border-gray-800 shadow-xl rounded-2xl bg-white dark:bg-gray-900">
                        <form onSubmit={submit} className="space-y-8">

                            {/* SECTION 1: GENERAL INFORMATION */}
                            <div className="bg-gray-50/70 dark:bg-gray-800/40 p-5 rounded-2xl border border-gray-100 dark:border-gray-800">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-4 flex items-center gap-2">
                                    <span>📌</span> General Information & Status
                                </h4>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>

                                        <FloatingSelect id="create-protected-area" label="Protected Area"
                    value={data.protected_area_id}
                    onChange={(e) => setData('protected_area_id', e.target.value)}

                    required>

                                            <option value="">Select Protected Area</option>
                                            {protectedAreas.map((pa) =>
                      <option key={pa.id} value={pa.id}>{pa.name}</option>
                      )}
                                        </FloatingSelect>
                                        {errors.protected_area_id && <div className="text-red-500 text-xs mt-1">{errors.protected_area_id}</div>}
                                    </div>

                                    <div>

                                        <FloatingInput id="create-pamo-office" label="PAMO Office"
                    type="text"
                    value={data.pamo_name}
                    onChange={(e) => setData('pamo_name', e.target.value)}
                    placeholder="e.g. PAMO Mount Hamiguitan"

                    required />

                                        {errors.pamo_name && <div className="text-red-500 text-xs mt-1">{errors.pamo_name}</div>}
                                    </div>

                                    <div>

                                        <FloatingSelect id="create-verification-status" label="Verification Status"
                    value={data.status}
                    onChange={(e) => setData('status', e.target.value)}

                    required>

                                            <option value="Pending">Pending (For Review)</option>
                                            <option value="Approved">Approved (Verified)</option>
                                        </FloatingSelect>
                                        {errors.status && <div className="text-red-500 text-xs mt-1">{errors.status}</div>}
                                    </div>

                                    <div>

                                        <FloatingInput id="create-assessment-year" label="Assessment Year"
                    type="number"
                    value={data.assessment_year}
                    onChange={(e) => setData('assessment_year', e.target.value)}

                    required />

                                        {errors.assessment_year && <div className="text-red-500 text-xs mt-1">{errors.assessment_year}</div>}
                                    </div>

                                    <div className="sm:col-span-2">

                                        <FloatingSelect id="create-assessment-period" label="Assessment Period"
                    value={data.assessment_period}
                    onChange={(e) => setData('assessment_period', e.target.value)}>


                                            <option value="Annual">Annual</option>
                                            <option value="Semestral - 1st Semester">Semestral - 1st Semester</option>
                                            <option value="Semestral - 2nd Semester">Semestral - 2nd Semester</option>
                                            <option value="Q1">Q1</option>
                                            <option value="Q2">Q2</option>
                                            <option value="Q3">Q3</option>
                                            <option value="Q4">Q4</option>
                                        </FloatingSelect>
                                    </div>
                                </div>
                            </div>

                            {/* SECTION 2: CORE QUANTITATIVE INDICATORS */}
                            <div>
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-3 flex items-center gap-2">
                                    <span>📊</span> Core Quantitative Indicators
                                </h4>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                                    <div>

                                        <FloatingInput id="create-visitor-arrivals" label="Visitor Arrivals"
                    type="number"
                    value={data.visitor_arrivals}
                    onChange={(e) => setData('visitor_arrivals', e.target.value)}
                    placeholder="Total visitors" />


                                    </div>
                                    <div>

                                        <FloatingInput id="create-solid-waste-kg" label="Solid Waste (kg)"
                    type="number"
                    step="0.01"
                    value={data.solid_waste_generation_kg}
                    onChange={(e) => setData('solid_waste_generation_kg', e.target.value)}
                    placeholder="e.g. 150.50" />


                                    </div>
                                    <div>

                                        <FloatingInput id="create-satisfaction" label="Satisfaction (%)"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    value={data.visitor_satisfaction_rate}
                    onChange={(e) => setData('visitor_satisfaction_rate', e.target.value)}
                    placeholder="e.g. 95.5" />


                                        {errors.visitor_satisfaction_rate && <div className="text-red-500 text-xs mt-1">{errors.visitor_satisfaction_rate}</div>}
                                    </div>
                                </div>
                            </div>

                            {/* SECTION 3: IMPACT ASSESSMENT INDICATORS */}
                            <div className="border-t border-gray-100 dark:border-gray-800 pt-6">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-3 flex items-center gap-2">
                                    <span>🔍</span> Impact Assessment Indicators
                                </h4>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>

                                        <FloatingInput id="create-trail-condition" label="Trail Condition"
                    type="text"
                    value={data.trail_condition}
                    onChange={(e) => setData('trail_condition', e.target.value)}
                    placeholder="e.g. Stable, Minor Erosion" />


                                    </div>
                                    <div>

                                        <FloatingInput id="create-wildlife-disturbance" label="Wildlife Disturbance"
                    type="text"
                    value={data.wildlife_disturbance}
                    onChange={(e) => setData('wildlife_disturbance', e.target.value)}
                    placeholder="e.g. Low, Moderate" />


                                    </div>
                                    <div>

                                        <FloatingInput id="create-vegetation-damage" label="Vegetation Damage"
                    type="text"
                    value={data.vegetation_damage}
                    onChange={(e) => setData('vegetation_damage', e.target.value)}
                    placeholder="e.g. Minimal, None" />


                                    </div>
                                    <div>

                                        <FloatingInput id="create-water-quality" label="Water Quality"
                    type="text"
                    value={data.water_quality}
                    onChange={(e) => setData('water_quality', e.target.value)}
                    placeholder="e.g. Clear, Good" />


                                    </div>
                                </div>
                            </div>

                            {/* SECTION 4: DETAILED IMPACT NOTES */}
                            <div className="border-t border-gray-100 dark:border-gray-800 pt-6">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-3 flex items-center gap-2">
                                    <span>📝</span> Detailed Impact Notes
                                </h4>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>

                                        <FloatingTextarea id="create-biodiversity-notes" label="Biodiversity Notes"
                    rows="2.5"
                    value={data.biodiversity_impact_notes}
                    onChange={(e) => setData('biodiversity_impact_notes', e.target.value)}
                    placeholder="Enter observation notes..." />


                                    </div>
                                    <div>

                                        <FloatingTextarea id="create-environment-notes" label="Environment Notes"
                    rows="2.5"
                    value={data.environment_impact_notes}
                    onChange={(e) => setData('environment_impact_notes', e.target.value)}
                    placeholder="Enter environment notes..." />


                                    </div>
                                    <div>

                                        <FloatingTextarea id="create-social-cultural-notes" label="Social/Cultural Notes"
                    rows="2.5"
                    value={data.social_cultural_impact_notes}
                    onChange={(e) => setData('social_cultural_impact_notes', e.target.value)}
                    placeholder="Enter social notes..." />


                                    </div>
                                    <div>

                                        <FloatingTextarea id="create-economic-impact-livelihood" label="Economic Impact & Livelihood"
                    rows="2.5"
                    value={data.economic_impact_notes}
                    onChange={(e) => setData('economic_impact_notes', e.target.value)}
                    placeholder="Enter economic notes..." />


                                    </div>
                                </div>
                            </div>

                            {/* CARRYING CAPACITY COMPLIANCE CHECKBOX */}
                            <div className="border-t border-gray-100 dark:border-gray-800 pt-4">
                                <label className="flex items-center gap-2.5 cursor-pointer py-1">
                                    <input
                    type="checkbox"
                    checked={Boolean(data.carrying_capacity_compliance)}
                    onChange={(e) => setData('carrying_capacity_compliance', e.target.checked)}
                    className="rounded border-gray-300 text-green-700 shadow-xs h-4 w-4 focus:ring-green-600" />

                                    <span className="text-sm font-semibold text-gray-700 dark:text-gray-300">Compliant with Carrying Capacity Regulations</span>
                                </label>
                            </div>

                            {/* SECTION 5: SUPPORTING DOCUMENTS UPLOAD */}
                            <div className="border-t border-gray-100 dark:border-gray-800 pt-6">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 mb-3 flex items-center gap-2">
                                    <span>📎</span> Attach Supporting Documents (Multiple)
                                </h4>
                                <div className="space-y-3">
                                    <FileInput id="create-335-field"
                  type="file"
                  multiple
                  onChange={handleFileChange} />


                                    {errors.attachments && <div className="text-red-500 text-xs mt-1">{errors.attachments}</div>}

                                    {/* Listahan sa mga files nga pwede pilion para i-preview sa right side */}
                                    {attachedFiles.length > 0 &&
                  <div className="flex flex-wrap gap-2 pt-1">
                                            {attachedFiles.map((item, index) =>
                    <div
                      key={index}
                      onClick={() => setActivePreviewIndex(index)}
                      className={`flex items-center gap-2 border px-3.5 py-2 rounded-xl text-xs font-semibold cursor-pointer transition shadow-xs ${
                      activePreviewIndex === index ?
                      'bg-green-700 text-white border-green-700 shadow-md' :
                      'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-100'}`
                      }>

                                                    <Tooltip content={item.name} className="max-w-[180px]"><span className="truncate max-w-[180px]">📄 {item.name}</span></Tooltip>
                                                    <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleRemoveFile(index);
                        }}
                        className={`font-bold ml-1 h-5 w-5 flex items-center justify-center rounded-full transition ${
                        activePreviewIndex === index ?
                        'text-white hover:bg-green-800' :
                        'text-red-500 hover:bg-red-50 dark:hover:bg-red-950/50'}`
                        }
                        aria-label="Tangtangon ang file">

                                                        <Tooltip content="Tangtangon ang file">✕</Tooltip>
                                                    </button>
                                                </div>
                    )}
                                        </div>
                  }
                                </div>
                            </div>

                            {/* FORM ACTION BUTTONS */}
                            <div className="flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                                <Link
                  href="/imea"
                  className="rounded-xl border border-gray-300 px-5 py-2.5 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition shadow-xs">

                                    Cancel
                                </Link>
                                <button
                  type="submit"
                  disabled={processing}
                  className="rounded-xl bg-green-700 hover:bg-green-800 px-6 py-2.5 text-xs font-bold text-white shadow-md transition">

                                    💾 Save Assessment Record
                                </button>
                            </div>
                        </form>
                    </Card>
                </div>

                {/* RIGHT SIDE: STICKY LIVE DOCUMENT PREVIEW (SPAN 5) */}
                <div className="lg:col-span-5 sticky top-28">
                    <Card padding="p-5" className="border border-gray-200 dark:border-gray-800 shadow-xl rounded-2xl bg-white dark:bg-gray-900">
                        <div className="flex items-center justify-between mb-3 pb-3 border-b border-gray-100 dark:border-gray-800">
                            <h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400 flex items-center gap-2">
                                <span>👁️</span> Live Document Preview
                            </h3>
                            {attachedFiles[activePreviewIndex] &&
              <a
                href={attachedFiles[activePreviewIndex].url}
                target="_blank"
                rel="noopener noreferrer"
                className="text-xs font-semibold text-green-700 dark:text-green-400 hover:underline">

                                    Fullscreen ↗
                                </a>
              }
                        </div>

                        {attachedFiles.length > 0 && attachedFiles[activePreviewIndex] ?
            <div className="space-y-3">
                                <Tooltip content={attachedFiles[activePreviewIndex].name} className="block max-w-full"><p className="text-xs font-medium text-gray-600 dark:text-gray-300 truncate">
                                    📄 <strong className="text-gray-900 dark:text-white">{attachedFiles[activePreviewIndex].name}</strong>
                                </p></Tooltip>
                                <div className="w-full h-[620px] bg-gray-100 dark:bg-gray-950 rounded-xl overflow-hidden border border-gray-300 dark:border-gray-800 shadow-inner">
                                    <iframe
                  src={attachedFiles[activePreviewIndex].url}
                  title={attachedFiles[activePreviewIndex].name}
                  className="w-full h-full" />

                                </div>
                            </div> :

            <div className="flex flex-col items-center justify-center h-[620px] text-center p-8 bg-gray-50 dark:bg-gray-950/50 rounded-xl border border-dashed border-gray-200 dark:border-gray-800">
                                <span className="text-4xl mb-3">📁</span>
                                <h4 className="text-sm font-semibold text-gray-800 dark:text-white">No file selected for preview</h4>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xs">
                                    Upload supporting documents or reports on the left form to view them here live.
                                </p>
                            </div>
            }
                    </Card>
                </div>

            </div>

        </AuthenticatedLayout>);

}
