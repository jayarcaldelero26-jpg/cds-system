import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Create({ protectedAreas, impactRatings, statuses }) {
    const { data, setData, post, processing, errors } = useForm({
        protected_area_id: '',
        site_name: '',
        monitoring_date: '',
        visitors_count: 0,
        impact_rating: 'Low',
        issues_observed: '',
        recommendations: '',
        status: 'Under Review',
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/ecotourism-monitorings');
    };

    const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
    const inputClass = "mt-1 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:[color-scheme:dark]";
    const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

    return (
        <AuthenticatedLayout title="Add Ecotourism Monitoring Record">
            <PageHeader
                title="Add Ecotourism Monitoring"
                description="Input fresh impact monitoring data and tourism statistics from PAMOs."
                actions={
                    <Link href="/ecotourism-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        Back
                    </Link>
                }
            />

            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* Protected Area */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Protected Area / PAMO</label>
                                <select required className={inputClass} value={data.protected_area_id} onChange={(e) => setData('protected_area_id', e.target.value)}>
                                    <option value="">Select Protected Area</option>
                                    {protectedAreas.map((area) => (
                                        <option key={area.id} value={area.id}>{area.name}</option>
                                    ))}
                                </select>
                                {errors.protected_area_id && <p className={errorClass}>{errors.protected_area_id}</p>}
                            </div>

                            {/* Site Name */}
                            <div>
                                <label className={labelClass}>Ecotourism Site Name</label>
                                <input required type="text" placeholder="E.g., Mt. Hamiguitan Trail, Pujada Bay Sandbar" className={inputClass} value={data.site_name} onChange={(e) => setData('site_name', e.target.value)} />
                                {errors.site_name && <p className={errorClass}>{errors.site_name}</p>}
                            </div>

                            {/* Monitoring Date */}
                            <div>
                                <label className={labelClass}>Monitoring Conducted Date</label>
                                <input required type="date" className={inputClass} value={data.monitoring_date} onChange={(e) => setData('monitoring_date', e.target.value)} />
                                {errors.monitoring_date && <p className={errorClass}>{errors.monitoring_date}</p>}
                            </div>

                            {/* Visitors Count */}
                            <div>
                                <label className={labelClass}>Visitors Count</label>
                                <input required type="number" min="0" className={inputClass} value={data.visitors_count} onChange={(e) => setData('visitors_count', e.target.value)} />
                                {errors.visitors_count && <p className={errorClass}>{errors.visitors_count}</p>}
                            </div>

                            {/* Impact Rating */}
                            <div>
                                <label className={labelClass}>Ecotourism Impact Rating</label>
                                <select required className={inputClass} value={data.impact_rating} onChange={(e) => setData('impact_rating', e.target.value)}>
                                    {impactRatings.map((rating) => (
                                        <option key={rating} value={rating}>{rating} Impact</option>
                                    ))}
                                </select>
                                {errors.impact_rating && <p className={errorClass}>{errors.impact_rating}</p>}
                            </div>

                            {/* Status */}
                            <div>
                                <label className={labelClass}>Record Status</label>
                                <select required className={inputClass} value={data.status} onChange={(e) => setData('status', e.target.value)}>
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>{status}</option>
                                    ))}
                                </select>
                                {errors.status && <p className={errorClass}>{errors.status}</p>}
                            </div>

                            {/* PDF Attachment */}
                            <div>
                                <label className={labelClass}>Upload PDF Report (Max 20MB)</label>
                                <input type="file" accept=".pdf" className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 dark:file:bg-gray-800 dark:file:text-green-400" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Issues Observed */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Issues / Concerns Observed</label>
                                <textarea rows="3" placeholder="E.g., Garbage accumulation near water source, trail erosion, vandalism..." className={inputClass} value={data.issues_observed} onChange={(e) => setData('issues_observed', e.target.value)} />
                                {errors.issues_observed && <p className={errorClass}>{errors.issues_observed}</p>}
                            </div>

                            {/* Recommendations */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Recommendations / Action Steps</label>
                                <textarea rows="3" placeholder="E.g., Limit daily visitors, install garbage bins, put signage..." className={inputClass} value={data.recommendations} onChange={(e) => setData('recommendations', e.target.value)} />
                                {errors.recommendations && <p className={errorClass}>{errors.recommendations}</p>}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/ecotourism-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition disabled:opacity-50">
                                {processing ? 'Saving...' : 'Save Record'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
