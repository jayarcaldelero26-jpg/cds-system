import { FileInput } from "@/Components/Crud/FileInput";import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '../../Components/Form';

export default function Edit({ issue, protectedAreas, statuses }) {
  const { data, setData, post, processing, errors } = useForm({
    _method: 'PATCH', // Importante kini para modawat og file uploads sa server inig update
    protected_area_id: issue.protected_area_id || '',
    issue_description: issue.issue_description || '',
    findings: issue.findings || '',
    date_observed: issue.date_observed || '',
    recommendations: issue.recommendations || '',
    action_taken: issue.action_taken || '',
    status: issue.status || 'Pending',
    attachment: null
  });

  const submit = (e) => {
    e.preventDefault();
    post(`/issue-monitorings/${issue.id}`);
  };

  const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

  return (
    <AuthenticatedLayout title="Edit Issue Monitoring Record">
            <PageHeader
        title="Edit Issue Monitoring Record"
        description="Update progress, actions taken, or recommendations for this logged issue."
        actions={
        <Link href="/issue-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        Back
                    </Link>
        } />


            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* Protected Area */}
                            <div className="md:col-span-2">
                                <FloatingSelect id="issue-pa" label="Protected Area / PAMO" required value={data.protected_area_id} onChange={(e) => setData('protected_area_id', e.target.value)} error={errors.protected_area_id}>
                                    <option value="">Select Protected Area</option>
                                    {protectedAreas.map((area) =>
                  <option key={area.id} value={area.id}>{area.name}</option>
                  )}
                                </FloatingSelect>
                            </div>

                            {/* Date Observed */}
                            <div>
                                <FloatingInput id="issue-date" label="Date Observed / Reported" required type="date" value={data.date_observed} onChange={(e) => setData('date_observed', e.target.value)} error={errors.date_observed} />
                            </div>

                            {/* Status */}
                            <div>
                                <FloatingSelect id="issue-status" label="Issue Status" required value={data.status} onChange={(e) => setData('status', e.target.value)} error={errors.status}>
                                    {statuses.map((status) =>
                  <option key={status} value={status}>{status}</option>
                  )}
                                </FloatingSelect>
                            </div>

                            {/* PDF Attachment Replacement */}
                            <div className="md:col-span-2">

                                <FileInput id="edit-replace-attachment-optional-pdf-max-20mb" type="file" accept=".pdf" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {issue.attachment &&
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        Current file: <span className="font-mono text-green-700 dark:text-green-400">{issue.attachment.split('/').pop()}</span>
                                    </p>
                }
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Issue Description */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="issue-description" label="Issue Description" required rows="3" value={data.issue_description} onChange={(e) => setData('issue_description', e.target.value)} error={errors.issue_description} />
                            </div>

                            {/* Findings */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="issue-findings" label="Findings / Details" required rows="3" value={data.findings} onChange={(e) => setData('findings', e.target.value)} error={errors.findings} />
                            </div>

                            {/* Recommendations */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="issue-recommendations" label="Recommendations" rows="3" value={data.recommendations} onChange={(e) => setData('recommendations', e.target.value)} error={errors.recommendations} />
                            </div>

                            {/* Action Taken */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="issue-action" label="Action Taken" rows="3" value={data.action_taken} onChange={(e) => setData('action_taken', e.target.value)} error={errors.action_taken} />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/issue-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition disabled:opacity-50">
                                {processing ? 'Updating...' : 'Update Issue'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>);

}
