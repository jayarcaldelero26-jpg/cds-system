import { FileInput } from "@/Components/Crud/FileInput";import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '../../Components/Form';

export default function Create({ cenroList = [], statuses = [] }) {
  const { data, setData, post, processing, errors } = useForm({
    cenro: '',
    patrol_date: '',
    patrol_distance: '',
    patrol_hours: '',
    patrol_members_count: 1,
    threats_observed: '',
    remarks: '',
    status: 'Under Review',
    attachment: null
  });

  const submit = (e) => {
    e.preventDefault();
    post('/lawin-monitorings');
  };

  const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

  return (
    <AuthenticatedLayout title="Record Patrol Activity">
            <PageHeader
        title="Record LAWIN Patrol Activity"
        description="Input data from LAWIN smart patrol forms submitted by field rangers."
        actions={
        <Link href="/lawin-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        Back
                    </Link>
        } />


            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* CENRO / Station */}
                            <div className="md:col-span-2">
                                <FloatingSelect id="lawin-cenro" label="CENRO / Station" required value={data.cenro} onChange={(e) => setData('cenro', e.target.value)} error={errors.cenro}>
                                    <option value="">Select CENRO / Station</option>
                                    {cenroList.map((cenro) =>
                  <option key={cenro} value={cenro}>{cenro}</option>
                  )}
                                </FloatingSelect>
                            </div>

                            {/* Patrol Date */}
                            <div>
                                <FloatingInput id="lawin-patrol-date" label="Patrol Date" required type="date" value={data.patrol_date} onChange={(e) => setData('patrol_date', e.target.value)} error={errors.patrol_date} />
                            </div>

                            {/* Patrol Members Count */}
                            <div>
                                <FloatingInput id="lawin-members" label="No. of Patrol Members (Pax)" required type="number" min="1" value={data.patrol_members_count} onChange={(e) => setData('patrol_members_count', e.target.value)} error={errors.patrol_members_count} />
                            </div>

                            {/* Patrol Distance */}
                            <div>
                                <FloatingInput id="lawin-distance" label="Total Distance Covered (km)" required type="number" step="0.01" min="0" placeholder="E.g., 12.45" value={data.patrol_distance} onChange={(e) => setData('patrol_distance', e.target.value)} error={errors.patrol_distance} />
                            </div>

                            {/* Patrol Hours */}
                            <div>
                                <FloatingInput id="lawin-hours" label="Total Patrol Hours (hrs)" required type="number" step="0.1" min="0" placeholder="E.g., 5.5" value={data.patrol_hours} onChange={(e) => setData('patrol_hours', e.target.value)} error={errors.patrol_hours} />
                            </div>

                            {/* Record Status */}
                            <div>
                                <FloatingSelect id="lawin-status" label="Record Status" required value={data.status} onChange={(e) => setData('status', e.target.value)} error={errors.status}>
                                    {statuses.map((status) =>
                  <option key={status} value={status}>{status}</option>
                  )}
                                </FloatingSelect>
                            </div>

                            {/* PDF Attachment */}
                            <div>

                                <FileInput id="create-upload-patrol-report-max-20mb" type="file" accept=".pdf" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Threats Observed */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="lawin-threats" label="Threats Observed / Detected" rows="3" placeholder="E.g., 2 instances of illegal hunting, 1 active kaingin site..." value={data.threats_observed} onChange={(e) => setData('threats_observed', e.target.value)} error={errors.threats_observed} />
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="lawin-remarks" label="Remarks / Notes" rows="3" placeholder="Additional observations, weather conditions, or local sightings..." value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} error={errors.remarks} />
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href="/lawin-monitorings" className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition disabled:opacity-50">
                                {processing ? 'Saving...' : 'Save Patrol'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>);

}
