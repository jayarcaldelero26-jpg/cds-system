import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Create({ protectedAreas, categories, statuses }) {
    const { data, setData, post, processing, errors } = useForm({
        protected_area_id: '',
        title: '',
        category: 'Activity',
        description: '',
        budget: 0,
        source_of_fund: '',
        start_date: '',
        end_date: '',
        status: 'Ongoing',
        remarks: '',
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/program-project-activities');
    };

    const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
    const inputClass = "mt-1 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:[color-scheme:dark]";
    const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

    return (
        <AuthenticatedLayout title="Record PPA">
            <PageHeader
                title="Record New PPA"
                description="Register new programs, development projects, or operations under PAMOs."
                actions={
                    <Link href="/program-project-activities" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                        Back
                    </Link>
                }
            />

            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* Title */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>PPA Title / Name</label>
                                <input required type="text" placeholder="E.g., Coastal Resource Rehabilitation, Forest Watch Training..." className={inputClass} value={data.title} onChange={(e) => setData('title', e.target.value)} />
                                {errors.title && <p className={errorClass}>{errors.title}</p>}
                            </div>

                            {/* Protected Area */}
                            <div>
                                <label className={labelClass}>Protected Area / PAMO</label>
                                <select required className={inputClass} value={data.protected_area_id} onChange={(e) => setData('protected_area_id', e.target.value)}>
                                    <option value="">Select Protected Area</option>
                                    {protectedAreas.map((area) => (
                                        <option key={area.id} value={area.id}>{area.name}</option>
                                    ))}
                                </select>
                                {errors.protected_area_id && <p className={errorClass}>{errors.protected_area_id}</p>}
                            </div>

                            {/* Category */}
                            <div>
                                <label className={labelClass}>Category</label>
                                <select required className={inputClass} value={data.category} onChange={(e) => setData('category', e.target.value)}>
                                    {categories.map((cat) => (
                                        <option key={cat} value={cat}>{cat}</option>
                                    ))}
                                </select>
                                {errors.category && <p className={errorClass}>{errors.category}</p>}
                            </div>

                            {/* Budget */}
                            <div>
                                <label className={labelClass}>Allocated Budget (PHP)</label>
                                <input required type="number" min="0" step="0.01" className={inputClass} value={data.budget} onChange={(e) => setData('budget', e.target.value)} />
                                {errors.budget && <p className={errorClass}>{errors.budget}</p>}
                            </div>

                            {/* Source of Fund */}
                            <div>
                                <label className={labelClass}>Source of Fund</label>
                                <input type="text" placeholder="E.g., GOP, IPAF, NGO Grant" className={inputClass} value={data.source_of_fund} onChange={(e) => setData('source_of_fund', e.target.value)} />
                                {errors.source_of_fund && <p className={errorClass}>{errors.source_of_fund}</p>}
                            </div>

                            {/* Start Date */}
                            <div>
                                <label className={labelClass}>Start Date</label>
                                <input type="date" className={inputClass} value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                                {errors.start_date && <p className={errorClass}>{errors.start_date}</p>}
                            </div>

                            {/* End Date */}
                            <div>
                                <label className={labelClass}>End Date</label>
                                <input type="date" className={inputClass} value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} />
                                {errors.end_date && <p className={errorClass}>{errors.end_date}</p>}
                            </div>

                            {/* Status */}
                            <div>
                                <label className={labelClass}>Status</label>
                                <select required className={inputClass} value={data.status} onChange={(e) => setData('status', e.target.value)}>
                                    {statuses.map((stat) => (
                                        <option key={stat} value={stat}>{stat}</option>
                                    ))}
                                </select>
                                {errors.status && <p className={errorClass}>{errors.status}</p>}
                            </div>

                            {/* PDF Attachment */}
                            <div>
                                <label className={labelClass}>Upload Project Document (Max 20MB PDF)</label>
                                <input type="file" accept=".pdf" className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 dark:file:bg-gray-800 dark:file:text-green-400" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Description */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>PPA Description / Objectives</label>
                                <textarea rows="3" placeholder="Brief outline of project scope, physical targets, or objectives..." className={inputClass} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                                {errors.description && <p className={errorClass}>{errors.description}</p>}
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Remarks / Project Update Notes</label>
                                <textarea rows="3" placeholder="Latest implementations, procurement milestones, or bottlenecks..." className={inputClass} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                                {errors.remarks && <p className={errorClass}>{errors.remarks}</p>}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/program-project-activities" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition">
                                {processing ? 'Saving...' : 'Save PPA'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
