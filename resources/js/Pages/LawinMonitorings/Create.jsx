import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

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
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/lawin-monitorings');
    };

    const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
    const inputClass = "mt-1 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:[color-scheme:dark]";
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
                }
            />

            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* CENRO / Station */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>CENRO / Station</label>
                                <select required className={inputClass} value={data.cenro} onChange={(e) => setData('cenro', e.target.value)}>
                                    <option value="">Select CENRO / Station</option>
                                    {cenroList.map((cenro) => (
                                        <option key={cenro} value={cenro}>{cenro}</option>
                                    ))}
                                </select>
                                {errors.cenro && <p className={errorClass}>{errors.cenro}</p>}
                            </div>

                            {/* Patrol Date */}
                            <div>
                                <label className={labelClass}>Patrol Date</label>
                                <input required type="date" className={inputClass} value={data.patrol_date} onChange={(e) => setData('patrol_date', e.target.value)} />
                                {errors.patrol_date && <p className={errorClass}>{errors.patrol_date}</p>}
                            </div>

                            {/* Patrol Members Count */}
                            <div>
                                <label className={labelClass}>No. of Patrol Members (Pax)</label>
                                <input required type="number" min="1" className={inputClass} value={data.patrol_members_count} onChange={(e) => setData('patrol_members_count', e.target.value)} />
                                {errors.patrol_members_count && <p className={errorClass}>{errors.patrol_members_count}</p>}
                            </div>

                            {/* Patrol Distance */}
                            <div>
                                <label className={labelClass}>Total Distance Covered (km)</label>
                                <input required type="number" step="0.01" min="0" placeholder="E.g., 12.45" className={inputClass} value={data.patrol_distance} onChange={(e) => setData('patrol_distance', e.target.value)} />
                                {errors.patrol_distance && <p className={errorClass}>{errors.patrol_distance}</p>}
                            </div>

                            {/* Patrol Hours */}
                            <div>
                                <label className={labelClass}>Total Patrol Hours (hrs)</label>
                                <input required type="number" step="0.1" min="0" placeholder="E.g., 5.5" className={inputClass} value={data.patrol_hours} onChange={(e) => setData('patrol_hours', e.target.value)} />
                                {errors.patrol_hours && <p className={errorClass}>{errors.patrol_hours}</p>}
                            </div>

                            {/* Record Status */}
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
                                <label className={labelClass}>Upload Patrol Report (Max 20MB)</label>
                                <input type="file" accept=".pdf" className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 dark:file:bg-gray-800 dark:file:text-green-400" onChange={(e) => setData('attachment', e.target.files[0])} />
                                {errors.attachment && <p className={errorClass}>{errors.attachment}</p>}
                            </div>

                            {/* Threats Observed */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Threats Observed / Detected</label>
                                <textarea rows="3" placeholder="E.g., 2 instances of illegal hunting, 1 active kaingin site..." className={inputClass} value={data.threats_observed} onChange={(e) => setData('threats_observed', e.target.value)} />
                                {errors.threats_observed && <p className={errorClass}>{errors.threats_observed}</p>}
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Remarks / Notes</label>
                                <textarea rows="3" placeholder="Additional observations, weather conditions, or local sightings..." className={inputClass} value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                                {errors.remarks && <p className={errorClass}>{errors.remarks}</p>}
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
        </AuthenticatedLayout>
    );
}
