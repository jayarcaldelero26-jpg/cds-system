import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Edit({ technicalReport, protectedAreas, reportTypes, statuses, quarters }) {
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PATCH', // Importante kini para sa file upload update sa Laravel
        protected_area_id: technicalReport.protected_area_id || '',
        report_type: technicalReport.report_type || '',
        reporting_year: technicalReport.reporting_year || new Date().getFullYear(),
        quarter: technicalReport.quarter || 'Annual',
        submission_date: technicalReport.submission_date || '',
        status: technicalReport.status || 'Pending',
        attachment: null,
        remarks: technicalReport.remarks || '',
    });

    const submit = (e) => {
        e.preventDefault();
        // Naggamit kita og POST diri uban ang _method: 'PATCH' aron mosugot si Laravel modawat ug file upload
        post(`/technical-reports/${technicalReport.id}`);
    };

    const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
    const inputClass = "mt-1 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white";
    const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

    return (
        <AuthenticatedLayout title="Edit Technical Report">
            <PageHeader
                title="Edit Technical Report"
                description="Modify and update the submission details of this report."
                actions={
                    <Link href="/technical-reports" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        Back to registry
                    </Link>
                }
            />

            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* Protected Area Dropdown */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Protected Area / PAMO</label>
                                <select
                                    required
                                    className={inputClass}
                                    value={data.protected_area_id}
                                    onChange={(e) => setData('protected_area_id', e.target.value)}
                                >
                                    <option value="">Select Protected Area</option>
                                    {protectedAreas.map((area) => (
                                        <option key={area.id} value={area.id}>{area.name}</option>
                                    ))}
                                </select>
                                {errors.protected_area_id && <p className={errorClass}>{errors.protected_area_id}</p>}
                            </div>

                            {/* Report Type */}
                            <div>
                                <label className={labelClass}>Report Type</label>
                                <select
                                    required
                                    className={inputClass}
                                    value={data.report_type}
                                    onChange={(e) => setData('report_type', e.target.value)}
                                >
                                    <option value="">Select Report Type</option>
                                    {reportTypes.map((type) => (
                                        <option key={type} value={type}>{type}</option>
                                    ))}
                                </select>
                                {errors.report_type && <p className={errorClass}>{errors.report_type}</p>}
                            </div>

                            {/* Status */}
                            <div>
                                <label className={labelClass}>Status</label>
                                <select
                                    required
                                    className={inputClass}
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                >
                                    {statuses.map((item) => (
                                        <option key={item} value={item}>{item}</option>
                                    ))}
                                </select>
                                {errors.status && <p className={errorClass}>{errors.status}</p>}
                            </div>

                            {/* Reporting Year */}
                            <div>
                                <label className={labelClass}>Reporting Year</label>
                                <input
                                    required
                                    type="number"
                                    min="2000"
                                    max={new Date().getFullYear() + 5}
                                    className={inputClass}
                                    value={data.reporting_year}
                                    onChange={(e) => setData('reporting_year', e.target.value)}
                                />
                                {errors.reporting_year && <p className={errorClass}>{errors.reporting_year}</p>}
                            </div>

                            {/* Reporting Quarter */}
                            <div>
                                <label className={labelClass}>Reporting Period / Quarter</label>
                                <select
                                    className={inputClass}
                                    value={data.quarter}
                                    onChange={(e) => setData('quarter', e.target.value)}
                                >
                                    {quarters.map((q) => (
                                        <option key={q} value={q}>{q}</option>
                                    ))}
                                </select>
                                {errors.quarter && <p className={errorClass}>{errors.quarter}</p>}
                            </div>

                            {/* Submission Date */}
                            <div>
                                <label className={labelClass}>Date Submitted (Optional)</label>
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={data.submission_date}
                                    onChange={(e) => setData('submission_date', e.target.value)}
                                />
                                {errors.submission_date && <p className={errorClass}>{errors.submission_date}</p>}
                            </div>

                            {/* Attachment Upload */}
                            <div>
                                <label className={labelClass}>Replace Attachment (Optional - PDF Max 20MB)</label>
                                <input
                                    type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx"
                                    className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 dark:file:bg-gray-800 dark:file:text-green-400"
                                    onChange={(e) => setData('attachment', e.target.files[0])}
                                />
                                {technicalReport.attachment && (
                                    <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        Current file: <span className="font-mono text-green-700 dark:text-green-400">{technicalReport.attachment.split('/').pop()}</span>
                                    </p>
                                )}
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Remarks / Notes</label>
                                <textarea
                                    rows="3"
                                    className={inputClass}
                                    value={data.remarks}
                                    onChange={(e) => setData('remarks', e.target.value)}
                                />
                                {errors.remarks && <p className={errorClass}>{errors.remarks}</p>}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/technical-reports" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition disabled:opacity-50"
                            >
                                {processing ? 'Updating...' : 'Update report'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
