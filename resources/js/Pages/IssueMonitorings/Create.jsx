import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Create({ protectedAreas, statuses }) {
    const { data, setData, post, processing, errors } = useForm({
        protected_area_id: '',
        issue_description: '',
        findings: '',
        date_observed: '',
        recommendations: '',
        action_taken: '',
        status: 'Pending',
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/issue-monitorings');
    };

    const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
    // Gi-apil na ang dark:[color-scheme:dark] para masulbad ang text visibility sa dark mode
    const inputClass = "mt-1 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:[color-scheme:dark]";
    const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

    return (
        <AuthenticatedLayout title="Record New Issue">
            <PageHeader
                title="Record New Issue"
                description="Log active issues, findings, and ongoing actions in Protected Areas."
                actions={
                    <Link href="/issue-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
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

                            {/* Date Observed */}
                            <div>
                                <label className={labelClass}>Date Observed / Reported</label>
                                <input required type="date" className={inputClass} value={data.date_observed} onChange={(e) => setData('date_observed', e.target.value)} />
                                {errors.date_observed && <p className={errorClass}>{errors.date_observed}</p>}
                            </div>

                            {/* Status */}
                            <div>
                                <label className={labelClass}>Issue Status</label>
                                <select required className={inputClass} value={data.status} onChange={(e) => setData('status', e.target.value)}>
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>{status}</option>
                                    ))}
                                </select>
                                {errors.status && <p className={errorClass}>{errors.status}</p>}
                            </div>

                            {/* PDF Attachment */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Upload PDF Report / Evidence (Max 20MB)</label>
                                <input type="file" accept=".pdf" className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 dark:file:bg-gray-800 dark:file:text-green-400" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Issue Description */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Issue Description</label>
                                <textarea required rows="3" placeholder="E.g., Illegal logging activities detected near Buffer Zone 3..." className={inputClass} value={data.issue_description} onChange={(e) => setData('issue_description', e.target.value)} />
                                {errors.issue_description && <p className={errorClass}>{errors.issue_description}</p>}
                            </div>

                            {/* Findings */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Findings / Details</label>
                                <textarea required rows="3" placeholder="E.g., Cutting of 5 Dipterocarp trees, chainsaws heard, tire tracks found..." className={inputClass} value={data.findings} onChange={(e) => setData('findings', e.target.value)} />
                                {errors.findings && <p className={errorClass}>{errors.findings}</p>}
                            </div>

                            {/* Recommendations */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Recommendations</label>
                                <textarea rows="3" placeholder="E.g., Deploy more forest rangers, install surveillance cameras..." className={inputClass} value={data.recommendations} onChange={(e) => setData('recommendations', e.target.value)} />
                                {errors.recommendations && <p className={errorClass}>{errors.recommendations}</p>}
                            </div>

                            {/* Action Taken */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Action Taken (If any)</label>
                                <textarea rows="3" placeholder="E.g., Coordinate with local PNP, filed case report..." className={inputClass} value={data.action_taken} onChange={(e) => setData('action_taken', e.target.value)} />
                                {errors.action_taken && <p className={errorClass}>{errors.action_taken}</p>}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/issue-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition disabled:opacity-50">
                                {processing ? 'Saving...' : 'Save Issue'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
