import { FileInput } from "@/Components/Crud/FileInput";import { FloatingInput, FloatingSelect, FloatingTextarea } from "@/Components/Form";import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Edit({ ppa, protectedAreas, categories, statuses }) {
  const { data, setData, post, processing, errors } = useForm({
    _method: 'PATCH',
    protected_area_id: ppa.protected_area_id || '',
    title: ppa.title || '',
    category: ppa.category || 'Activity',
    description: ppa.description || '',
    budget: ppa.budget || 0,
    source_of_fund: ppa.source_of_fund || '',
    start_date: ppa.start_date || '',
    end_date: ppa.end_date || '',
    status: ppa.status || 'Ongoing',
    remarks: ppa.remarks || '',
    attachment: null
  });

  const submit = (e) => {
    e.preventDefault();
    post(`/program-project-activities/${ppa.id}`);
  };

  const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
  const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

  return (
    <AuthenticatedLayout title="Edit PPA">
            <PageHeader
        title="Edit PPA Details"
        description="Update progress status, latest budget allocations, or milestones for this project."
        actions={
        <Link href="/program-project-activities" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                        Back
                    </Link>
        } />


            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* Title */}
                            <div className="md:col-span-2">

                                <FloatingInput variant="legacy" id="edit-ppa-title-name" label="PPA Title / Name" required type="text" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                                {errors.title && <p className={errorClass}>{errors.title}</p>}
                            </div>

                            {/* Protected Area */}
                            <div>

                                <FloatingSelect variant="legacy" id="edit-protected-area-pamo" label="Protected Area / PAMO" required value={data.protected_area_id} onChange={(e) => setData('protected_area_id', e.target.value)}>
                                    <option value="">Select Protected Area</option>
                                    {protectedAreas.map((area) =>
                  <option key={area.id} value={area.id}>{area.name}</option>
                  )}
                                </FloatingSelect>
                                {errors.protected_area_id && <p className={errorClass}>{errors.protected_area_id}</p>}
                            </div>

                            {/* Category */}
                            <div>

                                <FloatingSelect variant="legacy" id="edit-category" label="Category" required value={data.category} onChange={(e) => setData('category', e.target.value)}>
                                    {categories.map((cat) =>
                  <option key={cat} value={cat}>{cat}</option>
                  )}
                                </FloatingSelect>
                                {errors.category && <p className={errorClass}>{errors.category}</p>}
                            </div>

                            {/* Budget */}
                            <div>

                                <FloatingInput variant="legacy" id="edit-allocated-budget-php" label="Allocated Budget (PHP)" required type="number" min="0" step="0.01" value={data.budget} onChange={(e) => setData('budget', e.target.value)} />
                                {errors.budget && <p className={errorClass}>{errors.budget}</p>}
                            </div>

                            {/* Source of Fund */}
                            <div>

                                <FloatingInput variant="legacy" id="edit-source-of-fund" label="Source of Fund" type="text" value={data.source_of_fund} onChange={(e) => setData('source_of_fund', e.target.value)} />
                                {errors.source_of_fund && <p className={errorClass}>{errors.source_of_fund}</p>}
                            </div>

                            {/* Start Date */}
                            <div>

                                <FloatingInput variant="legacy" id="edit-start-date" label="Start Date" type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                                {errors.start_date && <p className={errorClass}>{errors.start_date}</p>}
                            </div>

                            {/* End Date */}
                            <div>

                                <FloatingInput variant="legacy" id="edit-end-date" label="End Date" type="date" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                                {errors.end_date && <p className={errorClass}>{errors.end_date}</p>}
                            </div>

                            {/* Status */}
                            <div>

                                <FloatingSelect variant="legacy" id="edit-status" label="Status" required value={data.status} onChange={(e) => setData('status', e.target.value)}>
                                    {statuses.map((stat) =>
                  <option key={stat} value={stat}>{stat}</option>
                  )}
                                </FloatingSelect>
                                {errors.status && <p className={errorClass}>{errors.status}</p>}
                            </div>

                            {/* Replacement File Upload */}
                            <div>

                                <FileInput variant="legacy" id="edit-replace-attachment-optional-pdf-max-20mb" type="file" accept=".pdf" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {ppa.attachment &&
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        Current document: <span className="font-mono text-green-700 dark:text-green-400">{ppa.attachment.split('/').pop()}</span>
                                    </p>
                }
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Description */}
                            <div className="md:col-span-2">

                                <FloatingTextarea variant="legacy" id="edit-ppa-description-objectives" label="PPA Description / Objectives" rows="3" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                                {errors.description && <p className={errorClass}>{errors.description}</p>}
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">

                                <FloatingTextarea variant="legacy" id="edit-remarks-project-update-notes" label="Remarks / Project Update Notes" rows="3" value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                                {errors.remarks && <p className={errorClass}>{errors.remarks}</p>}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/program-project-activities" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition">
                                {processing ? 'Updating...' : 'Update PPA'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>);

}
